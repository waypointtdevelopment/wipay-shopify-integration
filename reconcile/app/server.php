<?php
/**
 * Waypoint WiPay — Shopify order reconciliation app (NO Shopify approval needed).
 *
 * Records unpaid Shopify orders created with a manual payment method, hands
 * the merchant a server-pinned WiPay hosted-checkout link per order, and
 * marks the order paid through the Admin GraphQL orderMarkAsPaid mutation
 * once WiPay confirms the money by signed webhook. The browser is never the
 * source of truth and checkout is never diverted: this records a payment the
 * merchant has already agreed to accept off-platform (the documented use of
 * orderMarkAsPaid). Read PLATFORM-RESEARCH.md for the terms caveat.
 *
 * Zero dependencies; run with:
 *   APP_BASE_URL=http://127.0.0.1:8095 php -S 127.0.0.1:8095 app/server.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/ReconcileCore.php';

$config = [
    'base_url'        => getenv('APP_BASE_URL') ?: 'http://127.0.0.1:8095',
    'country'         => getenv('WIPAY_COUNTRY') ?: 'TT',
    'environment'     => getenv('WIPAY_ENVIRONMENT') ?: 'sandbox',
    'account'         => getenv('WIPAY_ACCOUNT') ?: WaypointWiPayCore::SANDBOX_ACCOUNT_NUMBER,
    'api_key'         => getenv('WIPAY_API_KEY') ?: WaypointWiPayCore::SANDBOX_API_KEY,
    'fee_structure'   => getenv('WIPAY_FEE_STRUCTURE') ?: 'merchant_absorb',
    'webhook_secret'  => getenv('WIPAY_WEBHOOK_SECRET') ?: '',
    'wapi_key'        => getenv('WIPAY_WAPI_KEY') ?: '',
    'wapi_base'       => getenv('WIPAY_WAPI_BASE') ?: '', // test override; derived from country otherwise
    'shopify_secret'  => getenv('SHOPIFY_CLIENT_SECRET') ?: '',
    'admin_api_url'   => getenv('SHOPIFY_ADMIN_API_URL') ?: '', // {shop}/admin/api/2026-10/graphql.json
    'admin_token'     => getenv('SHOPIFY_ACCESS_TOKEN') ?: '',
    'link_token'      => getenv('ADMIN_LINK_TOKEN') ?: 'link',
    'reconcile_token' => getenv('RECONCILE_TOKEN') ?: 'reconcile',
    'stale_hours'     => (int)(getenv('STALE_HOURS') ?: 24),
    'debug'           => getenv('APP_DEBUG') === '1',
];

// ---------------------------------------------------------------------------
// Store (JSON file + flock, same contract as the prototype)
// ---------------------------------------------------------------------------

function store_path(string $name): string
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir . '/' . $name;
}

/** @return array<string, array<string, mixed>> */
function store_load(string $name): array
{
    $path = store_path($name);
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function store_save(string $name, array $rows): void
{
    file_put_contents(store_path($name), json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
}

/** Whole read-modify-write cycle under one exclusive lock. */
function store_update(string $name, callable $fn): void
{
    $fp = fopen(store_path($name) . '.lock', 'c');
    flock($fp, LOCK_EX);
    try {
        $rows = store_load($name);
        $fn($rows);
        store_save($name, $rows);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function json_out(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

function html_out(int $status, string $html): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function debug_log(string $msg, array $ctx = []): void
{
    if (getenv('APP_DEBUG') === '1') {
        file_put_contents('php://stderr', '[wipay-reconcile] ' . $msg . ' ' . json_encode($ctx) . "\n", FILE_APPEND);
    }
}

function read_raw_body(): string
{
    return (string)file_get_contents('php://input');
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
 * Calls the Admin GraphQL API. Endpoint format:
 * https://{shop}/admin/api/2026-10/graphql.json with X-Shopify-Access-Token.
 * Returns [ok, decoded].
 */
function admin_graphql(array $config, string $query, array $variables): array
{
    if ($config['admin_api_url'] === '' || $config['admin_token'] === '') {
        debug_log('admin_graphql_not_configured');
        return [false, null];
    }
    [$code, $body] = http_post_json(
        $config['admin_api_url'],
        ['query' => $query, 'variables' => $variables],
        ['X-Shopify-Access-Token: ' . $config['admin_token']]
    );
    $decoded = json_decode($body, true);
    return [$code === 200 && is_array($decoded), $decoded];
}

/**
 * orderMarkAsPaid is NOT a no-op when the order is already paid: Shopify
 * returns the user error "Order cannot be marked as paid." We therefore only
 * call it after our own dedupe, and treat that error as a benign race.
 */
function mark_order_paid(array $config, string $gid): array
{
    [$ok, $resp] = admin_graphql($config, <<<'GQL'
    mutation MarkPaid($id: ID!) {
      orderMarkAsPaid(input: {id: $id}) {
        order { id displayFinancialStatus }
        userErrors { field message }
      }
    }
    GQL, ['id' => $gid]);
    $errors = $resp['data']['orderMarkAsPaid']['userErrors'] ?? null;
    $status = $resp['data']['orderMarkAsPaid']['order']['displayFinancialStatus'] ?? '';
    if ($ok && !$errors && $status === 'PAID') {
        return [true, 'PAID'];
    }
    debug_log('mark_paid_failed', ['resp' => $resp]);
    return [false, is_array($errors) && $errors ? (string)($errors[0]['message'] ?? 'user_error') : 'transport'];
}

/** Creates a real WiPay Payment Request with the amount pinned server-side. */
function create_wipay_link(array $config, array $order): array
{
    $ref = ReconcileCore::wipayOrderRef($order['gid']);
    $payload = WaypointWiPayCore::buildPaymentRequest([
        'total'          => $order['total'],
        'currency'       => $order['currency'],
        'email'          => $order['email'] !== '' ? $order['email'] : 'order-' . $ref . '@reconcile.invalid',
        'order_id'       => $ref,
        'country'        => $config['country'],
        'environment'    => $config['environment'],
        'fee_structure'  => $config['fee_structure'],
        'account_number' => $config['account'],
        'return_url'     => $config['base_url'] . '/return?ref=' . rawurlencode($ref),
    ]);
    $endpoint = WaypointWiPayCore::paymentEndpoint($config['country'], $config['environment']);
    if ($endpoint === null) {
        return ['error' => 'wipay_config'];
    }
    [$code, $body] = http_post_json($endpoint, $payload);
    $resp = json_decode($body, true);
    $url = is_array($resp) && isset($resp['url']) ? trim((string)$resp['url']) : '';
    $txn = is_array($resp) && isset($resp['transaction_id']) ? trim((string)$resp['transaction_id']) : '';
    if ($code !== 200 || !WaypointWiPayCore::isValidCheckoutUrl($url) || !WaypointWiPayCore::isValidTransactionId($txn)) {
        debug_log('wipay_request_failed', ['code' => $code, 'body' => substr($body, 0, 300)]);
        return ['error' => 'wipay_unavailable'];
    }
    return ['ref' => $ref, 'txn' => $txn, 'url' => $url, 'pinned_total' => $order['total']];
}

function wapi_base(array $config): ?string
{
    if ($config['wapi_base'] !== '') {
        return rtrim($config['wapi_base'], '/');
    }
    $env = WaypointWiPayCore::wapiEnvironment($config['wapi_key']) === 'sandbox' ? 'sandbox' : 'live';
    $base = WaypointWiPayCore::baseUrl($config['country'], $env);
    return $base === null ? null : $base . '/wapi';
}

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- POST /shopify/webhook (orders/create, orders/paid) --------------------
if ($path === '/shopify/webhook' && $method === 'POST') {
    $raw = read_raw_body();
    $hmac = (string)($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '');
    if (!ReconcileCore::shopifyWebhookValid($raw, $hmac, $config['shopify_secret'])) {
        json_out(401, ['result' => 'Invalid signature']);
    }
    $topic = strtolower((string)($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? ''));
    $event_id = (string)($_SERVER['HTTP_X_SHOPIFY_WEBHOOK_ID'] ?? '');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        json_out(400, ['result' => 'Invalid payload']);
    }

    if ($topic === 'orders/create') {
        $order = ReconcileCore::orderFromWebhook($payload);
        if ($order === null) {
            json_out(400, ['result' => 'Unusable order payload']);
        }
        $result = 'Recorded';
        store_update('orders', function (array &$orders) use ($order, $event_id, &$result) {
            if (isset($orders[$order['gid']])) {
                $result = 'Duplicate';
                return;
            }
            if ($event_id !== '') {
                $order['notes'][] = 'shopify:' . $event_id;
            }
            $orders[$order['gid']] = $order;
        });
        json_out(200, ['result' => $result]);
    }

    if ($topic === 'orders/paid') {
        $gid = is_string($payload['id'] ?? null) ? $payload['id'] : '';
        $result = 'Unknown';
        store_update('orders', function (array &$orders) use ($gid, $event_id, &$result) {
            if ($gid === '' || !isset($orders[$gid])) {
                return;
            }
            $orders[$gid]['paid'] = true;
            $orders[$gid]['financial'] = 'PAID';
            $orders[$gid]['updated_at'] = time();
            if ($event_id !== '') {
                $orders[$gid]['notes'][] = 'paid:' . $event_id;
            }
            $result = 'MarkedPaid';
        });
        json_out(200, ['result' => $result]);
    }

    json_out(200, ['result' => 'Ignored']);
}

// --- GET /payment-link?order=gid&token=... (merchant/cron) -----------------
if ($path === '/payment-link' && $method === 'GET') {
    $token = (string)($_GET['token'] ?? '');
    if (!hash_equals($config['link_token'], $token)) {
        json_out(403, ['error' => 'forbidden']);
    }
    $gid = (string)($_GET['order'] ?? '');
    if ($gid === '') {
        json_out(400, ['error' => 'missing_order']);
    }
    $order = store_load('orders')[$gid] ?? null;
    if ($order === null) {
        json_out(404, ['error' => 'unknown_order']);
    }
    if (!empty($order['paid'])) {
        json_out(409, ['error' => 'order_already_paid']);
    }
    // Amount and currency come from the recorded Shopify order, never the
    // query string: there is nothing to override.
    $link = create_wipay_link($config, $order);
    if (isset($link['error'])) {
        json_out(502, ['error' => $link['error']]);
    }
    store_update('orders', function (array &$orders) use ($gid, $link) {
        if (isset($orders[$gid])) {
            $orders[$gid]['links'][$link['ref']] = [
                'txn' => $link['txn'], 'pinned_total' => $link['pinned_total'],
                'created_at' => time(),
            ];
            $orders[$gid]['updated_at'] = time();
        }
    });
    json_out(200, [
        'order'    => $gid,
        'ref'      => $link['ref'],
        'txn'      => $link['txn'],
        'checkout' => $link['url'],
        'total'    => $link['pinned_total'],
    ]);
}

// --- GET /return (buyer lands back from the hosted page) -------------------
// Informational only. The authoritative signal is the WiPay webhook; the
// browser carries nothing we trust beyond showing the customer a status.
if ($path === '/return' && $method === 'GET') {
    $ref = (string)($_GET['ref'] ?? '');
    $txn = (string)($_GET['transaction_id'] ?? '');
    $status = (string)($_GET['status'] ?? '');
    $hash = strtolower((string)($_GET['hash'] ?? ''));
    $pinned = null;
    foreach (store_load('orders') as $order) {
        $link = $order['links'][$ref] ?? null;
        if ($link !== null && hash_equals((string)$link['txn'], $txn)) {
            $pinned = (string)$link['pinned_total'];
        }
    }
    $verified = $pinned !== null
        && $status === 'success'
        && WaypointWiPayCore::verifyResponseHash($txn, $pinned, $config['api_key'], $hash);
    if ($verified) {
        html_out(200, "<!doctype html><title>Payment received</title>"
            . "<p>Thank you. Your payment was received. Your order will be marked paid once WiPay confirms it to the store.</p>");
    }
    html_out(200, "<!doctype html><title>Payment status</title>"
        . "<p>We could not confirm a payment for this link. If you completed a payment, please contact the store.</p>");
}

// --- POST /wipay/webhook (WiPay server-to-server; authoritative) -----------
if ($path === '/wipay/webhook' && $method === 'POST') {
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
    $type = (string)($event['event'] ?? $event['event_type'] ?? '');
    $txn = (string)($event['data']['transaction_id'] ?? '');
    $eventTotal = isset($event['data']['total']) ? number_format((float)$event['data']['total'], 2, '.', '') : null;

    $result = 'Unmatched';
    store_update('orders', function (array &$orders) use ($config, $type, $txn, $eventTotal, $event_id, &$result) {
        if ($txn === '' || !WaypointWiPayCore::isValidTransactionId($txn)) {
            return;
        }
        $where = null; // [gid, ref]
        foreach ($orders as $gid => $order) {
            foreach ($order['links'] as $ref => $link) {
                if (($link['txn'] ?? '') === $txn) {
                    $where = [$gid, $ref];
                    break 2;
                }
            }
        }
        if ($where === null) {
            return;
        }
        [$gid, $ref] = $where;
        if ($event_id !== '' && in_array('wipay:' . $event_id, $orders[$gid]['notes'] ?? [], true)) {
            $result = 'Duplicate';
            return;
        }
        if ($event_id !== '') {
            $orders[$gid]['notes'][] = 'wipay:' . $event_id;
        }
        if ($type !== 'payment.success') {
            $orders[$gid]['notes'][] = 'lifecycle:' . $type;
            $orders[$gid]['updated_at'] = time();
            $result = 'Noted';
            return;
        }
        // Amount check: a success event for a different total never marks
        // an order paid, even with a valid signature.
        $pinned = (string)($orders[$gid]['links'][$ref]['pinned_total'] ?? '');
        if ($eventTotal !== null && !hash_equals($pinned, $eventTotal)) {
            $orders[$gid]['notes'][] = 'amount_mismatch:' . $eventTotal;
            $orders[$gid]['updated_at'] = time();
            $result = 'AmountMismatch';
            return;
        }
        if (!empty($orders[$gid]['paid'])) {
            $result = 'AlreadyPaid';
            return;
        }
        [$ok, $status] = mark_order_paid($config, $gid);
        if ($ok) {
            $orders[$gid]['paid'] = true;
            $orders[$gid]['financial'] = 'PAID';
            $result = 'MarkedPaid';
        } else {
            $orders[$gid]['notes'][] = 'mark_paid_failed:' . $status;
        }
        $orders[$gid]['updated_at'] = time();
    });
    json_out(200, ['result' => $result]);
}

// --- GET /wapi-reconcile?token= (cron for missed webhooks) -----------------
if ($path === '/wapi-reconcile' && ($method === 'GET' || $method === 'POST')) {
    $token = (string)($_GET['token'] ?? '');
    if (!hash_equals($config['reconcile_token'], $token)) {
        json_out(403, ['error' => 'forbidden']);
    }
    if ($config['wapi_key'] === '') {
        json_out(503, ['error' => 'wapi_not_configured']);
    }
    $base = wapi_base($config);
    if ($base === null) {
        json_out(503, ['error' => 'wipay_config']);
    }
    $target = (string)($_GET['order'] ?? '');
    $cutoff = time() - $config['stale_hours'] * 3600;
    $settled = 0;
    $skipped = 0;
    store_update('orders', function (array &$orders) use ($config, $base, $target, $cutoff, &$settled, &$skipped) {
        foreach ($orders as $gid => $order) {
            if (!empty($order['paid']) || ($target !== '' && $gid !== $target)) {
                continue;
            }
            foreach ($order['links'] as $ref => $link) {
                // The age window protects cron runs from churning on links
                // the buyer may still be using; a targeted run is deliberate.
                if ($target === '' && (int)($link['created_at'] ?? 0) > $cutoff) {
                    continue;
                }
                [$code, $body] = http_get($base . '/transactions/' . rawurlencode((string)$link['txn']), [
                    'X-WAPI-Key: ' . $config['wapi_key'],
                ]);
                $data = json_decode($body, true);
                $inner = is_array($data['data'] ?? null) ? $data['data'] : $data;
                $status = is_array($inner) ? ($inner['status'] ?? null) : null;
                $remoteTotal = is_array($inner) && isset($inner['total'])
                    ? number_format((float)$inner['total'], 2, '.', '') : null;
                if ($code !== 200 || $status === null) {
                    $skipped++;
                    continue;
                }
                if ($status === 'success' && hash_equals((string)$link['pinned_total'], (string)$remoteTotal)) {
                    [$ok] = mark_order_paid($config, $gid);
                    if ($ok) {
                        $orders[$gid]['paid'] = true;
                        $orders[$gid]['financial'] = 'PAID';
                        $orders[$gid]['notes'][] = 'reconciled:' . $ref;
                        $orders[$gid]['updated_at'] = time();
                        $settled++;
                    }
                    return; // one order per run keeps the audit trail clean
                }
                $orders[$gid]['notes'][] = 'reconcile_' . $status . ':' . $ref;
                $orders[$gid]['updated_at'] = time();
            }
        }
    });
    json_out(200, ['result' => 'Processed', 'settled' => $settled, 'skipped' => $skipped]);
}

// --- GET /health -----------------------------------------------------------
if ($path === '/health' && $method === 'GET') {
    json_out(200, ['ok' => true, 'module' => 'wipay-shopify-reconcile', 'environment' => $config['environment']]);
}

json_out(404, ['error' => 'not_found']);
