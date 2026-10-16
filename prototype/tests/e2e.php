<?php
/**
 * End-to-end prototype test: mock Shopify <-> app server <-> REAL WiPay
 * sandbox. Run:  php tests/e2e.php
 *
 * Verifies the full offsite flow without a Partner account:
 * session request -> hosted-page redirect (real sandbox checkout URL),
 * idempotency, hash-verified buyer return -> paymentSessionResolve,
 * tampered hash -> paymentSessionReject, signed webhook -> reject,
 * replay dedupe, authorization-kind refusal, compliance acks.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$APP_BASE = 'http://127.0.0.1:8091';
$SHOP_BASE = 'http://127.0.0.1:8092';
$DEV_SECRET = 'e2e-dev-secret';
$WEBHOOK_SECRET = 'e2e-webhook-secret';

$pass = 0;
$fail = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS  $label\n";
    } else {
        $fail++;
        echo "FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/** @return array{0:int,1:string} [code, body] */
function req(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($resp) ? $resp : ''];
}

// --- Clean state -----------------------------------------------------------
// data/ is gitignored — create it on a fresh clone so the log pipes below can open.
if (!is_dir($ROOT . '/data')) {
    mkdir($ROOT . '/data', 0777, true);
}

@unlink($ROOT . '/data/sessions.json');
@unlink($ROOT . '/data/mock_shopify_state.json');

// --- Boot servers ----------------------------------------------------------
$env = array_merge(getenv(), [
    'APP_BASE_URL'       => $APP_BASE,
    'WIPAY_COUNTRY'      => 'TT',
    'WIPAY_ENVIRONMENT'  => 'sandbox',
    'WIPAY_WEBHOOK_SECRET' => $WEBHOOK_SECRET,
    'SHOPIFY_API_URL'    => $SHOP_BASE . '/payments_apps/api/2026-01/graphql.json',
    'SHOPIFY_ACCESS_TOKEN' => 'mock-token',
    'DEV_SHARED_SECRET'  => $DEV_SECRET,
    'APP_DEBUG'          => '1',
]);

$php = PHP_BINARY;
$app_proc = proc_open(
    [$php, '-S', '127.0.0.1:8091', $ROOT . '/app/server.php'],
    [1 => ['file', $ROOT . '/data/app.out.log', 'a'], 2 => ['file', $ROOT . '/data/app.err.log', 'a']],
    $pipes,
    $ROOT,
    $env
);
$shop_proc = proc_open(
    [$php, '-S', '127.0.0.1:8092', $ROOT . '/harness/mock_shopify.php'],
    [1 => ['file', $ROOT . '/data/shop.out.log', 'a'], 2 => ['file', $ROOT . '/data/shop.err.log', 'a']],
    $pipes2,
    $ROOT,
    $env
);

register_shutdown_function(function () use ($app_proc, $shop_proc) {
    foreach ([$app_proc, $shop_proc] as $p) {
        if (is_resource($p)) {
            proc_terminate($p);
            proc_close($p);
        }
    }
});

$up = false;
for ($i = 0; $i < 50; $i++) {
    [$c1] = req('GET', $APP_BASE . '/health');
    [$c2] = req('GET', $SHOP_BASE . '/state');
    if ($c1 === 200 && $c2 === 200) {
        $up = true;
        break;
    }
    usleep(200000);
}
if (!$up) {
    echo "FAIL  servers did not become ready\n";
    exit(1);
}

// --- 1. Payment session request -> real sandbox checkout URL ---------------
[$code, $body] = req('POST', $SHOP_BASE . '/trigger-session',
    json_encode(['id' => 'attempt-e2e-1', 'amount' => '25.00', 'currency' => 'TTD', 'test' => true]),
    ['Content-Type: application/json']
);
$trig = json_decode($body, true);
$redirect = $trig['app_response']['redirect_url'] ?? '';
check('session request accepted by app', $code === 200 && ($trig['app_http_code'] ?? 0) === 200, 'got ' . json_encode($trig));
check('redirect_url is real WiPay sandbox checkout', (bool)preg_match('#^https://ttsb\.wipayfinancial\.com/checkout/payment\?#', $redirect), $redirect);
$gid1 = $trig['gid'] ?? '';

// --- 2. Idempotency --------------------------------------------------------
[$code2, $body2] = req('POST', $SHOP_BASE . '/trigger-session',
    json_encode(['id' => 'attempt-e2e-1', 'amount' => '25.00', 'currency' => 'TTD', 'test' => true]),
    ['Content-Type: application/json']
);
$trig2 = json_decode($body2, true);
check('repeat request returns same redirect (idempotent)',
    ($trig2['app_response']['redirect_url'] ?? '') === $redirect && $gid1 === ($trig2['gid'] ?? ''),
    json_encode($trig2));
$sessions = json_decode((string)file_get_contents($ROOT . '/data/sessions.json'), true);
check('only one session stored for repeated id', is_array($sessions) && count($sessions) === 1, 'count=' . (is_array($sessions) ? count($sessions) : 'n/a'));

// --- 3. No GraphQL mutations yet ------------------------------------------
[, $stateBody] = req('GET', $SHOP_BASE . '/state');
$state = json_decode($stateBody, true);
check('no mutations before buyer completes', empty($state['mutations']));

// --- 4. Buyer completes successfully (hash verified) -> Resolve ------------
$sessions = json_decode((string)file_get_contents($ROOT . '/data/sessions.json'), true);
$s1 = $sessions['attempt-e2e-1'];
$good_hash = md5($s1['txn'] . '25.00' . '123'); // sandbox api key
[$c4] = req('GET', $APP_BASE . '/return?' . http_build_query([
    'id' => 'attempt-e2e-1',
    'transaction_id' => $s1['txn'],
    'status' => 'success',
    'hash' => $good_hash,
]));
check('buyer return accepted (302 to Shopify)', $c4 === 302, 'got ' . $c4);
[, $stateBody] = req('GET', $SHOP_BASE . '/state');
$state = json_decode($stateBody, true);
$resolved = array_filter($state['mutations'] ?? [], fn($m) => $m['mutation'] === 'paymentSessionResolve' && $m['id'] === $gid1);
check('paymentSessionResolve called with session gid', count($resolved) === 1, json_encode($state['mutations'] ?? []));
$sessions = json_decode((string)file_get_contents($ROOT . '/data/sessions.json'), true);
check('session state is resolved', ($sessions['attempt-e2e-1']['state'] ?? '') === 'resolved');

// --- 5. Tampered hash -> Reject -------------------------------------------
req('POST', $SHOP_BASE . '/trigger-session',
    json_encode(['id' => 'attempt-e2e-2', 'amount' => '10.00', 'currency' => 'TTD', 'test' => true]),
    ['Content-Type: application/json']);
$sessions = json_decode((string)file_get_contents($ROOT . '/data/sessions.json'), true);
$s2 = $sessions['attempt-e2e-2'];
$trigState = json_decode((string)file_get_contents($ROOT . '/data/mock_shopify_state.json'), true);
$gid2 = '';
foreach (array_reverse($trigState['session_responses']) as $r) {
    if ($r['request_id'] === 'attempt-e2e-2') {
        $gid2 = $r['gid'];
        break;
    }
}
req('GET', $APP_BASE . '/return?' . http_build_query([
    'id' => 'attempt-e2e-2',
    'transaction_id' => $s2['txn'],
    'status' => 'success',
    'hash' => md5($s2['txn'] . '999.99' . '123'), // wrong pinned total
]));
[, $stateBody] = req('GET', $SHOP_BASE . '/state');
$state = json_decode($stateBody, true);
$resolved2 = array_filter($state['mutations'] ?? [], fn($m) => $m['mutation'] === 'paymentSessionResolve' && $m['id'] === $gid2);
$rejected2 = array_filter($state['mutations'] ?? [], fn($m) => $m['mutation'] === 'paymentSessionReject' && $m['id'] === $gid2);
check('tampered hash never resolves', count($resolved2) === 0);
check('tampered hash triggers paymentSessionReject', count($rejected2) === 1, json_encode($state['mutations'] ?? []));

// --- 6. Signed webhook -> Reject ------------------------------------------
req('POST', $SHOP_BASE . '/trigger-session',
    json_encode(['id' => 'attempt-e2e-3', 'amount' => '15.00', 'currency' => 'TTD', 'test' => true]),
    ['Content-Type: application/json']);
$trigState = json_decode((string)file_get_contents($ROOT . '/data/mock_shopify_state.json'), true);
$gid3 = '';
foreach (array_reverse($trigState['session_responses']) as $r) {
    if ($r['request_id'] === 'attempt-e2e-3') {
        $gid3 = $r['gid'];
        break;
    }
}
$sessions = json_decode((string)file_get_contents($ROOT . '/data/sessions.json'), true);
$s3 = $sessions['attempt-e2e-3'];
$ts = (string)time();
$event = json_encode([
    'id' => 'evt-e2e-fail-1',
    'event' => 'payment.failed',
    'occurred_at' => gmdate('c'),
    'data' => ['transaction_id' => $s3['txn'], 'order_id' => $s3['wipay_order'], 'status' => 'failed'],
    'meta' => [],
]);
$sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $event, $WEBHOOK_SECRET);
[$c6, $b6] = req('POST', $APP_BASE . '/webhook', $event, [
    'Content-Type: application/json',
    'X-WiPay-Webhook-Signature: ' . $sig,
    'X-WiPay-Webhook-Timestamp: ' . $ts,
    'X-WiPay-Webhook-Id: evt-e2e-fail-1',
]);
check('signed failure webhook accepted', $c6 === 200, 'got ' . $c6 . ' ' . $b6);
[, $stateBody] = req('GET', $SHOP_BASE . '/state');
$state = json_decode($stateBody, true);
$rejected3 = array_filter($state['mutations'] ?? [], fn($m) => $m['mutation'] === 'paymentSessionReject' && $m['id'] === $gid3);
check('webhook drives paymentSessionReject', count($rejected3) === 1);

// --- 7. Replay -> Duplicate ------------------------------------------------
[$c7, $b7] = req('POST', $APP_BASE . '/webhook', $event, [
    'Content-Type: application/json',
    'X-WiPay-Webhook-Signature: ' . $sig,
    'X-WiPay-Webhook-Timestamp: ' . $ts,
    'X-WiPay-Webhook-Id: evt-e2e-fail-1',
]);
check('replayed webhook deduplicated', $c7 === 200 && str_contains($b7, 'Duplicate'), $b7);

// --- 8. Bad signature rejected --------------------------------------------
[$c8] = req('POST', $APP_BASE . '/webhook', $event, [
    'Content-Type: application/json',
    'X-WiPay-Webhook-Signature: sha256=' . str_repeat('0', 64),
    'X-WiPay-Webhook-Timestamp: ' . $ts,
]);
check('bad webhook signature rejected', $c8 === 400, 'got ' . $c8);

// --- 9. Authorization kind refused ----------------------------------------
$ch = curl_init($SHOP_BASE . '/trigger-session');
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
    CURLOPT_POSTFIELDS => json_encode(['id' => 'attempt-e2e-auth', 'amount' => '5.00', 'currency' => 'TTD', 'kind' => 'authorization']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);
$r9 = json_decode((string)curl_exec($ch), true);
curl_close($ch);
check('authorization kind refused (422)', ($r9['app_http_code'] ?? 0) === 422 && str_contains(json_encode($r9), 'authorization_not_supported'), json_encode($r9));

// --- 10. Compliance + health ----------------------------------------------
[$c10] = req('POST', $APP_BASE . '/compliance/shop_redact', '{}', ['Content-Type: application/json']);
[$c11] = req('GET', $APP_BASE . '/health');
check('compliance webhook acknowledged', $c10 === 200);
check('health endpoint ok', $c11 === 200);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
