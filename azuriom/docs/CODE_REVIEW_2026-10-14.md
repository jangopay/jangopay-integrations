# JangoPay Azuriom v1.0.1 — Pre-Codex Review (2026-10-14)

## Scope and limitations

Static source inspection, upstream Shop `PaymentMethod`, `Payment`, `PaymentManager`, `PluginManager`, and JangoPay PHP SDK comparison. PHP lint, offline synthetic cryptography tests and targeted deterministic state-transition regression tests. **No real Azuriom installation, merchant invoice, real JangoPay webhook or actual game fulfillment was executed.**

## Fixed in this review copy

1. **CRITICAL — concurrent verification could re-enable delivery:** `src/Services/Reconciler.php` unconditionally wrote `state=verified`, including after another worker had advanced the same attempt to `delivery_started` (or `delivered`). A second process could then obtain the `verified → delivery_started` claim and invoke Shop fulfillment twice. Now only `awaiting_payment`/`quoted` may transition to `verified` using an atomic conditional UPDATE; terminal and in-flight states are not reset. Mismatch branches also conditionally transition to `manual_review`, so late query responses cannot overwrite a delivery claim.
2. **MEDIUM — checkout fingerprint omitted item options:** the old checksum only included display name, unit price and quantity, so selecting a different game server/variable on a same-priced item could reuse an earlier payment attempt in the same session. The fingerprint now hashes the full `Cart::toArray()` snapshot, USD total and currency; the checkout key also includes the logged-in user ID.
3. **LOW — payment method icon:** `src/JangoPayMethod.php::image` referenced `/plugins/...` while Azuriom's `PluginManager::createAssetsLink` links assets under `/assets/plugins/...`. Corrected the public path.

## Still unresolved: Codex must inspect and test

- **HIGH — exact remote API request contract:** plugin `createInvoice()` sends camelCase JSON fields, while the latest PHP SDK converts top-level names to snake_case. Public docs show camelCase, so do not assume either is correct without a real sandbox API exchange. Compare requests to SDK, confirm what real JangoPay accepts, and add a transport-level contract test.
- **HIGH — DB + Shop integration:** concurrency tests here use a deterministic fake store, **not** real MySQL/PostgreSQL with parallel requests. Validate unique indexes, transition CAS, Shop pending cleanup and `Payment::deliver()` side effects under genuine race conditions; no blind retry of external game commands.
- **HIGH — no authentic final invoice enum or encrypted webhook vector:** `JANGOPAY_FINAL_INVOICE_STATUSES` and `JANGOPAY_WEBHOOK_TAG_FORMAT` intentionally default off. Obtain official sample/merchant responses and ensure payedAmount uses the expected unit before enabling fulfillment.
- **MEDIUM — merchant credentials & gateway settings:** current API Key and RSA key path use server environment variables, not editable masked fields in Shop gateway admin. A shared API Key across multiple configured gateways needs an explicit operator restriction or per-gateway secret model.
- **MEDIUM — offered assets are not snapshotted per attempt:** `Reconciler` currently checks the gateway's *current* allowed network list; removing a network during payment can force `manual_review` for an otherwise legitimate payment. Confirm whether to store immutable asset allowlist when creating the invoice.
- **MEDIUM — user access token appears in checkout URL:** high-entropy random token exists; also check Referrer-Policy, analytics logging, and session ownership in rendered Azuriom theme.
- **MEDIUM — API/UX:** no QR code or actual automatic frontend polling despite having a status endpoint; no self-service recovery for `quote_unknown` and `unknown_creation`. The manual recovery behavior is conservative, not end-to-end validated.
- **MEDIUM — payment expiry:** Shop purges older pending records; after expiration the plugin intentionally leaves paid orders for manual audit. Operational refund/remediation path must be defined.
- **LOW — gateway icon and manual installation:** corrected icon path is not yet verified on a real server. Revalidate ZIP extraction path and Azuriom plugin loader, PHP version and permissions.

## Codex acceptance gate

Do **not** declare production-ready merely because this review archive lints. Run a real Azuriom + Shop staging instance, match verified JangoPay request/response + Webhook sample contracts, run simultaneous verify/webhook/admin command workers, and complete one controlled end-to-end small-value payment with actual Shop item delivery and replay. Never publish credentials or touch live funds without explicit permission.
