<?php
/**
 * Waypoint WiPay — Shopify reconciliation core.
 *
 * Framework-free helpers for the single-store reconciliation app: Shopify
 * webhook verification (the documented base64 HMAC-SHA256 scheme) and order
 * reference handling. The WiPay side reuses WaypointWiPayCore, so the crypto
 * is identical to the shipped WordPress/Magento/OpenCart modules and to the
 * offsite prototype.
 */

require_once __DIR__ . '/../../prototype/lib/WaypointWiPayCore.php';

class ReconcileCore
{
    /** Admin GraphQL API version targeted by this app (latest stable, 2026-10). */
    public const ADMIN_API_VERSION = '2026-10';

    /**
     * Shopify webhook verification: X-Shopify-Hmac-Sha256 carries the
     * base64-encoded HMAC-SHA256 of the RAW request body, keyed with the
     * app's client secret. Constant-time compared; never re-serialize.
     */
    public static function shopifyWebhookValid($rawBody, $headerB64, $secret)
    {
        if ($secret === '' || $rawBody === '' || !is_string($headerB64) || $headerB64 === '') {
            return false;
        }
        $decoded = base64_decode($headerB64, true);
        if ($decoded === false) {
            return false;
        }
        $expected = hash_hmac('sha256', (string)$rawBody, (string)$secret, true);
        return hash_equals($expected, $decoded);
    }

    /** Builds the base64 HMAC header the way Shopify's own deliveries do (tests). */
    public static function shopifyWebhookSignature($rawBody, $secret)
    {
        return base64_encode(hash_hmac('sha256', (string)$rawBody, (string)$secret, true));
    }

    /**
     * Extracts the order record fields we persist from an orders/create or
     * orders/paid webhook body. Returns null when the payload is unusable.
     */
    public static function orderFromWebhook($payload)
    {
        if (!is_array($payload) || !isset($payload['id']) || !is_string($payload['id']) || $payload['id'] === '') {
            return null;
        }
        $total = $payload['totalSet']['shopMoney']['amount'] ?? null;
        $currency = strtoupper((string)($payload['totalSet']['shopMoney']['currencyCode'] ?? ''));
        if ($total === null || !preg_match('/^\d+(\.\d+)?$/', (string)$total) || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }
        return [
            'gid'        => $payload['id'],
            'name'       => (string)($payload['name'] ?? ''),
            'total'      => number_format((float)$total, 2, '.', ''),
            'currency'   => $currency,
            'email'      => (string)($payload['email'] ?? ''),
            'financial'  => (string)($payload['financialStatus'] ?? ''),
            'paid'       => (string)($payload['financialStatus'] ?? '') === 'PAID',
            'links'      => [],   // ref => ['txn' => ..., 'pinned_total' => ...]
            'notes'      => [],
            'created_at' => time(),
            'updated_at' => time(),
        ];
    }

    /**
     * WiPay order_id for a Shopify order: 16 chars, alphanumeric, stable per
     * order (the documented limit), and irreversible so the storefront never
     * learns internal ids.
     */
    public static function wipayOrderRef($shopifyGid)
    {
        return 'sh' . substr(hash('sha256', (string)$shopifyGid), 0, 14);
    }

    /** The documented Payments API order_id shape. */
    public static function isValidWipayOrderRef($ref)
    {
        return (bool)preg_match('/^[A-Za-z0-9]{1,16}$/', (string)$ref);
    }
}
