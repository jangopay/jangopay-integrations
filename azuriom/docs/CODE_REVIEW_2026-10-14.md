# JangoPay Azuriom v1.0.1 — Pre-Codex Review (2026-10-14)

## Fixed in this review copy

1. **CRITICAL — concurrent verification could re-enable delivery:** `src/Services/Reconciler.php` unconditionally wrote `state=verified`, including after another worker had advanced the same attempt to `delivery_started` (or `delivered`). A second process could then obtain the `verified → delivery_started` claim and invoke Shop fulfillment twice. Now only `awaiting_payment`/`quoted` may transition to `verified` using an atomic conditional UPDATE; terminal and in-flight states are not reset. Mismatch branches also conditionally transition to `manual_review`, so late query responses cannot overwrite a delivery claim.
2. **MEDIUM — checkout fingerprint omitted item options:** the old checksum only included display name, unit price and quantity, so selecting a different game server/variable on a same-priced item could reuse an earlier payment attempt in the same session. The fingerprint now hashes the full `Cart::toArray()` snapshot, USD total and currency; the checkout key also includes the logged-in user ID.
3. **LOW — payment method icon:** `src/JangoPayMethod.php::image` referenced `/plugins/...` while Azuriom's `PluginManager::createAssetsLink` links assets under `/assets/plugins/...`. Corrected the public path.

## Maintenance references

For current configuration, use [the installation guide](../README_EN.md) and [API contract](API_CONTRACT.md). Subsequent changes are recorded in [CHANGELOG.md](../CHANGELOG.md).

Operational rules: use shared session/lock storage across application nodes; keep checkout capabilities and credentials private; inspect actual game-server delivery before retrying external effects. Payment instructions display the asset, amount and address.
