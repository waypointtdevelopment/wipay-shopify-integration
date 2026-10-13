# Platform research: Shopify

## Status

**Reviewed (2026-10-13).** Research/partner-feasibility workspace only — no code. WiPay's Shopify page points merchants to Payphora, a third-party bridge; that is not proof of a WiPay-built or WiPay-supported Shopify gateway.

## Integration models — what is actually possible for a hosted-page redirect provider

| Model | Fit | Access gate |
|---|---|---|
| **Offsite Payments App extension** (Checkout Extensibility) | **The official, intended fit for "redirect to provider-hosted page, verify server-side, webhooks back."** Shopify sends a payment-session request to our server; we return `redirect_url`; buyer returns to Shopify; we settle via the Payments Apps API. Browser return is never the source of truth. | **Shopify Payments Platform is invitation-only.** Two gates: Payments Partner application (review, not guaranteed) then payments-app review. Revenue-share agreement required before live processing. |
| Legacy external gateway | Existing listed providers keep working, but **no public self-service route** exists to build a new bespoke legacy gateway. Do not plan on this as an alternative. | Unverified/likely closed to new applicants |
| Checkout UI extension redirect | Not a payment integration. Extensions on checkout's payment step are **Shopify Plus-only**, and Shopify documents no authorization for a UI extension to replace payment processing with an off-site redirect. | Not viable as the payment flow |
| Embedded OAuth app (admin) | Fine for settings/reporting UI, **cannot process payments**. Useful only as an adjunct. | Standard app review (open) |

### Payments Platform requirements to plan for

- mTLS validation, idempotency/retry handling, compliance webhooks, supported API versions.
- Charge + refund + test-transaction capability; 3-D Secure where required.
- ≥ 99.95% availability, merchant support obligations, transparent pricing.
- Payment extension limited to the Payments Apps API + mandatory webhooks (no general Admin API use from it).
- Prohibited brands: Shop Pay, PayPal, Apple Pay, Google Pay.
- Listing lifecycle: approved apps are **hidden by default** (installable by link); "Generally Available" requires ≥ 50 stores and > US$1M processed. Payment extensions are not discoverable via the ordinary App Store.

### Market/currency reality for Caribbean merchants

- Shopify Payments is **not available** in TT/JM/BB/GY/GD — those merchants must use third-party providers (i.e., this is exactly WiPay's addressable market).
- Multi-currency checkout requires Shopify Payments or Adyen; with any other provider **customers pay in the store's default currency**. A WiPay payment app would charge in the store currency (TTD/JMD/etc.), not per-market local currency.
- Supported countries are declared by the extension; each territory must be confirmed against Shopify's live gateway directory.

## Recommendation

1. **The architecture fits perfectly** — Shopify's Offsite payment extension is purpose-built for WiPay's hosted-page model, including pending states and webhook-driven lifecycle, which our existing modules already implement (the WP/Magento/OpenCart security model ports over: server-pinned amounts, hash verification, HMAC webhooks, reconcile via WAPI retrieve).
2. **The blocker is commercial, not technical:** Shopify Payments Partner approval is invitation-only and not guaranteed. Per the pre-existing note in this repo: do not start a public native gateway until WiPay confirms it will pursue partner authorization and the operational obligations (mTLS, 99.95% uptime, support).
3. **Actionable now without approval:** build a prototype offsite payments app against a dev store (Partner account + `shopify app generate extension --type payments`), reusing the proven WiPay sandbox contract. This de-risks the partner conversation: WiPay/Waypoint can show a working integration rather than a proposal. Effort: ~6–12+ engineering weeks for production hardening; the prototype is far smaller.

## Next decisions

- Ask WiPay whether it will apply for Shopify Payments Partner access (prototype can proceed in parallel at low cost).
- Verify Payphora ownership, WiPay support, regions, fees, privacy/security, and order reconciliation.
- Confirm approved territories and currencies per platform entry.

## Sources (verified 2026-10-13)

- [Payments extensions overview](https://shopify.dev/docs/apps/build/payments)
- [Payments extension requirements](https://shopify.dev/docs/apps/build/payments/requirements)
- [Payments approval process](https://shopify.dev/docs/apps/build/payments/payments-extension-review)
- [Offsite extension tutorial (CLI)](https://shopify.dev/docs/apps/build/payments/offsite/use-the-cli)
- [Payment processing lifecycle](https://shopify.dev/docs/apps/build/payments/processing)
- [Offsite payment request reference](https://shopify.dev/docs/apps/build/payments/request-reference)
- [Shopify App Store requirements](https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements)
- [Shopify Payments supported countries](https://help.shopify.com/en/manual/payments/shopify-payments/supported-countries)
- [Markets local-currency rules](https://help.shopify.com/en/manual/markets/customizations/local-currencies)
- [Checkout UI extensions testing / Plus restriction](https://shopify.dev/docs/apps/build/checkout/test-checkout-ui-extensions)
- [WiPay Shopify page](https://wipaycaribbean.com/integrations/shopify)
