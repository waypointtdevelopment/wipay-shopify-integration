# Platform research: Shopify

## Status

**Research + working software.** No Waypoint-supported Shopify payment gateway exists. Native checkout access is gated by Shopify Payments Partner approval (commercial gate, not technical). Two things run today without approval: the **Offsite Payments App prototype** for the native route ([prototype/](prototype/), e2e-verified 2026-10-13, hardened and re-verified 2026-10-16: 21/21 flow assertions + 4/4 hardening, exit 0, against the real WiPay sandbox) and the **approval-free reconciliation app** ([reconcile/](reconcile/), 21/21 assertions, exit 0). WiPay's Shopify page points merchants to Payphora, a third-party bridge; that is not proof of a WiPay-built or WiPay-supported Shopify gateway.

## Hard constraint

A hosted-redirect payment method can only be surfaced in Shopify checkout as an **offsite payments extension**, and that path is open only to approved Shopify Payments Partners. Shopify says its payments platform is invitation-only, and applying does not guarantee acceptance. A normal app or a Checkout UI extension cannot register a new native checkout payment method in place of a payments extension.
- [Payments extensions](https://shopify.dev/docs/apps/build/payments)
- [Payments extension review](https://shopify.dev/docs/apps/build/payments/payments-extension-review)
- [Payments extension requirements](https://shopify.dev/docs/apps/build/payments/requirements)
- [Checkout UI extensions](https://shopify.dev/docs/api/checkout-ui-extensions/latest)
- [App Store requirements](https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements)

## Integration models — what is actually possible for a hosted-page redirect provider

| Model | Fit | Access gate |
|---|---|---|
| **Offsite Payments App extension** (Checkout Extensibility) | **The official, intended fit for "redirect to provider-hosted page, verify server-side, webhooks back."** Shopify sends a payment-session request to our server; we return `redirect_url`; buyer returns to Shopify; we settle via the Payments Apps API. Browser return is never the source of truth. | **Shopify Payments Platform is invitation-only.** Two gates: Payments Partner application (review, not guaranteed) then payments-app review. Revenue-share agreement required before live processing. |
| Legacy external gateway | Existing listed providers keep working, but **no public self-service route** exists to build a new bespoke legacy gateway. Do not plan on this as an alternative. | Unverified/likely closed to new applicants |
| Checkout UI extension redirect | Not a payment integration. Extensions on checkout's payment step are **Shopify Plus-only**, and Shopify documents no authorization for a UI extension to replace payment processing with an off-site redirect. | Not viable as the payment flow |
| Embedded OAuth app (admin) | Fine for settings/reporting UI and order reconciliation, **cannot process payments**. Useful as an adjunct (see interim work below). | Custom/unlisted distribution: **no Shopify approval** |

### Payments Platform requirements to plan for

- mTLS validation, idempotency/retry handling, compliance webhooks, supported API versions.
- Charge + refund + test-transaction capability; 3-D Secure where required.
- ≥ 99.95% availability, merchant support obligations, transparent pricing.
- Payment extension limited to the Payments Apps API + mandatory webhooks (no general Admin API use from it).
- Signed revenue-share agreement submitted before an app can be approved to process live payments; Shopify waives invoicing/collection below US$150,000 volume, but the rate is not public.
- Prohibited brands: Shop Pay, PayPal, Apple Pay, Google Pay.
- Listing lifecycle: approved apps are **hidden by default** (installable by link); "Generally Available" requires ≥ 50 stores and > US$1M processed. Payment extensions are not discoverable via the ordinary App Store.

### Market/currency reality for Caribbean merchants

- Shopify Payments is **not available** in TT/JM/BB/GY/GD — those merchants must use third-party providers (i.e., this is exactly WiPay's addressable market).
- Multi-currency checkout requires Shopify Payments or Adyen; with any other provider **customers pay in the store's default currency**. A WiPay payment app would charge in the store currency (TTD/JMD/etc.), not per-market local currency.
- Supported countries are declared by the extension; each territory must be confirmed against Shopify's live gateway directory. WiPay's documented processing platforms: Barbados (BB), Grenada (GD), Guyana (GY), Jamaica (JM), Trinidad & Tobago (TT).

## What is buildable today

- **Partner route:** a properly approved offsite payments extension can redirect the buyer to a provider-hosted page and return to Shopify to complete the order. That is the real native path, but it is gated by Shopify partner authorization, review, and a revenue-share model.
- **Merchant workflow route:** Shopify supports manual payment methods with checkout instructions; the order stays unpaid until the merchant manually marks it paid. That is a sanctioned off-platform pattern, but it is not an automated WiPay integration.
  - [Manual payment methods](https://help.shopify.com/en/manual/payments/manual-payments)
  - [Create orders and get paid](https://help.shopify.com/en/manual/fulfillment/managing-orders/create-orders/get-paid)

## What is not buildable today

- A regular Shopify app cannot add WiPay as a checkout payment method or implement a hosted card redirect inside checkout without partner authorization.
- Do not build or publish anything that tries to bypass Shopify checkout or payment processing. Gateway apps must use the Payments Apps API after authorization.

## Interim development that needs no Shopify approval (verified 2026-10-16)

None of the following requires Payments Partner approval, and all of it can start now:

1. **WiPay integration core** — Payments API client, hosted-checkout redirect, server-side response-hash verification, HMAC webhook handling with replay protection, WAPI refund/reconcile. Fully testable against the WiPay sandbox today (account `1234567890`, API key `123`, `https://ttsb.wipayfinancial.com`, `environment=sandbox`). **Implemented and covered by `php prototype/tests/e2e.php` (21/21) and `php prototype/tests/hardening.php` (4/4).**
2. **Prototype hardening on a dev store** — a free Shopify Partner account plus a development store is enough to exercise the app scaffolding, manifests and CLI flow. Dev stores cannot process real transactions; 250 dev stores per organization.
3. **Single-store custom app for reconciliation** — custom distribution requires **no Shopify approval** and can be installed on one store. Scopes: `read_orders` (60 days; `read_all_orders` needs Shopify approval) and `write_orders`; the authorizing user needs the `mark_orders_as_paid` permission. Webhook topics `orders/create`, `orders/paid`, `refunds/create` cover lifecycle events. New custom apps are created through the **Dev Dashboard** (legacy in-Admin creation closed 2026-01-01). [App distribution](https://shopify.dev/docs/apps/launch/distribution) · [Access scopes](https://shopify.dev/docs/api/usage/access-scopes)
4. **`orderMarkAsPaid` automation** — the current Admin GraphQL mutation (API `2026-10`) exists specifically to record "payments received outside the standard checkout process" (manual/offline/external reconciliation). [Mutation reference](https://shopify.dev/docs/api/admin-graphql/latest/mutations/orderMarkAsPaid)
   - **Terms caveat:** Shopify's [API terms](https://www.shopify.com/legal/api-terms) prohibit, absent authorization, "an alternative to Shopify Checkout for checkout or payment processing." Recording a payment the merchant already received is the documented use of the mutation; a flow that *diverts* checkout and collects payment itself is not. Get written confirmation from Shopify before shipping any automated collect-then-mark-paid design.
   - **Implemented (2026-10-16):** [reconcile/](reconcile/) — records orders from signed `orders/create` webhooks, issues server-pinned WiPay hosted-checkout links, marks orders paid only on a signed WiPay `payment.success` with a matching amount, and reconciles missed webhooks via the WAPI. It never touches checkout. `php reconcile/tests/e2e.php` — 21/21.
5. **Merchant-facing manual-payment + reconciliation guide** — **written:** [MERCHANT-GUIDE.md](MERCHANT-GUIDE.md). It promises no checkout automation and carries the disclaimers.

## WiPay-specific open point

WiPay's Shopify page points merchants to Payphora, a third-party bridge ($20/month, privacy policy updated 2026-08-19, no Shopify App Store listing found). That does not establish a WiPay-built or WiPay-supported Shopify gateway, and it does not resolve whether Waypoint could build or publish one.

## Recommendation

1. **The architecture fits perfectly** — Shopify's Offsite payment extension is purpose-built for WiPay's hosted-page model, including pending states and webhook-driven lifecycle, which our existing modules already implement (the WP/Magento/OpenCart security model ports over: server-pinned amounts, hash verification, HMAC webhooks, reconcile via WAPI retrieve).
2. **The blocker is commercial, not technical:** Shopify Payments Partner approval is invitation-only and not guaranteed. Do not start a public native gateway until WiPay confirms it will pursue partner authorization and the operational obligations (mTLS, 99.95% uptime, support).
3. **Actionable now without approval:** the prototype exists and is sandbox-verified, so the Partners conversation can start from a running integration rather than a proposal. Next technical spend is hardening it against a dev store, not new architecture. Production hardening effort: ~6–12+ engineering weeks; the prototype is far smaller.

## Recommended next steps

1. Decide whether a native checkout integration is a strategic priority or whether the short-term deliverable should be a merchant-facing manual-payment guide.
2. If native checkout matters, ask Shopify about Payments Partner access before investing further, and ask WiPay whether it intends to pursue Shopify partner authorization.
3. If only a guide is wanted, it exists: [MERCHANT-GUIDE.md](MERCHANT-GUIDE.md) plus the [reconcile/](reconcile/) app, with the terms caveat above. Remaining pre-approval work is deploying the app on a real dev store (free Partner account) and getting written Shopify confirmation on the mark-as-paid automation.
4. Confirm approved territories, currencies, fees, dispute/refund handling, and reconciliation flow before any production-facing document.
5. Verify Payphora ownership, WiPay support, regions, fees, privacy/security, and order reconciliation.

## Sources

Verified 2026-10-13; interim-development items re-verified 2026-10-16.

- [Payments extensions overview](https://shopify.dev/docs/apps/build/payments)
- [Payments extension requirements](https://shopify.dev/docs/apps/build/payments/requirements)
- [Payments approval process](https://shopify.dev/docs/apps/build/payments/payments-extension-review)
- [Offsite extension tutorial (CLI)](https://shopify.dev/docs/apps/build/payments/offsite/use-the-cli)
- [Payment processing lifecycle](https://shopify.dev/docs/apps/build/payments/processing)
- [Offsite payment request reference](https://shopify.dev/docs/apps/build/payments/request-reference)
- [Payments Apps API](https://shopify.dev/docs/api/payments-apps/latest)
- [Shopify App Store requirements](https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements)
- [Shopify Payments supported countries](https://help.shopify.com/en/manual/payments/shopify-payments/supported-countries)
- [Markets local-currency rules](https://help.shopify.com/en/manual/markets/customizations/local-currencies)
- [Checkout UI extensions testing / Plus restriction](https://shopify.dev/docs/apps/build/checkout/test-checkout-ui-extensions)
- [App distribution and approval requirements](https://shopify.dev/docs/apps/launch/distribution)
- [Development stores](https://shopify.dev/docs/apps/build/stores/development-stores)
- [Access scopes](https://shopify.dev/docs/api/usage/access-scopes)
- [orderMarkAsPaid mutation](https://shopify.dev/docs/api/admin-graphql/latest/mutations/orderMarkAsPaid)
- [Webhook subscription topics](https://shopify.dev/docs/api/admin-graphql/latest/enums/WebhookSubscriptionTopic)
- [Managing apps / Dev Dashboard custom apps](https://help.shopify.com/en/manual/apps/managing-apps)
- [Shopify API terms](https://www.shopify.com/legal/api-terms)
- [WiPay Shopify page](https://wipaycaribbean.com/integrations/shopify)
- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
- [WiPay hosted checkout flows](https://docs.wipayfinancial.com/hosted-checkout-flows)
- [WiPay platforms and environments](https://docs.wipayfinancial.com/platforms-and-environments)
