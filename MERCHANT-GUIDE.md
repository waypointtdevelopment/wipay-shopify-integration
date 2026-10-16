# Accepting WiPay on Shopify today — merchant guide

**What this guide is.** A working way for a Caribbean Shopify merchant to
accept **WiPay** while no native WiPay gateway exists on Shopify. It uses
Shopify's own **manual payment method** feature plus an optional reconciliation
app from this repository.

**What this is not.** It is not a native checkout integration. Customers will
not see "WiPay" as a payment option during Shopify checkout, and orders are
not paid automatically unless you run the reconciliation app. Nothing here
bypasses Shopify checkout or processes payment through it.

> WiPay does not endorse this guide. Waypoint maintains it independently. Use
> at your own risk; no warranty is provided.

## Before you start

- A Shopify store (any plan) in a WiPay-supported country: **Barbados,
  Grenada, Guyana, Jamaica, Trinidad & Tobago**.
- A WiPay merchant account (account number and API key).
- Check the currency you will charge. With a manual payment method the
  customer pays in your **store's currency**; confirm WiPay supports that
  currency for your country before you promise prices to customers.

## Option A — fully manual (no software, works today)

1. **Create the manual payment method.** In Shopify: *Settings → Payments →
   Manual payment methods → Create custom payment method*. Name it `WiPay`,
   and put the instructions in the box, e.g. "Complete your order, then pay
   via our WiPay link. Your order ships once payment is confirmed."
2. **Customer places the order.** They check out normally and select
   `WiPay`. The order is created with financial status **Pending** — that is
   expected.
3. **Send the customer a WiPay payment link** created from your WiPay
   merchant portal or via a Payment Request, for the exact order total.
4. **Confirm the money in WiPay** (portal/transaction history), then in
   Shopify open the order and click **Mark as paid**. The order becomes
   `Paid` and normal fulfilment continues.
5. **Refunds** are processed in WiPay; then cancel/refund the order in
   Shopify to keep records aligned.

Time cost: a few minutes per order. This is exactly the flow Shopify
documents under [manual payment methods](https://help.shopify.com/en/manual/payments/manual-payments).

## Option B — automated reconciliation (this repo)

The [reconcile/](reconcile/) app automates steps 3–5 and keeps the audit
trail: it records the order from Shopify's `orders/create` webhook, issues a
server-pinned WiPay hosted-checkout link, and marks the order paid through
Shopify's Admin API only after **WiPay confirms the payment by signed
server-to-server webhook with a matching amount**. The customer's browser
return never marks an order paid.

Setup overview (your developer or Waypoint can do this in an hour or two):

1. Deploy `reconcile/` anywhere that runs PHP (any host, or behind your own
   server). It has zero dependencies.
2. Create a **custom app** in *Settings → Apps → Develop apps* with
   `read_orders` + `write_orders`, subscribe it to `orders/create` and
   `orders/paid`, and give the app the webhook URL, admin API URL and
   access token.
3. Add your WiPay credentials and webhook secret as environment variables.
4. Create the same manual payment method as Option A, with instructions
   telling the customer their payment link follows.
5. Run `GET /wapi-reconcile?token=...` on a schedule (every 6 hours) as a
   backstop for any missed webhook.

**Read the caveat first:** automating "mark as paid" is fine when it records a
payment you have already agreed to accept off-platform. Do not extend it into
an alternative checkout — that needs Shopify Payments Partner approval. Full
detail in [PLATFORM-RESEARCH.md](PLATFORM-RESEARCH.md).

## What is still not possible

- Showing WiPay **inside** the Shopify checkout as a native payment method.
  That requires Shopify Payments Partner approval — invitation-only, with a
  revenue-share agreement and a separate app review.
- Automatic currency conversion per market: with any non-Shopify-Payments
  flow, customers pay in your store's default currency.
- Payphora: WiPay's own Shopify page points merchants to this third-party
  bridge (~$20/month). It is not a Waypoint product; evaluate it independently
  if you prefer a hosted service over running this software.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Order stays `Pending` after the customer paid | The WiPay webhook did not arrive or the amount differed from the order total. Check the app logs, then run the reconcile backstop. |
| `Order cannot be marked as paid` in logs | The order was already marked paid (by you or a replay). Benign. |
| Customer paid but no link was issued | The `orders/create` webhook was not delivered or its signature failed. Verify `SHOPIFY_CLIENT_SECRET` and the subscription topics. |
| Reconcile returns `wapi_not_configured` | `WIPAY_WAPI_KEY` is missing, so the backstop is disabled. |

## Support

Waypoint maintains this as an independent research project. WiPay support
handles anything on WiPay's side (transactions, refunds, webhooks). Shopify
support handles manual payment methods and custom app permissions.
