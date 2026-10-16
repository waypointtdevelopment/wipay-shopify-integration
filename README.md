# Waypoint WiPay for Shopify

An independent Shopify integration research project maintained by Waypoint. It is not affiliated with, sponsored by, or endorsed by WiPay.

## Project status

**Prototype built and e2e-verified (2026-10-13).** [prototype/](prototype/) implements a working Offsite Payments App (zero-dependency PHP + mock-Shopify harness); `php prototype/tests/e2e.php` runs the full flow end-to-end against the **real WiPay sandbox** — 17/17 assertions green. Ready for the Payments Partner conversation; production deltas are listed in [prototype/README.md](prototype/README.md).

**Reviewed (2026-10-13) — research/prototype stage.** The technically supported route for WiPay's hosted-page model is an **Offsite Payments App extension** on Shopify's Checkout Extensibility platform; the blocker is commercial (Shopify's Payments Platform is invitation-only, requiring Payments Partner approval). No Waypoint-supported Shopify payment gateway exists yet; WiPay's published page points to Payphora, a third-party bridge that is not a Waypoint product. See [PLATFORM-RESEARCH.md](PLATFORM-RESEARCH.md) for the full model comparison, requirements, and recommendation.

## Research

See [PLATFORM-RESEARCH.md](PLATFORM-RESEARCH.md) for the hard constraint, what is and is not buildable today, the Payphora open point, and official references.

## Ownership and disclaimer

Waypoint maintains this independent research project. WiPay does not endorse it. Use is at your own risk; no warranty is provided.
