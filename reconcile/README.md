# WiPay order reconciliation app — no Shopify approval required

A single-store Shopify app that lets a Caribbean merchant accept **WiPay**
today, without waiting for Shopify Payments Partner approval.

It does **not** add WiPay to Shopify checkout and it does **not** divert
checkout. It automates the workflow Shopify already sanctions: the merchant
creates the order with a **manual payment method**, the customer pays on
WiPay's hosted page through a link the app issues, and the app records that
payment against the order with the Admin GraphQL `orderMarkAsPaid` mutation —
which Shopify documents for exactly this purpose ("payments received outside
the standard checkout process").

**Terms caveat (read this):** Shopify's [API terms](https://www.shopify.com/legal/api-terms)
prohibit, without authorisation, "an alternative to Shopify Checkout for
checkout or payment processing". Recording a payment the merchant has already
agreed to accept off-platform is the documented use of `orderMarkAsPaid`; a
flow that actively diverts checkout and collects payment itself is not. Get
written confirmation from Shopify before distributing this beyond a single
store, and read [PLATFORM-RESEARCH.md](../PLATFORM-RESEARCH.md).

## What it does

1. **`orders/create` webhook** (HMAC-verified) — records the unpaid order:
   gid, name, total, currency, email. Replayed deliveries are deduplicated by
   `X-Shopify-Webhook-Id`.
2. **`GET /payment-link?order=<gid>&token=...`** — creates a real WiPay
   Payment Request with the **amount and currency pinned server-side from the
   recorded order** (there is no client-supplied amount to tamper with) and
   returns the hosted-checkout URL for the merchant to send to the customer.
3. **WiPay `payment.success` webhook** (HMAC-SHA256 + replay window) — the
   only authoritative signal. It matches the transaction to the order,
   re-checks the amount against the pinned total, then calls
   `orderMarkAsPaid`. Duplicate event ids never produce a second mutation.
4. **`GET /return`** — what the customer sees coming back from the hosted
   page. Hash-verified, informational only: it can never mark an order paid.
5. **`GET /wapi-reconcile`** — cron backstop for missed webhooks: retrieves
   the transaction through the WAPI and settles only when the status is
   `success` **and** the retrieved total equals the pinned total.

## Running the tests

```
php tests/e2e.php
```

21 assertions, exit 0. Boots the app plus a mock Shopify Admin (GraphQL
`orderMarkAsPaid`, signed webhook delivery, fake WAPI retrieve) and creates
**real hosted-checkout links against the WiPay sandbox**.

## Environment

| Variable | Purpose |
|---|---|
| `APP_BASE_URL` | Public base URL of this app |
| `SHOPIFY_CLIENT_SECRET` | HMAC key for verifying Shopify webhook deliveries (base64 `X-Shopify-Hmac-Sha256`) |
| `SHOPIFY_ADMIN_API_URL` | `{shop}/admin/api/2026-10/graphql.json` |
| `SHOPIFY_ACCESS_TOKEN` | Custom-app Admin API token (`write_orders`) |
| `ADMIN_LINK_TOKEN` | Guard for `/payment-link` |
| `RECONCILE_TOKEN` | Guard for `/wapi-reconcile` |
| `WIPAY_COUNTRY`, `WIPAY_ENVIRONMENT`, `WIPAY_ACCOUNT`, `WIPAY_API_KEY`, `WIPAY_FEE_STRUCTURE` | WiPay Payments API config |
| `WIPAY_WEBHOOK_SECRET` | HMAC key for WiPay webhook verification |
| `WIPAY_WAPI_KEY` | Enables the reconcile backstop (`wapi_test_*` = sandbox) |
| `WIPAY_WAPI_BASE` | Test override for the WAPI base URL |

## Shopify-side setup (the approval-free part)

1. Free Shopify Partner account → development store, or the merchant's own
   store.
2. **Settings → Apps → Develop apps** (Dev Dashboard): create a custom app
   with `read_orders` + `write_orders`. Legacy in-Admin custom-app creation
   closed on 2026-01-01. Custom distribution needs **no Shopify approval** and
   installs on one store only.
3. Subscribe the app to `orders/create` and `orders/paid` pointing at
   `/shopify/webhook`.
4. The authorizing store user needs the `mark_orders_as_paid` permission.
5. Merchant creates the manual payment method in **Settings → Payments →
   Create custom payment method** (admin UI; not available through the API).

## Security model

Identical to the shipped WordPress/Magento/OpenCart modules and the offsite
prototype: amounts pinned server-side, the browser never trusted, WiPay
webhooks HMAC-verified with a replay window and event dedupe, Shopify
webhooks verified against the raw body, and settlement only through
`orderMarkAsPaid` after server-side verification. The store's JSON state uses
`flock`-guarded read-modify-write; production should put Redis/DB behind the
same contract.

## Ownership and disclaimer

Waypoint maintains this independent project. WiPay does not endorse it. Use
is at your own risk; no warranty is provided.
