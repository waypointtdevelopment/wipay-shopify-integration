# Waypoint WiPay for Shopify

An independent Shopify integration research project maintained by Waypoint. It is not affiliated with, sponsored by, or endorsed by WiPay.

## Project status

**Prototype built and e2e-verified (2026-10-13).** [prototype/](prototype/) implements a working Offsite Payments App (zero-dependency PHP + mock-Shopify harness); `php prototype/tests/e2e.php` runs the full flow end-to-end against the **real WiPay sandbox** — 17/17 assertions green. Ready for the Payments Partner conversation; production deltas are listed in [prototype/README.md](prototype/README.md).

**Reviewed (2026-10-13) — research/prototype stage.** The technically supported route for WiPay's hosted-page model is an **Offsite Payments App extension** on Shopify's Checkout Extensibility platform; the blocker is commercial (Shopify's Payments Platform is invitation-only, requiring Payments Partner approval). No Waypoint-supported Shopify payment gateway exists yet; WiPay's published page points to Payphora, a third-party bridge that is not a Waypoint product. See [PLATFORM-RESEARCH.md](PLATFORM-RESEARCH.md) for the full model comparison, requirements, and recommendation.

## What you can actually do with WiPay on Shopify today

There is no native WiPay checkout integration you can install from this repository. If a Shopify merchant still wants to use WiPay, the realistic path is a manual, off-checkout workflow: accept the order in Shopify, direct the customer to a WiPay payment link or hosted checkout through a separate backend, and reconcile the verified payment against the Shopify order by hand.

That is a merchant-operated workaround, not an automated Shopify integration. It does not add WiPay as a Shopify payment method and it does not automatically update Shopify orders. [reconcile/](reconcile/) automates the reconciliation half of that workflow — order recorded, payment verified server-side, order marked paid — without touching checkout; see the terms caveat in its README.

## What is in this repository

- [prototype/](prototype/) — the **Offsite Payments App** prototype for the native-checkout route (blocked on Shopify Payments Partner approval, but built, hardened and sandbox-verified: `php prototype/tests/e2e.php`, 21/21).
- [reconcile/](reconcile/) — the **approval-free reconciliation app** a merchant can run today: records orders, issues server-pinned WiPay hosted-checkout links, marks orders paid via `orderMarkAsPaid` on verified WiPay webhooks (`php reconcile/tests/e2e.php`, 21/21).
- [MERCHANT-GUIDE.md](MERCHANT-GUIDE.md) — merchant-facing instructions for accepting WiPay on Shopify today.

## Research

See [PLATFORM-RESEARCH.md](PLATFORM-RESEARCH.md) for the hard constraint, what is and is not buildable today, the Payphora open point, and official references.

## Ownership and disclaimer

Waypoint maintains this independent research project. WiPay does not endorse it. Use is at your own risk; no warranty is provided.
