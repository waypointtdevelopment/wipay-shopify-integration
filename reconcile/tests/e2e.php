<?php
/**
 * End-to-end test: mock Shopify Admin <-> reconciliation app <-> REAL WiPay
 * sandbox (for hosted-checkout link creation). Run: php tests/e2e.php
 *
 * Proves the no-approval flow end to end:
 *   orders/create (signed) -> record -> server-pinned WiPay link ->
 *   WiPay payment.success (signed) -> orderMarkAsPaid -> PAID,
 * plus every rejection path: bad HMAC, replays, unknown transactions,
 * amount mismatches, and a browser return that must never mark an order paid.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$APP_BASE = 'http://127.0.0.1:8095';
$ADMIN_BASE = 'http://127.0.0.1:8096';
$SHOP_SECRET = 'e2e-shopify-secret';
$WIPAY_SECRET = 'e2e-webhook-secret';

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

function admin_state(): array
{
    global $ADMIN_BASE;
    [, $b] = req('GET', $ADMIN_BASE . '/state');
    $d = json_decode($b, true);
    return is_array($d) ? $d : [];
}

/** Delivers a correctly signed Shopify webhook through the harness. */
function shopify_webhook(string $topic, array $body, string $webhookId): array
{
    global $ADMIN_BASE;
    [, $b] = req('POST', $ADMIN_BASE . '/send-shopify-webhook', json_encode([
        'topic' => $topic, 'body' => $body, 'webhook_id' => $webhookId,
    ]), ['Content-Type: application/json']);
    $d = json_decode($b, true);
    return is_array($d) ? $d : [];
}

/** Sends a WiPay webhook directly to the app with the given signature. */
function wipay_webhook(array $event, string $eventId, string $sig, string $ts): array
{
    global $APP_BASE;
    return req('POST', $APP_BASE . '/wipay/webhook', json_encode($event), [
        'Content-Type: application/json',
        'X-WiPay-Webhook-Signature: ' . $sig,
        'X-WiPay-Webhook-Timestamp: ' . $ts,
        'X-WiPay-Webhook-Id: ' . $eventId,
    ]);
}

function signed_wipay(array $event, string $eventId, string $secret): array
{
    $raw = json_encode($event);
    $ts = (string)time();
    return [$raw, $ts, 'sha256=' . hash_hmac('sha256', $ts . '.' . $raw, $secret), $eventId];
}

// --- Boot servers ----------------------------------------------------------
@mkdir($ROOT . '/data', 0775, true);
@unlink($ROOT . '/data/orders.json');
@unlink($ROOT . '/data/mock_admin_state.json');

$php = PHP_BINARY;
$env = array_merge(getenv(), [
    'APP_BASE_URL'         => $APP_BASE,
    'WIPAY_COUNTRY'        => 'TT',
    'WIPAY_ENVIRONMENT'    => 'sandbox',
    'WIPAY_WEBHOOK_SECRET' => $WIPAY_SECRET,
    'WIPAY_WAPI_KEY'       => 'wapi_test_e2e',
    'WIPAY_WAPI_BASE'      => $ADMIN_BASE . '/wapi',
    'SHOPIFY_CLIENT_SECRET'=> $SHOP_SECRET,
    'SHOPIFY_ADMIN_API_URL'=> $ADMIN_BASE . '/admin/api/2026-10/graphql.json',
    'SHOPIFY_ACCESS_TOKEN' => 'mock-token',
    'ADMIN_LINK_TOKEN'     => 'e2e-link',
    'RECONCILE_TOKEN'      => 'e2e-recon',
    'APP_DEBUG'            => '1',
]);
$appProc = proc_open(
    [$php, '-S', '127.0.0.1:8095', $ROOT . '/app/server.php'],
    [1 => ['file', $ROOT . '/data/app.out.log', 'a'], 2 => ['file', $ROOT . '/data/app.err.log', 'a']],
    $pipes, $ROOT, $env
);
$adminProc = proc_open(
    [$php, '-S', '127.0.0.1:8096', $ROOT . '/harness/mock_shopify_admin.php'],
    [1 => ['file', $ROOT . '/data/admin.out.log', 'a'], 2 => ['file', $ROOT . '/data/admin.err.log', 'a']],
    $pipes2, $ROOT, $env
);
register_shutdown_function(function () use ($appProc, $adminProc) {
    foreach ([$appProc, $adminProc] as $p) {
        if (is_resource($p)) {
            proc_terminate($p);
            proc_close($p);
        }
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    [$c1] = req('GET', $APP_BASE . '/health');
    [$c2] = req('GET', $ADMIN_BASE . '/state');
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

$GID_A = 'gid://shopify/Order/1001';
$GID_B = 'gid://shopify/Order/1002';
$GID_C = 'gid://shopify/Order/1003';
$GID_D = 'gid://shopify/Order/1004';

// --- 1. orders/create recorded --------------------------------------------
req('POST', $ADMIN_BASE . '/seed', json_encode(['id' => $GID_A, 'name' => '#1001', 'total' => '45.00', 'currency' => 'TTD', 'email' => 'buyer@example.com']), ['Content-Type: application/json']);
$r = shopify_webhook('orders/create', ['id' => $GID_A, 'name' => '#1001', 'email' => 'buyer@example.com', 'financialStatus' => 'PENDING', 'totalSet' => ['shopMoney' => ['amount' => '45.00', 'currencyCode' => 'TTD']]], 'wh-1');
check('signed orders/create accepted and order recorded', ($r['app_http_code'] ?? 0) === 200 && ($r['app_response']['result'] ?? '') === 'Recorded', json_encode($r));

// --- 2. bad HMAC rejected --------------------------------------------------
$badBody = json_encode(['id' => 'gid://shopify/Order/9999', 'totalSet' => ['shopMoney' => ['amount' => '10.00', 'currencyCode' => 'TTD']]]);
[$c2] = req('POST', $APP_BASE . '/shopify/webhook', $badBody, [
    'Content-Type: application/json',
    'X-Shopify-Topic: orders/create',
    'X-Shopify-Hmac-Sha256: ' . base64_encode('not-a-real-signature-value-here!!'),
    'X-Shopify-Webhook-Id: wh-bad',
]);
check('bad Shopify HMAC rejected (401)', $c2 === 401, 'got ' . $c2);

// --- 3. replayed orders/create deduplicated -------------------------------
$r3 = shopify_webhook('orders/create', ['id' => $GID_A, 'name' => '#1001', 'financialStatus' => 'PENDING', 'totalSet' => ['shopMoney' => ['amount' => '45.00', 'currencyCode' => 'TTD']]], 'wh-1');
check('replayed orders/create deduplicated', ($r3['app_response']['result'] ?? '') === 'Duplicate', json_encode($r3));

// --- 4. orders/paid for unknown order is a no-op ---------------------------
$r4 = shopify_webhook('orders/paid', ['id' => 'gid://shopify/Order/7777'], 'wh-2');
check('orders/paid for unknown order is a no-op', ($r4['app_response']['result'] ?? '') === 'Unknown', json_encode($r4));

// --- 5. payment-link guards ------------------------------------------------
[$c5] = req('GET', $APP_BASE . '/payment-link?order=' . rawurlencode($GID_A) . '&token=wrong');
check('payment-link refuses wrong token (403)', $c5 === 403, 'got ' . $c5);
[$c6] = req('GET', $APP_BASE . '/payment-link?order=' . rawurlencode('gid://shopify/Order/0') . '&token=e2e-link');
check('payment-link refuses unknown order (404)', $c6 === 404, 'got ' . $c6);

// --- 6. payment-link pins the server-side amount ---------------------------
[$c7, $b7] = req('GET', $APP_BASE . '/payment-link?order=' . rawurlencode($GID_A) . '&token=e2e-link&total=999.00');
$linkA = json_decode($b7, true);
check('payment-link returns real WiPay sandbox checkout', $c7 === 200 && (bool)preg_match('#^https://ttsb\.wipayfinancial\.com/checkout/payment\?#', (string)($linkA['checkout'] ?? '')), $b7);
check('amount is pinned server-side (client total ignored)', ($linkA['total'] ?? '') === '45.00', json_encode($linkA));

// --- 7. bad WiPay signature never marks paid -------------------------------
$event = ['id' => 'wv-0', 'event' => 'payment.success', 'data' => ['transaction_id' => (string)($linkA['txn'] ?? ''), 'total' => '45.00']];
[$c8] = wipay_webhook($event, 'wv-0', 'sha256=' . str_repeat('0', 64), (string)time());
$state = admin_state();
check('bad WiPay signature rejected (400) and no mutation', $c8 === 400 && count($state['mutations']) === 0, 'got ' . $c8);

// --- 8. verified payment.success marks the order paid ----------------------
$ts = (string)time();
$event = ['id' => 'wv-1', 'event' => 'payment.success', 'occurred_at' => gmdate('c'), 'data' => ['transaction_id' => (string)($linkA['txn'] ?? ''), 'order_id' => (string)($linkA['ref'] ?? ''), 'total' => '45.00', 'status' => 'success']];
[, , $sig, ] = signed_wipay($event, 'wv-1', $WIPAY_SECRET);
[$c9, $b9] = wipay_webhook($event, 'wv-1', $sig, $ts);
$state = admin_state();
$mutA = array_filter($state['mutations'] ?? [], fn($m) => $m['mutation'] === 'orderMarkAsPaid' && $m['id'] === $GID_A);
check('verified payment.success marks order paid', $c9 === 200 && str_contains($b9, 'MarkedPaid'), 'got ' . $c9 . ' ' . $b9);
check('orderMarkAsPaid called exactly once for the order', count($mutA) === 1, json_encode($state['mutations']));

// --- 9. replayed WiPay event deduplicated ---------------------------------
[$c10, $b10] = wipay_webhook($event, 'wv-1', $sig, $ts);
$state = admin_state();
check('replayed WiPay event deduplicated', $c10 === 200 && str_contains($b10, 'Duplicate') && count($state['mutations']) === 1, $b10);

// --- 10. success for an unknown transaction is ignored ---------------------
$ts11 = (string)time();
$event11 = ['id' => 'wv-2', 'event' => 'payment.success', 'data' => ['transaction_id' => 'SB-NOT-OURS-1', 'total' => '10.00']];
[, , $sig11] = signed_wipay($event11, 'wv-2', $WIPAY_SECRET);
[$c11, $b11] = wipay_webhook($event11, 'wv-2', $sig11, $ts11);
$state = admin_state();
check('success for unknown transaction is unmatched', $c11 === 200 && str_contains($b11, 'Unmatched') && count($state['mutations']) === 1, $b11);

// --- 11. amount mismatch never marks paid ---------------------------------
req('POST', $ADMIN_BASE . '/seed', json_encode(['id' => $GID_B, 'name' => '#1002', 'total' => '60.00', 'currency' => 'TTD']), ['Content-Type: application/json']);
shopify_webhook('orders/create', ['id' => $GID_B, 'name' => '#1002', 'financialStatus' => 'PENDING', 'totalSet' => ['shopMoney' => ['amount' => '60.00', 'currencyCode' => 'TTD']]], 'wh-3');
[, $bB] = req('GET', $APP_BASE . '/payment-link?order=' . rawurlencode($GID_B) . '&token=e2e-link');
$linkB = json_decode($bB, true);
$ts12 = (string)time();
$event12 = ['id' => 'wv-3', 'event' => 'payment.success', 'data' => ['transaction_id' => (string)($linkB['txn'] ?? ''), 'total' => '999.00']];
[, , $sig12] = signed_wipay($event12, 'wv-3', $WIPAY_SECRET);
[$c12, $b12] = wipay_webhook($event12, 'wv-3', $sig12, $ts12);
$state = admin_state();
$mutB = array_filter($state['mutations'] ?? [], fn($m) => $m['id'] === $GID_B);
check('amount mismatch flagged and never marks paid', $c12 === 200 && str_contains($b12, 'AmountMismatch') && count($mutB) === 0, $b12);

// --- 12. browser return never marks paid ----------------------------------
[$c13, $b13] = req('GET', $APP_BASE . '/return?ref=' . rawurlencode((string)($linkA['ref'] ?? '')) . '&transaction_id=' . rawurlencode((string)($linkA['txn'] ?? '')) . '&status=success&hash=' . md5((string)($linkA['txn'] ?? '') . '45.00' . '123'));
$state = admin_state();
check('browser return with valid hash confirms to the buyer', $c13 === 200 && str_contains($b13, 'Payment received'), $b13);
check('browser return never triggers orderMarkAsPaid', count($state['mutations']) === 1, json_encode($state['mutations']));
[$c14, $b14] = req('GET', $APP_BASE . '/return?ref=' . rawurlencode((string)($linkA['ref'] ?? '')) . '&transaction_id=' . rawurlencode((string)($linkA['txn'] ?? '')) . '&status=success&hash=' . str_repeat('a', 32));
check('tampered browser hash is not confirmed', $c14 === 200 && str_contains($b14, 'could not confirm'), $b14);

// --- 13. WAPI reconcile settles a missed webhook ---------------------------
req('POST', $ADMIN_BASE . '/seed', json_encode(['id' => $GID_C, 'name' => '#1003', 'total' => '75.00', 'currency' => 'TTD']), ['Content-Type: application/json']);
shopify_webhook('orders/create', ['id' => $GID_C, 'name' => '#1003', 'financialStatus' => 'PENDING', 'totalSet' => ['shopMoney' => ['amount' => '75.00', 'currencyCode' => 'TTD']]], 'wh-4');
[, $bC] = req('GET', $APP_BASE . '/payment-link?order=' . rawurlencode($GID_C) . '&token=e2e-link');
$linkC = json_decode($bC, true);
req('POST', $ADMIN_BASE . '/seed-wapi', json_encode(['txn' => (string)($linkC['txn'] ?? ''), 'status' => 'success', 'total' => '75.00']), ['Content-Type: application/json']);
[$c15, $b15] = req('GET', $APP_BASE . '/wapi-reconcile?token=e2e-recon&order=' . rawurlencode($GID_C));
$state = admin_state();
$mutC = array_filter($state['mutations'] ?? [], fn($m) => $m['id'] === $GID_C);
check('WAPI reconcile settles a paid order missed by webhooks', $c15 === 200 && str_contains($b15, '"settled":1') && count($mutC) === 1, $b15);

// --- 14. WAPI reconcile refuses a total mismatch ---------------------------
req('POST', $ADMIN_BASE . '/seed', json_encode(['id' => $GID_D, 'name' => '#1004', 'total' => '50.00', 'currency' => 'TTD']), ['Content-Type: application/json']);
shopify_webhook('orders/create', ['id' => $GID_D, 'name' => '#1004', 'financialStatus' => 'PENDING', 'totalSet' => ['shopMoney' => ['amount' => '50.00', 'currencyCode' => 'TTD']]], 'wh-5');
[, $bD] = req('GET', $APP_BASE . '/payment-link?order=' . rawurlencode($GID_D) . '&token=e2e-link');
$linkD = json_decode($bD, true);
req('POST', $ADMIN_BASE . '/seed-wapi', json_encode(['txn' => (string)($linkD['txn'] ?? ''), 'status' => 'success', 'total' => '111.00']), ['Content-Type: application/json']);
$before = count(admin_state()['mutations']);
[$c16, $b16] = req('GET', $APP_BASE . '/wapi-reconcile?token=e2e-recon&order=' . rawurlencode($GID_D));
$state = admin_state();
check('WAPI reconcile refuses a total mismatch', $c16 === 200 && str_contains($b16, '"settled":0') && count($state['mutations']) === $before, $b16);

// --- 15. guards + health ---------------------------------------------------
[$c17] = req('GET', $APP_BASE . '/wapi-reconcile?token=wrong');
check('wapi-reconcile refuses wrong token (403)', $c17 === 403, 'got ' . $c17);
[$c18] = req('GET', $APP_BASE . '/health');
check('health endpoint ok', $c18 === 200);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
