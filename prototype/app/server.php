<?php
/**
 * Waypoint WiPay — Shopify Offsite Payments App prototype.
 *
 * Implements the documented Shopify offsite payment-extension contract
 * (shopify.dev Payments Apps API 2026-01) backed by the live-verified
 * WiPay Payments API contract. Zero dependencies; run with:
 *
 *   APP_BASE_URL=http://127.0.0.1:8081 php -S 127.0.0.1:8081 app/server.php
 *
 * Production deltas (mTLS, real GraphQL endpoint, extension uid, compliance
 * webhook registration) are listed in README.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/WaypointWiPayCore.php';

const SHOPIFY_API_VERSION = '2026-01';

$config = [
    'base_url'         => getenv('APP_BASE_URL') ?: 'http://127.0.0.1:8081',
    'country'          => getenv('WIPAY_COUNTRY') ?: 'TT',
    'environment'      => getenv('WIPAY_ENVIRONMENT') ?: 'sandbox',
    'account'          => getenv('WIPAY_ACCOUNT') ?: WaypointWiPayCore::SANDBOX_ACCOUNT_NUMBER,
    'api_key'          => getenv('WIPAY_API_KEY') ?: WaypointWiPayCore::SANDBOX_API_KEY,
    'fee_structure'    => getenv('WIPAY_FEE_STRUCTURE') ?: 'merchant_absorb',
    'webhook_secret'   => getenv('WIPAY_WEBHOOK_SECRET') ?: '',
    'wapi_key'         => getenv('WIPAY_WAPI_KEY') ?: '',
    'shopify_api_url'  => getenv('SHOPIFY_API_URL') ?: '',   // {shop}/payments_apps/api/2026-01/graphql.json
    'shopify_token'    => getenv('SHOPIFY_ACCESS_TOKEN') ?: '',
    'dev_secret'       => getenv('DEV_SHARED_SECRET') ?: '', // dev-only substitute for mTLS
    'reconcile_token'  => getenv('RECONCILE_TOKEN') ?: 'reconcile',
    'stale_hours'      => (int)(getenv('STALE_HOURS') ?: 24),
    'debug'            => getenv('APP_DEBUG') === '1',
];

// --------------------------------------------------------------------------
// Session store (JSON file; production would use Redis/DB)
// --------------------------------------------------------------------------

function store_path(): string
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir . '/sessions.json';
}

/** @return array<string, array<string, mixed>> */
function store_load(): array
{
    $path = store_path();
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function store_save(array $sessions): void
{
    file_put_contents(store_path(), json_encode($sessions, JSON_PRETTY_PRINT), LOCK_EX);
}

function store_get(string $id): ?array
{
    $sessions = store_load();
    return $sessions[$id] ?? null;
}

function store_put(string $id, array $session): void
{
    $sessions = store_load();
    $sessions[$id] = $session;
    store_save($sessions);
}

// --------------------------------------------------------------------------
// Helpers
// --------------------------------------------------------------------------

function json_out(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

function debug_log(string $msg, array $ctx = []): void
{
    if (getenv('APP_DEBUG') === '1') {
        // php://stderr works in both CLI and the built-in server SAPI
        // (STDERR constant is undefined under php -S).
        file_put_contents('php://stderr', '[wipay-shopify] ' . $msg . ' ' . json_encode($ctx) . "\n", FILE_APPEND);
    }
}

function read_raw_body(): string
{
    return (string)file_get_contents('php://input');
}

/**
 * Dev-only authentication substitute for Shopify's mTLS. Production must
 * validate Shopify's client certificate against Shopify's Payments CA
 * instead of this shared-secret header.
 */
function dev_auth_ok(array $config): bool
{
    if ($config['dev_secret'] === '') {
        return true;
    }
    $given = $_SERVER['HTTP_X_DEV_SHARED_SECRET'] ?? '';
    return is_string($given) && hash_equals($config['dev_secret'], $given);
}

/** @return array{0:int,1:string} [http_code, body] */
function http_post_json(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? $body : ''];
}

/** @return array{0:int,1:string} */
function http_get(string $url, array $headers = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? $body : ''];
}

/**
 * Calls a Payments Apps GraphQL mutation. Endpoint:
 * https://{shop}/payments_apps/api/{version}/graphql.json with
 * X-Shopify-Access-Token. Returns [ok, decoded].
 */
function shopify_graphql(array $config, string $query, array $variables): array
{
    if ($config['shopify_api_url'] === '' || $config['shopify_token'] === '') {
        debug_log('shopify_graphql_not_configured');
        return [false, null];
    }
    [$code, $body] = http_post_json(
        $config['shopify_api_url'],
        ['query' => $query, 'variables' => $variables],
        ['X-Shopify-Access-Token: ' . $config['shopify_token']]
    );
    $decoded = json_decode($body, true);
    return [$code === 200 && is_array($decoded), $decoded];
}

function shopify_resolve(array $config, string $gid): bool
{
    [$ok, $resp] = shopify_graphql($config, <<<'GQL'
    mutation Resolve($id: ID!) {
      paymentSessionResolve(id: $id) {
        paymentSession { id state { ... on PaymentSessionStateResolved { code } } }
        userErrors { field message }
      }
    }
    GQL, ['id' => $gid]);
    $errors = $resp['data']['paymentSessionResolve']['userErrors'] ?? null;
    if (!$ok || (is_array($errors) && $errors)) {
        debug_log('resolve_failed', ['resp' => $resp]);
        return false;
    }
    return true;
}

function shopify_reject(array $config, string $gid, string $code, string $message): bool
{
    [$ok, $resp] = shopify_graphql($config, <<<'GQL'
    mutation Reject($id: ID!, $reason: PaymentSessionStateReasonInput!) {
      paymentSessionReject(id: $id, reason: $reason) {
        paymentSession { id }
        userErrors { field message }
      }
    }
    GQL, ['id' => $gid, 'reason' => ['code' => $code, 'merchantMessage' => $message, 'source' => 'MERCHANT']]);
    $errors = $resp['data']['paymentSessionReject']['userErrors'] ?? null;
    if (!$ok || (is_array($errors) && $errors)) {
        debug_log('reject_failed', ['resp' => $resp]);
        return false;
    }
    return true;
}

/**
 * The WiPay Payment Request, built exactly like the live-verified
 * WordPress/OpenCart modules: server-side pinned amount, order_id derived
 * from the Shopify payment-attempt id (alphanumeric, <=16 chars).
 */
function create_wipay_checkout(array $config, array $session_req): array
{
    $attempt_id = (string)($session_req['id'] ?? '');
    $amount = $session_req['amount'] ?? '';
    $currency = strtoupper((string)($session_req['currency'] ?? ''));
    $customer = is_array($session_req['customer'] ?? null) ? $session_req['customer'] : [];

    if ($attempt_id === '' || !preg_match('/^\d+(\.\d+)?$/', (string)$amount) || (float)$amount <= 0
        || !preg_match('/^[A-Z]{3}$/', $currency)) {
        return ['error' => 'invalid_session_request'];
    }
    if (($session_req['kind'] ?? '') !== 'sale') {
        // Hosted-page capture-on-payment v1: authorization flows unsupported.
        return ['error' => 'authorization_not_supported'];
    }

    $order_id = 'shp' . substr(hash('sha256', $attempt_id), 0, 13); // 16 chars, alnum
    $email = (string)($customer['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = 'checkout-' . substr(hash('sha256', $attempt_id), 0, 8) . '@shopify-checkout.invalid';
    }

    $billing = is_array($customer['billing_address'] ?? null) ? $customer['billing_address'] : [];
    $phone = (string)($customer['phone_number'] ?? $billing['phone_number'] ?? '');
    $phone = preg_replace('/[^+0-9]/', '', $phone) ?? '';

    $payload = WaypointWiPayCore::buildPaymentRequest([
        'total'          => number_format((float)$amount, 2, '.', ''),
        'currency'       => $currency,
        'email'          => $email,
        'order_id'       => $order_id,
        'country'        => $config['country'],
        'environment'    => $config['environment'],
        'fee_structure'  => $config['fee_structure'],
        'account_number' => $config['account'],
        'return_url'     => $config['base_url'] . '/return?id=' . rawurlencode($attempt_id),
        'prefill'        => [
            'fname'   => (string)($billing['given_name'] ?? ''),
            'lname'   => (string)($billing['family_name'] ?? ''),
            'addr1'   => (string)($billing['line1'] ?? ''),
            'addr2'   => (string)($billing['line2'] ?? ''),
            'city'    => (string)($billing['city'] ?? ''),
            'state'   => (string)($billing['province'] ?? ''),
            'zipcode' => (string)($billing['postal_code'] ?? ''),
            'country' => (string)($billing['country_code'] ?? ''),
            'phone'   => $phone,
        ],
    ]);

    $endpoint = WaypointWiPayCore::paymentEndpoint($config['country'], $config['environment']);
    if ($endpoint === null) {
        return ['error' => 'wiay_config'];
    }

    [$code, $body] = http_post_json($endpoint, $payload);
    $resp = json_decode($body, true);
    $url = is_array($resp) && isset($resp['url']) ? trim((string)$resp['url']) : '';
    $txn = is_array($resp) && isset($resp['transaction_id']) ? trim((string)$resp['transaction_id']) : '';

    if ($code !== 200 || !WaypointWiPayCore::isValidCheckoutUrl($url) || !WaypointWiPayCore::isValidTransactionId($txn)) {
        debug_log('wipay_request_failed', ['code' => $code, 'body' => substr($body, 0, 300)]);
        return ['error' => 'wipay_unavailable'];
    }

    return [
        'id'            => $attempt_id,
        'gid'           => (string)($session_req['gid'] ?? ''),
        'shop'          => (string)($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? ''),
        'wipay_order'   => $order_id,
        'txn'           => $txn,
        'pinned_total'  => number_format((float)$amount, 2, '.', ''),
        'currency'      => $currency,
        'test'          => (bool)($session_req['test'] ?? false),
        'environment'   => $config['environment'],
        'state'         => 'open',
        'redirect_url'  => $url,
        'cancel_url'    => (string)($session_req['payment_method']['data']['cancel_url'] ?? ''),
        'created_at'    => time(),
        'updated_at'    => time(),
        'notes'         => [],
    ];
}

// --------------------------------------------------------------------------
// Routes
// --------------------------------------------------------------------------

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- POST /payment-session (Shopify -> app) -------------------------------
if ($path === '/payment-session' && $method === 'POST') {
    if (!dev_auth_ok($config)) {
        json_out(403, ['error' => 'forbidden']);
    }
    $req = json_decode(read_raw_body(), true);
    if (!is_array($req)) {
        json_out(400, ['error' => 'invalid_json']);
    }

    // Idempotency: same payment-attempt id returns the original redirect.
    $existing = store_get((string)($req['id'] ?? ''));
    if ($existing && ($existing['state'] ?? '') === 'open') {
        json_out(200, ['redirect_url' => $existing['redirect_url']]);
    }

    $session = create_wipay_checkout($config, $req);
    if (isset($session['error'])) {
        json_out(422, ['error' => $session['error']]);
    }
    store_put($session['id'], $session);
    json_out(200, ['redirect_url' => $session['redirect_url']]);
}

// --- GET /return (WiPay hosted page -> buyer -> app) ----------------------
if ($path === '/return' && $method === 'GET') {
    $id = (string)($_GET['id'] ?? '');
    $txn = (string)($_GET['transaction_id'] ?? '');
    $status = (string)($_GET['status'] ?? '');
    $hash = strtolower((string)($_GET['hash'] ?? ''));
    $message = (string)($_GET['message'] ?? $_GET['reasonDescription'] ?? '');

    $session = store_get($id);
    if (!$session || $txn === '' || !hash_equals((string)$session['txn'], $txn)) {
        debug_log('return_rejected', ['id' => $id, 'txn' => $txn]);
        json_out(400, ['error' => 'unknown_session']);
    }
    if (($session['state'] ?? '') !== 'open') {
        // Already resolved/rejected: idempotent success for the browser.
        header('Location: ' . ($session['cancel_url'] ?: 'about:blank'));
        exit;
    }

    if ($status === 'success') {
        $expected = WaypointWiPayCore::responseHash($txn, $session['pinned_total'], $config['api_key']);
        if (!preg_match('/^[a-f0-9]{32}$/', $hash) || !hash_equals($expected, $hash)) {
            $session['notes'][] = 'hash_mismatch_at_return';
            $session['state'] = 'rejected';
            $session['updated_at'] = time();
            store_put($id, $session);
            shopify_reject($config, $session['gid'], 'PROCESSING_ERROR', 'WiPay response failed hash verification.');
            debug_log('return_hash_rejected', ['id' => $id]);
            header('Location: ' . ($session['cancel_url'] ?: 'about:blank'));
            exit;
        }
        // Success is hash-verified; the authoritative signal to Shopify is
        // the Payments Apps API call — never the browser redirect itself.
        if (shopify_resolve($config, $session['gid'])) {
            $session['state'] = 'resolved';
        } else {
            $session['notes'][] = 'resolve_api_failed_at_return';
        }
        $session['updated_at'] = time();
        store_put($id, $session);
    } elseif (in_array($status, ['failed', 'cancelled', 'error'], true)) {
        $session['state'] = 'rejected';
        $session['notes'][] = 'buyer_return_' . $status . ($message !== '' ? ': ' . $message : '');
        $session['updated_at'] = time();
        store_put($id, $session);
        shopify_reject($config, $session['gid'], 'PROCESSING_ERROR', 'WiPay: ' . ($message ?: $status));
    }

    header('Location: ' . ($session['cancel_url'] ?: 'about:blank'));
    exit;
}

// --- POST /webhook (WiPay server-to-server) -------------------------------
if ($path === '/webhook' && $method === 'POST') {
    $raw = read_raw_body();
    $signature = (string)($_SERVER['HTTP_X_WIPAY_WEBHOOK_SIGNATURE'] ?? '');
    $timestamp = (string)($_SERVER['HTTP_X_WIPAY_WEBHOOK_TIMESTAMP'] ?? '');
    $event_id = (string)($_SERVER['HTTP_X_WIPAY_WEBHOOK_ID'] ?? '');

    if (!WaypointWiPayCore::verifyWebhookSignature($raw, $timestamp, $signature, $config['webhook_secret'])) {
        json_out(400, ['result' => 'Failed']);
    }
    $event = json_decode($raw, true);
    if (!is_array($event)) {
        json_out(400, ['result' => 'Failed']);
    }

    $sessions = store_load();
    if ($event_id !== '') {
        foreach ($sessions as $s) {
            if (in_array($event_id, $s['notes'] ?? [], true)) {
                json_out(200, ['result' => 'Duplicate']);
            }
        }
    }

    $type = (string)($event['event'] ?? $event['event_type'] ?? '');
    $txn = (string)($event['data']['transaction_id'] ?? '');
    foreach ($sessions as $id => $s) {
        if ($txn !== '' && ($s['txn'] ?? '') !== $txn) {
            continue;
        }
        if ($txn === '') {
            continue;
        }
        if ($event_id !== '') {
            $sessions[$id]['notes'][] = $event_id;
        }
        if ($type === 'payment.success') {
            if (($s['state'] ?? '') === 'open' && shopify_resolve($config, $s['gid'])) {
                $sessions[$id]['state'] = 'resolved';
            }
        } elseif (in_array($type, ['payment.failed', 'payment.error', 'payment.cancelled'], true)) {
            if (($s['state'] ?? '') === 'open' && shopify_reject($config, $s['gid'], 'PROCESSING_ERROR', 'WiPay webhook: ' . $type)) {
                $sessions[$id]['state'] = 'rejected';
            }
        } else {
            $sessions[$id]['notes'][] = 'lifecycle:' . $type;
        }
        $sessions[$id]['updated_at'] = time();
        store_save($sessions);
        json_out(200, ['result' => 'Processed']);
    }
    json_out(200, ['result' => 'Unmatched']);
}

// --- POST /refund-session (Shopify -> app; acknowledge 201 empty) ---------
if ($path === '/refund-session' && $method === 'POST') {
    if (!dev_auth_ok($config)) {
        json_out(403, ['error' => 'forbidden']);
    }
    $req = json_decode(read_raw_body(), true);
    $refund_id = is_array($req) ? (string)($req['id'] ?? '') : '';
    $sessions = store_load();
    if ($refund_id !== '' && isset($sessions[$refund_id]) && $config['wapi_key'] !== '') {
        $env = WaypointWiPayCore::wapiEnvironment($config['wapi_key']);
        $endpoint = WaypointWiPayCore::wapiEndpoint(
            $config['country'],
            $env === 'sandbox' ? 'sandbox' : 'live',
            '/transactions/' . rawurlencode((string)$sessions[$refund_id]['txn']) . '/refund'
        );
        if ($endpoint !== null) {
            // X-WAPI-Key only; never combined with Authorization (WiPay rejects both).
            [$code] = http_post_json($endpoint, [], ['X-WAPI-Key: ' . $config['wapi_key']]);
            debug_log('refund_request', ['code' => $code]);
            $sessions[$refund_id]['notes'][] = 'refund_requested:' . $code;
            store_save($sessions);
        }
    }
    http_response_code(201);
    exit;
}

// --- POST /compliance/* (Shopify privacy compliance webhooks) -------------
if (preg_match('#^/compliance/(customers_data_request|customers_redact|shop_redact)$#', $path, $m) && $method === 'POST') {
    debug_log('compliance_webhook', ['topic' => $m[1]]);
    json_out(200, ['ok' => true]);
}

// --- GET /reconcile (token-guarded cron) ----------------------------------
if ($path === '/reconcile' && ($method === 'GET' || $method === 'POST')) {
    $token = (string)($_GET['token'] ?? '');
    if (!hash_equals($config['reconcile_token'], $token)) {
        json_out(403, ['error' => 'forbidden']);
    }
    $cutoff = time() - $config['stale_hours'] * 3600;
    $sessions = store_load();
    $settled = 0;
    $rejected = 0;
    foreach ($sessions as $id => $s) {
        if (($s['state'] ?? '') !== 'open' || (int)$s['created_at'] > $cutoff) {
            continue;
        }
        $status = null;
        $remote_total = null;
        if ($config['wapi_key'] !== '') {
            $env = WaypointWiPayCore::wapiEnvironment($config['wapi_key']) === 'sandbox' ? 'sandbox' : 'live';
            $find = WaypointWiPayCore::wapiEndpoint($config['country'], $env, '/transactions/' . rawurlencode((string)$s['txn']));
            if ($find !== null) {
                [$code, $body] = http_get($find, ['X-WAPI-Key: ' . $config['wapi_key']]);
                $data = json_decode($body, true);
                if ($code === 200 && is_array($data)) {
                    $inner = is_array($data['data'] ?? null) ? $data['data'] : $data;
                    $status = $inner['status'] ?? null;
                    $remote_total = isset($inner['total']) ? number_format((float)$inner['total'], 2, '.', '') : null;
                }
            }
        }
        if ($status === 'success' && hash_equals((string)$s['pinned_total'], (string)$remote_total)) {
            if (shopify_resolve($config, $s['gid'])) {
                $sessions[$id]['state'] = 'resolved';
                $sessions[$id]['notes'][] = 'reconciled_paid';
                $settled++;
            }
        } elseif ($status !== null) {
            if (shopify_reject($config, $s['gid'], 'PROCESSING_ERROR', 'Reconcile: WiPay reports ' . $status)) {
                $sessions[$id]['state'] = 'rejected';
                $sessions[$id]['notes'][] = 'reconciled_' . $status;
                $rejected++;
            }
        } else {
            $sessions[$id]['notes'][] = 'reconcile_unresolved';
        }
        $sessions[$id]['updated_at'] = time();
    }
    store_save($sessions);
    json_out(200, ['result' => 'Processed', 'settled' => $settled, 'rejected' => $rejected]);
}

// --- GET /health -----------------------------------------------------------
if ($path === '/health' && $method === 'GET') {
    json_out(200, ['ok' => true, 'module' => 'wipay-shopify-offsite', 'environment' => $config['environment']]);
}

json_out(404, ['error' => 'not_found']);
