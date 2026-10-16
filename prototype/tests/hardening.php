<?php
/**
 * Hardening tests for the mTLS-ready auth path.
 *
 * When SHOPIFY_CLIENT_CA is set the app must refuse requests that are not
 * backed by a TLS-verified client certificate — the dev shared-secret
 * header alone is no longer sufficient. When it is unset, the dev header
 * remains the documented substitute.
 *
 * Run: php tests/hardening.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
$php = PHP_BINARY;
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
        CURLOPT_TIMEOUT        => 15,
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

/** @return array{0:resource,1:string} [proc, base_url] */
function boot_app(int $port, array $extraEnv)
{
    global $php, $ROOT;
    @mkdir($ROOT . '/data', 0775, true);
    $env = array_merge(getenv(), [
        'APP_BASE_URL'      => 'http://127.0.0.1:' . $port,
        'WIPAY_COUNTRY'     => 'TT',
        'WIPAY_ENVIRONMENT' => 'sandbox',
        'DEV_SHARED_SECRET' => 'hardening-secret',
        'APP_DEBUG'         => '1',
    ], $extraEnv);
    $proc = proc_open(
        [$php, '-S', '127.0.0.1:' . $port, $ROOT . '/app/server.php'],
        [1 => ['file', $ROOT . '/data/hardening.out.log', 'a'], 2 => ['file', $ROOT . '/data/hardening.err.log', 'a']],
        $pipes,
        $ROOT,
        $env
    );
    $base = 'http://127.0.0.1:' . $port;
    for ($i = 0; $i < 50; $i++) {
        [$c] = req('GET', $base . '/health');
        if ($c === 200) {
            break;
        }
        usleep(200000);
    }
    return [$proc, $base];
}

// --- A. No SHOPIFY_CLIENT_CA: dev header is the substitute -----------------
[$procA, $baseA] = boot_app(8093, []);
[$cA1] = req('POST', $baseA . '/payment-session', '{}', [
    'Content-Type: application/json', 'X-Dev-Shared-Secret: wrong-secret',
]);
check('without client CA, wrong dev secret is rejected (403)', $cA1 === 403, 'got ' . $cA1);
[$cA2, $bA2] = req('POST', $baseA . '/payment-session', '{}', [
    'Content-Type: application/json', 'X-Dev-Shared-Secret: hardening-secret',
]);
check('without client CA, valid dev secret passes auth (handler answers 422)', $cA2 === 422, 'got ' . $cA2 . ' ' . $bA2);

// --- B. SHOPIFY_CLIENT_CA set: certificate required ------------------------
[$procB, $baseB] = boot_app(8094, ['SHOPIFY_CLIENT_CA' => $ROOT . '/shopify.app.toml']);
[$cB1] = req('POST', $baseB . '/payment-session', '{}', [
    'Content-Type: application/json', 'X-Dev-Shared-Secret: hardening-secret',
]);
check('with client CA required, dev secret alone is refused (403)', $cB1 === 403, 'got ' . $cB1);
[$cB2] = req('GET', $baseB . '/health');
check('with client CA required, health still reachable', $cB2 === 200, 'got ' . $cB2);

foreach ([$procA, $procB] as $p) {
    if (is_resource($p)) {
        proc_terminate($p);
        proc_close($p);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
