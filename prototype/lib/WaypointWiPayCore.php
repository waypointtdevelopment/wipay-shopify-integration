<?php
/**
 * Waypoint WiPay — shared, framework-free core.
 *
 * Single source of truth for the WiPay API contract used by both the
 * OpenCart 3.x and 4.x packages. No OpenCart dependencies on purpose:
 * this file is unit-tested directly, and identical copies ship inside
 * each package (tests assert the copies stay identical).
 */
class WaypointWiPayCore
{
    public const MODULE_VERSION = '1.0.0';

    public const SANDBOX_ACCOUNT_NUMBER = '1234567890';
    public const SANDBOX_API_KEY = '123';

    /** Valid fee_structure values per the live Payments API (verified 2026-10). */
    public const FEE_STRUCTURES = ['customer_pay', 'merchant_absorb', 'split'];

    /**
     * Normalizes fee structure to the API's valid values. Accepts the
     * canonical values plus legacy config aliases (MERCHANT/CUSTOMER);
     * anything unrecognized falls back to the documented default.
     */
    public static function feeStructure($value)
    {
        $value = (string)$value;
        if (in_array($value, self::FEE_STRUCTURES, true)) {
            return $value;
        }
        $aliases = [
            'MERCHANT' => 'merchant_absorb',
            'CUSTOMER' => 'customer_pay',
            'SPLIT' => 'split',
        ];
        return isset($aliases[$value]) ? $aliases[$value] : 'merchant_absorb';
    }

    /** @return bool True for a plausible WiPay transaction id (SB-... / NN-...). */
    public static function isValidTransactionId($id)
    {
        $id = (string)$id;
        return $id !== '' && strlen($id) <= 64 && (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id);
    }

    public const HOSTS = [
        'TT' => ['live' => 'tt.wipayfinancial.com', 'sandbox' => 'ttsb.wipayfinancial.com'],
        'JM' => ['live' => 'jm.wipayfinancial.com', 'sandbox' => 'jmsb.wipayfinancial.com'],
        'BB' => ['live' => 'bb.wipayfinancial.com', 'sandbox' => 'bbsb.wipayfinancial.com'],
        'GY' => ['live' => 'gy.wipayfinancial.com', 'sandbox' => 'gysb.wipayfinancial.com'],
        'GD' => ['live' => 'gd.wipayfinancial.com', 'sandbox' => 'gdsb.wipayfinancial.com'],
    ];

    /** @return string|null */
    public static function baseUrl($country, $environment)
    {
        $country = strtoupper((string) $country);
        if (!isset(self::HOSTS[$country][$environment])) {
            return null;
        }
        return 'https://' . self::HOSTS[$country][$environment];
    }

    /** @return string|null */
    public static function paymentEndpoint($country, $environment)
    {
        $base = self::baseUrl($country, $environment);
        return $base === null ? null : $base . '/plugins/payments/request';
    }

    /** @return string|null */
    public static function currenciesEndpoint($country, $environment)
    {
        $base = self::baseUrl($country, $environment);
        return $base === null
            ? null
            : $base . '/plugins/get/currencies?country_code=' . rawurlencode(strtoupper((string) $country));
    }

    /** @return string|null */
    public static function wapiEndpoint($country, $environment, $path)
    {
        $base = self::baseUrl($country, $environment);
        return $base === null ? null : $base . '/wapi' . $path;
    }

    /**
     * Documented formula: md5(transaction_id + original_total + api_key),
     * total formatted to exactly 2 decimals.
     */
    public static function responseHash($transactionId, $originalTotal, $apiKey)
    {
        return md5($transactionId . number_format((float)$originalTotal, 2, '.', '') . $apiKey);
    }

    /** @return bool */
    public static function verifyResponseHash($transactionId, $originalTotal, $apiKey, $providedHash)
    {
        if (!preg_match('/^[a-f0-9]{32}$/', (string)$providedHash)) {
            return false;
        }
        return hash_equals(self::responseHash($transactionId, $originalTotal, $apiKey), (string)$providedHash);
    }

    /** @return string|null 'live'|'sandbox' for a WAPI key, null when malformed. */
    public static function wapiEnvironment($key)
    {
        $parts = explode('_', trim((string)$key));
        if (count($parts) < 2 || $parts[0] !== 'wapi') {
            return null;
        }
        if ($parts[1] === 'live') {
            return 'live';
        }
        if ($parts[1] === 'test') {
            return 'sandbox';
        }
        return null;
    }

    /** @return bool True when the URL is an HTTPS wipayfinancial.com URL. */
    public static function isValidCheckoutUrl($url)
    {
        $parts = parse_url((string)$url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }
        return (bool)preg_match('/(^|\.)wipayfinancial\.com$/i', $parts['host']);
    }

    /**
     * Builds the documented Payment Request payload. The amount comes from
     * the order server-side; the browser never supplies one.
     *
     * @param array<string, mixed> $o keys: total, currency, email, order_id,
     *        country, environment, fee_structure, account_number, return_url,
     *        prefill (assoc of fname/lname/addr1/addr2/city/state/zipcode/country/phone)
     * @return array<string, mixed>
     */
    public static function buildPaymentRequest(array $o)
    {
        $payload = [
            'account_number' => (int)$o['account_number'],
            'country_code' => strtoupper((string)$o['country']),
            'currency' => strtoupper((string)$o['currency']),
            'environment' => (string)$o['environment'],
            'fee_structure' => self::feeStructure($o['fee_structure']),
            'method' => 'credit_card_co',
            'order_id' => (string)$o['order_id'],
            'origin' => 'WaypointOC',
            'response_url' => (string)$o['return_url'],
            'total' => number_format((float)$o['total'], 2, '.', ''),
            'email' => (string)$o['email'],
            'version' => self::MODULE_VERSION,
            'data' => json_encode([
                'order_id' => (string)$o['order_id'],
                'attempt' => isset($o['attempt']) ? (int)$o['attempt'] : time(),
            ]),
        ];
        $limits = [
            'fname' => 30, 'lname' => 30, 'addr1' => 50, 'addr2' => 50,
            'city' => 30, 'state' => 30, 'zipcode' => 10,
        ];
        $prefill = isset($o['prefill']) && is_array($o['prefill']) ? $o['prefill'] : [];
        $hasFname = isset($prefill['fname']) && trim((string)$prefill['fname']) !== '';
        $hasAddr1 = isset($prefill['addr1']) && trim((string)$prefill['addr1']) !== '';
        foreach ($limits as $field => $limit) {
            if ($field === 'lname' && !$hasFname) {
                continue;
            }
            if ($field === 'addr2' && !$hasAddr1) {
                continue;
            }
            if (!isset($prefill[$field])) {
                continue;
            }
            $text = trim((string)$prefill[$field]);
            if ($text !== '') {
                $payload[$field] = function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
            }
        }
        if (isset($prefill['country']) && preg_match('/^[A-Z]{2}$/', strtoupper((string)$prefill['country']))) {
            $payload['country'] = strtoupper((string)$prefill['country']);
        }
        if (isset($prefill['phone'])) {
            $phone = preg_replace('/[^+0-9]/', '', (string)$prefill['phone']);
            if (preg_match('/^\+[1-9][0-9]{1,14}$/', $phone)) {
                $payload['phone'] = $phone;
            }
        }
        return $payload;
    }

    /**
     * Parses the Supported Currencies helper response. Returns a clean list
     * of ISO codes, or null when the body is unusable (callers fail open).
     *
     * @return string[]|null
     */
    public static function currenciesFromJson($json)
    {
        $body = json_decode((string)$json, true);
        if (!is_array($body)) {
            return null;
        }
        $clean = [];
        foreach ($body as $code) {
            if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code)) {
                $clean[] = $code;
            }
        }
        return $clean ? array_values(array_unique($clean)) : null;
    }

    /**
     * Verifies a WiPay webhook signature per the documented v1 scheme:
     * HMAC-SHA256 over "timestamp + \".\" + raw_body" with the endpoint
     * secret, constant-time compared against the hex part of the
     * X-WiPay-Webhook-Signature header, within a replay window.
     *
     * @return bool
     */
    public static function verifyWebhookSignature($rawBody, $timestamp, $signatureHeader, $secret, $now = null)
    {
        if ($secret === '' || $rawBody === '' || $timestamp === '' || $signatureHeader === '') {
            return false;
        }
        if (!ctype_digit((string)$timestamp) || !preg_match('/^sha256=[a-f0-9]{64}$/', (string)$signatureHeader)) {
            return false;
        }
        $now = $now === null ? time() : (int)$now;
        $age = $now - (int)$timestamp;
        if ($age > 300 || $age < -300) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, (string)$secret);
        $received = substr((string)$signatureHeader, strlen('sha256='));
        return hash_equals($expected, $received);
    }
}
