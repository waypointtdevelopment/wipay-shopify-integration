# Platform research: Shopify

## Status
Research/partner-feasibility workspace only. No Waypoint-supported Shopify payment gateway exists.

## Hard constraint
A hosted-redirect payment method can only be surfaced in Shopify checkout as an **offsite payments extension**, and that path is open only to approved Shopify Payments Partners. Shopify says its payments platform is invitation-only, and applying does not guarantee acceptance. A normal app or a Checkout UI extension cannot register a new native checkout payment method in place of a payments extension.
- [Payments extensions](https://shopify.dev/docs/apps/build/payments)
- [Payments extension review](https://shopify.dev/docs/apps/build/payments/payments-extension-review)
- [Payments extension requirements](https://shopify.dev/docs/apps/build/payments/requirements)
- [Checkout UI extensions](https://shopify.dev/docs/api/checkout-ui-extensions/latest)
- [App Store requirements](https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements)

## What is buildable today
- **Partner route:** a properly approved offsite payments extension can redirect the buyer to a provider-hosted page and return to Shopify to complete the order. That is the real native path, but it is gated by Shopify partner authorization, review, and a revenue-share model.
- **Merchant workflow route:** Shopify supports manual payment methods with checkout instructions; the order stays unpaid until the merchant manually marks it paid. That is a sanctioned off-platform pattern, but it is not an automated WiPay integration.
  - [Manual payment methods](https://help.shopify.com/en/manual/payments/manual-payments)
  - [Create orders and get paid](https://help.shopify.com/en/manual/fulfillment/managing-orders/create-orders/get-paid)

## What is not buildable today
- A regular Shopify app cannot add WiPay as a checkout payment method or implement a hosted card redirect inside checkout without partner authorization.
- Do not build or publish anything that tries to bypass Shopify checkout or payment processing. Gateway apps must use the Payments API after authorization.

## WiPay-specific open point
WiPay's Shopify page points merchants to Payphora, a third-party bridge. That does not establish a WiPay-built or WiPay-supported Shopify gateway, and it does not resolve whether Waypoint could build or publish one.

## Recommended next steps
1. Decide whether a native checkout integration is a strategic priority or whether the short-term deliverable should be a merchant-facing manual-payment guide.
2. If native checkout matters, ask Shopify about Payments Partner access before investing in a payments extension, and ask WiPay whether it intends to pursue Shopify partner authorization.
3. If only a guide is wanted, write an explicit manual-payment/reconciliation guide that does not promise checkout automation.
4. Confirm approved territories, currencies, fees, dispute/refund handling, and reconciliation flow before any production-facing document.

## References
- [WiPay Shopify page](https://wipaycaribbean.com/integrations/shopify)
- [WiPay Payments API](https://docs.wipayfinancial.com/payments-api)
