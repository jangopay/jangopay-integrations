# JangoPay × Azuriom 1.0.2 — Codex independent-review remediation

Date: 2026-10-14. Based on `jangopay-azuriom-1.0.1-codex-review-20261014.md` and its `review-reproduction.zip` provided by the user. 

## Findings and fixes

| Independent review | Resolution in 1.0.2 | Evidence / test |
| --- | --- | --- |
| P1: A delayed `unknown_creation` recovery rewrites `delivery_started`, enabling duplicate Shop delivery | Both valid and invalid recovered-invoice branches use `WHERE state='unknown_creation' AND invoice_id IS NULL` guarded update; verify returned order ID. Check and return actual current state if another request wins. Also guarded `creating` and `quoting` callbacks against late state overwrites. | `src/JangoPayMethod.php`, `src/Controllers/CheckoutController.php`, `tests/codex_regressions.php` Fiber interleaving. |
| P2: Same empty-session snapshot generates different random nonces on first checkout | Missing generation resolves deterministically to `000...000`, so concurrent copies of the same session derive the same unique checkout key. A DB transaction wraps local Shop payment and attempt reservation. On an accepted checkout the session generation rotates, allowing future purchases. Losing requests cannot create a second invoice. | `src/JangoPayMethod.php`; deterministic two-session checkout test. |
| P2: Shop guest checkout gets 403 despite a valid token | Authenticated users must still match the Shop owner. An unauthenticated user must have `shop.guest_purchases` enabled, `use_site_money=false`, matching `shop.user` session ID and the original high-entropy token. | `src/Controllers/CheckoutController.php`; allowed and denied guest tests. |
| P2: Reconcile command starves orders beyond the first 20 | New persisted `last_checked_at` ordering (unseen first, then oldest check), stamped before merchant I/O; includes `quote_unknown`. After the local cutoff even a settled incoming payment is diverted to manual review, never auto delivered. | `src/Commands/ReconcilePayments.php`; `database/migrations/2026_10_14_000100_...`; 21-attempt regression. |
| P2: `quote_unknown` cannot recover even with a validated transaction | Merchant invoice and transaction can now be read without creating new invoice/quote. A proven paid invoice + settled merchant transaction may advance to delivery; pending transaction can restore its address/precise amount only when the merchant response proves matching invoice and selected asset/chain. Otherwise stay unknown. Only up to three transaction list pages scanned. | `src/Services/Reconciler.php` and `JangoPayApi.php`; paid callback-hint, lookup-without-hint, pending quote tests. |

## Additional defenses

- A new explicit collection preflight refuses to create a payable invoice unless `JANGOPAY_ENABLE_PAYMENTS=1`, verified `JANGOPAY_FINAL_INVOICE_STATUSES`, verified `JANGOPAY_WEBHOOK_TAG_FORMAT=appended16`, and a readable RSA PEM private key are set. Configure the values for the merchant account and notification framing.
- JangoPay invoice creation top-level fields are now converted to snake_case like the public PHP SDK. Transaction/invoice list search observes returned `totalPages` and does not assume that a short page ends pagination when metadata is absent.
- Preserve `allowed_assets` on the attempt itself; future gateway updates do not invalidate previous legitimate invoices.
- Guest identity, full cart fingerprint, order/amount equality and all other previous fail-closed validation remain in place.

## Related documentation

[Merchant setup](../README_EN.md) · [API contract](API_CONTRACT.md) · [Version history](../CHANGELOG.md)

Retain payment records for read-only recovery. A bounded transaction search may require manual investigation for older or ambiguous records. Inspect actual Shop delivery before retrying external game commands.
