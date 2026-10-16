# WiPay Offsite Payments App — prototype

A working prototype of a Shopify **Offsite Payments App extension** backed by
WiPay's hosted checkout — built *before* the Shopify Payments Partner
conversation, so the discussion starts from a running integration instead of
a proposal.

**Status: e2e-verified locally (21/21 assertions, exit 0) plus a 4/4 hardening
suite, against the REAL WiPay sandbox.** No Partner account was needed for any of this.

## What runs locally

```
prototype/
├── app/server.php          # the payment app (zero-dependency PHP, php -S)
├── harness/mock_shopify.php# impersonates Shopify: sends session requests,
│                            #  serves the Payments Apps GraphQL endpoint
├── tests/e2e.php           # full end-to-end suite (php tests/e2e.php)
├── lib/WaypointWiPayCore.php # the unit-tested WiPay core (shared with OC module)
├── shopify.app.toml        # app manifest, ready for Partner account
├── shopify.extension.toml  # offsite extension manifest (2026-01)
└── data/                   # runtime state (gitignored)
```

Run everything:

```
php tests/e2e.php
php tests/hardening.php
```

Run the app alone (for manual poking):

```
APP_BASE_URL=http://127.0.0.1:8081 DEV_SHARED_SECRET=dev-secret \
SHOPIFY_API_URL=... SHOPIFY_ACCESS_TOKEN=... php -S 127.0.0.1:8081 app/server.php
```

## What the e2e suite proves (each assertion ran against the real sandbox)

1. Shopify payment-session request (documented 2026-01 shape: `id`, `gid`,
   `amount`, `currency`, `test`, `kind: sale`, customer + billing address,
   `cancel_url`) → app creates a **real WiPay sandbox Payment Request** and
   responds `200 {"redirect_url": "https://ttsb.wipayfinancial.com/checkout/payment?..."}`.
2. **Idempotency**: resending the same payment attempt id returns the same
   redirect; one session stored.
3. Nothing is reported to Shopify before the buyer pays.
4. Buyer returns hash-verified (`md5(txn + pinned_total + api_key)`, sandbox
   key `123`, server-pinned amount) → app calls **`paymentSessionResolve`**
   via the Payments Apps GraphQL API with the session gid.
5. **Tampered hash never resolves** → `paymentSessionReject` instead.
6. WiPay **webhook** (HMAC-SHA256 + replay window) for a failed payment →
   `paymentSessionReject`; replayed event id → `Duplicate`; bad signature → 400.
7. `kind: authorization` is refused (422) — hosted-page v1 captures on payment.
8. Compliance webhook endpoints ack 200; health endpoint ok.
9. A burst of eight duplicate `payment.success` webhooks yields exactly one
   processed delivery, seven `Duplicate`s, and no new mutation on a settled
   session (event dedupe + locked store). Costs no extra sandbox call.

## Testing note — sandbox rate limits

The suite makes three real Payment Requests per run against
`ttsb.wipayfinancial.com`. That endpoint sits behind Cloudflare rate limiting
(error **1015**, HTTP 429): running the suite in quick succession will start
returning `wipay_unavailable` from the app. The limit clears on WiPay's
schedule (observed: >15 minutes for this host) — wait it out and rerun; the
suite itself needs no changes.

## Real vs mocked

| Piece | Status |
|---|---|
| WiPay Payment Request / hosted checkout URL / transaction id | **REAL sandbox** (ttsb.wipayfinancial.com, test account) |
| Response hash, webhook HMAC verification | **REAL crypto, same code as the shipped WP/M2/OC modules** |
| Shopify session request shape, GraphQL resolve/reject mutations | Mock Shopify, **shapes copied verbatim from shopify.dev docs (2026-01)** |
| Buyer actually paying on the hosted page | Simulated (return params constructed like WiPay's documented redirect) |
| mTLS, OAuth token issuance, extension registration, app review | Not exercisable pre-approval |

## Production deltas (the Partner-conversation checklist)

1. **mTLS** — done at the app level (2026-10-16): set `SHOPIFY_CLIENT_CA` and
   the app additionally requires a TLS-verified client certificate
   (`SSL_CLIENT_VERIFY`) before touching a payment endpoint; the dev
   `X-Dev-Shared-Secret` header alone is no longer sufficient. The web server
   still has to terminate TLS and validate Shopify's chain against Shopify's
   Payments CA. Behaviour covered by `tests/hardening.php`.
2. **GraphQL endpoint + token** — point `SHOPIFY_API_URL` at
   `https://{shop}/payments_apps/api/2026-01/graphql.json` with a real OAuth
   access token (already the code path; only config changes).
3. **Extension uid** — generate via Partner CLI; fill `shopify.extension.toml`.
4. **Reject reason enum** — verified 2026-10-16 against the Payments Apps
   API `2026-10` enum reference: `PROCESSING_ERROR` is valid (35 values;
   `RISKY` is deprecated in favour of `FRAUD_SUSPECTED`). No change needed.
5. **`paymentSessionPending`** — not needed for the direct-redirect path but
   available for long-pending states; `pendingExpiresAt` ≤ 3 days.
6. **Capture/void** — only if we later support `kind: authorization`.
7. **Compliance webhooks** — register the three topics on the Partner
   dashboard; endpoints already exist and ack 200.
8. **Session store** — the JSON store now serialises every read-modify-write
   with `flock`, so concurrent webhooks cannot lose updates. Production still
   wants Redis/DB behind the same contract.

## WiPay-side configuration

Environment variables: `WIPAY_COUNTRY`, `WIPAY_ENVIRONMENT`, `WIPAY_ACCOUNT`,
`WIPAY_API_KEY`, `WIPAY_FEE_STRUCTURE` (`merchant_absorb`/`customer_pay`/
`split` — live-verified values), `WIPAY_WEBHOOK_SECRET`, `WIPAY_WAPI_KEY`
(enables refund + reconcile verification), `STALE_HOURS`, `RECONCILE_TOKEN`.

The security model is identical to the shipped WordPress/Magento/OpenCart
modules: amounts pinned server-side, browser never trusted, hash verified
against the pinned value, authoritative settlement only via the Payments
Apps API (never the buyer's return), HMAC-verified webhooks with replay
protection and event dedupe, WAPI-backed reconciliation.
