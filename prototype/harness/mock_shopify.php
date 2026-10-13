<?php
/**
 * Mock Shopify for local end-to-end testing of the offsite payment
 * extension prototype. Serves:
 *   POST /payments_apps/api/2026-01/graphql.json  (records mutations)
 *   POST /trigger-session                          (sends a session request to the app)
 *   GET  /state                                    (recorded mutations + session responses)
 *
 *   APP_PORT=8082 php -S 127.0.0.1:8082 harness/mock_shopify.php
 */

declare(strict_types=1);

const STATE_FILE = __DIR__ . '/../data/mock_shopify_state.json';

function state_load(): array
{
    if (!file_exists(STATE_FILE)) {
        return ['mutations' => [], 'session_responses' => []];
    }
    $s = json_decode((string)file_get_contents(STATE_FILE), true);
    return is_array($s) ? $s : ['mutations' => [], 'session_responses' => []];
}

function state_save(array $state): void
{
    if (!is_dir(dirname(STATE_FILE))) {
        mkdir(dirname(STATE_FILE), 0775, true);
    }
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
}

function json_out(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$state = state_load();

// --- Payments Apps GraphQL endpoint ---------------------------------------
if ($path === '/payments_apps/api/2026-01/graphql.json' && $method === 'POST') {
    $token = $_SERVER['HTTP_X_SHOPIFY_ACCESS_TOKEN'] ?? '';
    if ($token !== 'mock-token') {
        json_out(401, ['errors' => 'unauthorized']);
    }
    $req = json_decode((string)file_get_contents('php://input'), true);
    $query = is_array($req) ? (string)($req['query'] ?? '') : '';
    $variables = is_array($req) && is_array($req['variables'] ?? null) ? $req['variables'] : [];

    foreach (['paymentSessionResolve' => 'Resolved', 'paymentSessionReject' => 'Rejected', 'paymentSessionPending' => 'Pending'] as $mutation => $stateName) {
        if (strpos($query, $mutation) !== false) {
            $state['mutations'][] = [
                'mutation'   => $mutation,
                'id'         => $variables['id'] ?? '',
                'variables'  => $variables,
                'session_state' => $stateName,
                'at'         => date('c'),
            ];
            state_save($state);
            json_out(200, ['data' => [
                $mutation => [
                    'paymentSession' => ['id' => $variables['id'] ?? ''],
                    'userErrors'     => [],
                ],
            ]]);
        }
    }
    json_out(200, ['errors' => 'unknown mutation']);
}

// --- Trigger: send a documented payment-session request to the app --------
if ($path === '/trigger-session' && $method === 'POST') {
    $app = getenv('APP_BASE_URL') ?: 'http://127.0.0.1:8081';
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];

    // Documented offsite payment-session shape (shopify.dev request reference).
    $attempt = (string)($body['id'] ?? ('attempt-' . bin2hex(random_bytes(6))));
    $payment = [
        'id'       => $attempt,
        // Deterministic gid per payment attempt, like Shopify's stable
        // PaymentSession id for the same attempt.
        'gid'      => 'gid://shopify/PaymentSession/' . substr(sha1($attempt), 0, 10),
        'group'    => 'group-' . random_int(1000, 9999),
        'session_id' => 'sess-' . random_int(1000, 9999),
        'amount'   => (string)($body['amount'] ?? '25.00'),
        'currency' => (string)($body['currency'] ?? 'TTD'),
        'test'     => (bool)($body['test'] ?? true),
        'merchant_locale' => 'en',
        'payment_method' => [
            'type' => 'offsite',
            'data' => ['cancel_url' => $app . '/buyer-cancelled'],
        ],
        'proposed_at' => gmdate('c'),
        'kind'     => (string)($body['kind'] ?? 'sale'),
    ];
    if (!($body['omit_customer'] ?? false)) {
        $payment['customer'] = [
            'email'    => $body['email'] ?? 'buyer@example.com',
            'phone_number' => '+18685550101',
            'locale'   => 'en',
            'billing_address' => [
                'given_name'    => 'Jane',
                'family_name'   => 'Shopper',
                'line1'         => '12 Main Street',
                'line2'         => 'Apt 4',
                'city'          => 'Port of Spain',
                'postal_code'   => '00000',
                'province'      => 'Trinidad',
                'province_code' => 'TT',
                'country_code'  => 'TT',
                'phone_number'  => '+18685550101',
                'company'       => '',
            ],
        ];
    }

    $ch = curl_init($app . '/payment-session');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payment),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Shopify-Shop-Domain: demo.myshopify.com',
            'Shopify-Request-Id: ' . bin2hex(random_bytes(8)),
            'Shopify-Api-Version: 2026-01',
            'X-Dev-Shared-Secret: ' . (getenv('DEV_SHARED_SECRET') ?: 'dev-secret'),
        ],
    ]);
    $resp_body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $state['session_responses'][] = [
        'request_id' => $payment['id'],
        'gid'        => $payment['gid'],
        'http_code'  => $code,
        'response'   => json_decode((string)$resp_body, true),
        'at'         => date('c'),
    ];
    state_save($state);
    json_out(200, ['shopify_request_id' => $payment['id'], 'gid' => $payment['gid'], 'app_http_code' => $code, 'app_response' => json_decode((string)$resp_body, true)]);
}

// --- State inspector -------------------------------------------------------
if ($path === '/state' && $method === 'GET') {
    json_out(200, state_load());
}

json_out(404, ['error' => 'not_found']);
