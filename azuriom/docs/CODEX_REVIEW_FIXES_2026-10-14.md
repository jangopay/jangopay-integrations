# JangoPay × Azuriom 1.0.2 — Codex independent-review remediation

Date: 2026-10-14. Based on `jangopay-azuriom-1.0.1-codex-review-20261014.md` and its `review-reproduction.zip` provided by the user. **Candidate test build, not production-ready.** No live payment or deployment performed.

## Findings and fixes

| Independent review | Resolution in 1.0.2 | Evidence / test |
| --- | --- | --- |
| P1: A delayed `unknown_creation` recovery rewrites `delivery_started`, enabling duplicate Shop delivery | Both valid and invalid recovered-invoice branches use `WHERE state='unknown_creation' AND invoice_id IS NULL` guarded update; verify returned order ID. Check and return actual current state if another request wins. Also guarded `creating` and `quoting` callbacks against late state overwrites. | `src/JangoPayMethod.php`, `src/Controllers/CheckoutController.php`, `tests/codex_regressions.php` Fiber interleaving. |
| P2: Same empty-session snapshot generates different random nonces on first checkout | Missing generation resolves deterministically to `000...000`, so concurrent copies of the same session derive the same unique checkout key. A DB transaction wraps local Shop payment and attempt reservation. On an accepted checkout the session generation rotates, allowing future purchases. Losing requests cannot create a second invoice. | `src/JangoPayMethod.php`; deterministic two-session checkout test. Real session/database stress test still needed. |
| P2: Shop guest checkout gets 403 despite a valid token | Authenticated users must still match the Shop owner. An unauthenticated user must have `shop.guest_purchases` enabled, `use_site_money=false`, matching `shop.user` session ID and the original high-entropy token. | `src/Controllers/CheckoutController.php`; allowed and denied guest tests. |
| P2: Reconcile command starves orders beyond the first 20 | New persisted `last_checked_at` ordering (unseen first, then oldest check), stamped before merchant I/O; includes `quote_unknown`. After the local cutoff even a settled incoming payment is diverted to manual review, never auto delivered. | `src/Commands/ReconcilePayments.php`; `database/migrations/2026_10_14_000100_...`; 21-attempt regression. |
| P2: `quote_unknown` cannot recover even with a validated transaction | Merchant invoice and transaction can now be read without creating new invoice/quote. A proven paid invoice + settled merchant transaction may advance to delivery; pending transaction can restore its address/precise amount only when the merchant response proves matching invoice and selected asset/chain. Otherwise stay unknown. Only up to three transaction list pages scanned. | `src/Services/Reconciler.php` and `JangoPayApi.php`; paid callback-hint, lookup-without-hint, pending quote tests. |

## Additional defenses

- A new explicit collection preflight refuses to create a payable invoice unless `JANGOPAY_ENABLE_PAYMENTS=1`, verified `JANGOPAY_FINAL_INVOICE_STATUSES`, verified `JANGOPAY_WEBHOOK_TAG_FORMAT=appended16`, and a readable RSA PEM private key are set. The strings alone are not proof of contract compatibility; the operator must have genuine samples.
- JangoPay invoice creation top-level fields are now converted to snake_case like the public PHP SDK; **production API acceptance not demonstrated**. Transaction/invoice list search observes returned `totalPages` and does not assume that a short page ends pagination when metadata is absent.
- Preserve `allowed_assets` on the attempt itself; future gateway updates do not invalidate previous legitimate invoices.
- Guest identity, full cart fingerprint, order/amount equality and all other previous fail-closed validation remain in place.

## Local test results and limitations

Run from `jangopay/`:

```bash
php tests/offline.php
php tests/state_regression.php
php tests/codex_regressions.php
php tests/readiness.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

- Offline synthetic tests: 17 successful checks, including locally generated RSA-OAEP-SHA256/AES-GCM vectors (not an official notification fixture).
- Original state regression: 10 checks, fake store.
- Codex-independent-review repair regression: 14 checks, Fiber and fake store. The fixture `tests/fixtures/ShopPaymentMethod.php` comes from the independent review and preserves upstream source attribution; it is *not* used as a runtime plugin class.
- Collection-preflight checks: 6, synthetic local RSA key fixture.
- All PHP syntax checks: passed in local PHP 8.4 CLI.

**Remaining blocking items**: Real Azuriom+Shop 1.2.x installation, MySQL/PostgreSQL unique-key contention with two workers and a shared session, merchant invoice/transaction response field casing and reliable pagination, authentic encrypted webhook and final invoice status, true paid order including in-game delivery, replays and long-delayed payments. A bounded transaction list search may not find older rows: use audited manual investigation. Do not enable live gateway or submit Market before these pass.

## Suggested Codex tests (highest priority)

1. Reproduce original P1 Fiber interleaving and simultaneously inject a real DB concurrent invoice recovery + Shop delivery, assert delivered exactly once.
2. Using two HTTP clients with the *same* session cookie and the *same* initial cart snapshot, race first checkout; assert exactly one local attempt, one Shop payment and one merchant createInvoice call. Include giftcards and a second deliberate identical purchase after cart regeneration.
3. Guest checkout: compare logged-in owner, guest session owner, wrong visitor, token replay and enabled/disabled guest policies.
4. Seed 21+ pending invoices and run cron twice. Verify attempt 21 is checked and expired local invoices turn into manual review without delivery.
5. Fault-inject `confirm-coin` timeout but persist a merchant transaction; exercise pending read-only address recovery, completed read-only fulfillment and mismatched transaction rejection.
6. Verify external game command can never be replayed on `delivery_attention` or a stale admin action. Observe actual DB write ordering rather than merely fake-store status changes.

No unattended live payments, credential embedding, remote deployments or Market submission were performed.
