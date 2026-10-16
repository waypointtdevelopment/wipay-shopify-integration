<?php
/**
 * Mock Shopify Admin for the reconciliation e2e suite.
 *
 * Impersonates what the app needs from Shopify on a development store:
 *   POST /seed                              — create an order the app records
 *   POST /admin/api/2026-10/graphql.json    — orderMarkAsPaid (records mutations)
 *   POST /send-shopify-webhook              — delivers a correctly signed webhook
 *   POST /seed-wapi                         — fake WAPI transaction status
 *   GET  /wapi/transactions/{txn}           — WAPI retrieve
 *   GET  /state                             — everything that happened
 *
 * GraphQL shapes follow shopify.dev (Admin API 2026-10); the merchant's real
 * store replaces this harness with no code change in the app.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$STATE = $ROOT . '/data/mock_admin_state.json';

function state_load(): array
{
    global $STATE;
    if (!file_exists($STATE)) {
        return ['orders' => [], 'mutations' => [], 'wapi' => []];
    }
    $d = json_decode((string)file_get_contents($STATE), true);
    return is_array($d) ? $d + ['orders' => [], 'mutations' => [], 'wapi' => []] : ['orders' => [], 'mutations' => [], 'wapi' => []];
}

function state_save(array $s): void
{
    global $STATE;
    @mkdir(dirname($STATE), 0775, true);
    file_put_contents($STATE, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX);
}

function out(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/seed' && $method === 'POST') {
    $req = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($req) || !isset($req['id'])) {
        out(400, ['error' => 'bad_seed']);
    }
    $s = state_load();
    $s['orders'][$req['id']] = [
        'id'          => $req['id'],
        'name'        => (string)($req['name'] ?? '#' . (count($s['orders']) + 1001)),
        'total'       => (string)($req['total'] ?? '45.00'),
        'currency'    => (string)($req['currency'] ?? 'TTD'),
        'email'       => (string)($req['email'] ?? 'buyer@example.com'),
        'financial'   => (string)($req['financialStatus'] ?? 'PENDING'),
    ];
    state_save($s);
    out(200, ['seeded' => $req['id']]);
}

if (preg_match('#^/admin/api/[\d-]+/graphql\.json$#', $path) && $method === 'POST') {
    $req = json_decode((string)file_get_contents('php://input'), true);
    $query = (string)($req['query'] ?? '');
    $gid = (string)($req['variables']['id'] ?? '');
    $s = state_load();
    if (str_contains($query, 'orderMarkAsPaid')) {
        if (!isset($s['orders'][$gid])) {
            out(200, ['data' => ['orderMarkAsPaid' => [
                'order' => null,
                'userErrors' => [['field' => 'input.id', 'message' => 'Order not found']],
            ]]]);
        }
        if ($s['orders'][$gid]['financial'] === 'PAID') {
            // Documented behaviour for an already-paid order.
            out(200, ['data' => ['orderMarkAsPaid' => [
                'order' => ['id' => $gid, 'displayFinancialStatus' => 'PAID'],
                'userErrors' => [['field' => 'input.id', 'message' => 'Order cannot be marked as paid.']],
            ]]]);
        }
        $s['orders'][$gid]['financial'] = 'PAID';
        $s['mutations'][] = ['mutation' => 'orderMarkAsPaid', 'id' => $gid, 'at' => time()];
        state_save($s);
        out(200, ['data' => ['orderMarkAsPaid' => [
            'order' => ['id' => $gid, 'displayFinancialStatus' => 'PAID'],
            'userErrors' => [],
        ]]]);
    }
    out(200, ['data' => new stdClass()]);
}

if ($path === '/send-shopify-webhook' && $method === 'POST') {
    $req = json_decode((string)file_get_contents('php://input'), true);
    $topic = (string)($req['topic'] ?? '');
    $payload = is_array($req['body'] ?? null) ? json_encode($req['body']) : (string)($req['body'] ?? '');
    $secret = (string)(getenv('SHOPIFY_CLIENT_SECRET') ?: 'e2e-shopify-secret');
    $hmac = base64_encode(hash_hmac('sha256', $payload, $secret, true));
    $ch = curl_init((getenv('APP_BASE_URL') ?: 'http://127.0.0.1:8095') . '/shopify/webhook');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Shopify-Topic: ' . $topic,
            'X-Shopify-Hmac-Sha256: ' . $hmac,
            'X-Shopify-Webhook-Id: ' . (string)($req['webhook_id'] ?? 'wh-' . uniqid('', true)),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    out(200, ['app_http_code' => $code, 'app_response' => json_decode((string)$body, true)]);
}

if ($path === '/seed-wapi' && $method === 'POST') {
    $req = json_decode((string)file_get_contents('php://input'), true);
    $s = state_load();
    $s['wapi'][(string)($req['txn'] ?? '')] = [
        'status' => (string)($req['status'] ?? 'pending'),
        'total'  => (string)($req['total'] ?? '0.00'),
    ];
    state_save($s);
    out(200, ['seeded' => true]);
}

if (preg_match('#^/wapi/transactions/([^/]+)$#', $path, $m) && $method === 'GET') {
    $s = state_load();
    $txn = rawurldecode($m[1]);
    if (!isset($s['wapi'][$txn])) {
        out(404, ['error' => 'not_found']);
    }
    out(200, ['data' => ['transaction_id' => $txn] + $s['wapi'][$txn]]);
}

if ($path === '/state' && $method === 'GET') {
    out(200, state_load());
}

out(404, ['error' => 'not_found']);
