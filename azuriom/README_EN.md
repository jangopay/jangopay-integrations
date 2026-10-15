# JangoPay for Azuriom Shop — v1.0.4

> **Owner acceptance pending.** Version 1.0.4 includes the four live API/webhook/money compatibility fixes. Real Azuriom/Shop integration and one USDT/BSC payment were verified; the paid order completed after audited recovery and synthetic callback replay. Before public rollout, verify a fresh order completing automatically on its first official callback. Real game-server delivery and production database/multi-node operation remain outside the tested scope. Current results are in [the live validation record](docs/LIVE-COMPAT-20261015.md).

## Features

An independent `jangopay` plugin registering a `JangoPay` gateway with Shop. USD-only one-time checkout with USDT/USDC through merchant-confirmed network combinations, private invoice creation, a payment-instruction page, a durable local attempt ledger, merchant-side verification, idempotent delivery claims, administrator manual review and a reconciliation command. No subscription, refund, exchange, payout, or currency conversion capabilities.

## Installation and configuration

1. Use an Azuriom 1.x / Shop 1.2.x **test** installation and PHP >= 8.1. Confirm OpenSSL and `proc_open` are supported if enabling encrypted webhooks.
2. Extract the install ZIP into `<azuriom>/plugins/jangopay`. The ZIP contains `plugin.json` **at its root**; do not nest the directory again. Enable the plugin using Azuriom administration.
3. Configure `JANGOPAY_API_KEY` in the server process environment (not in Shop's plaintext gateway data). Generate an RSA keypair and store the private key outside the public root. Set `JANGOPAY_RSA_PRIVATE_KEY_PATH` to its secure path; register the public key in JangoPay Console → Developer settings → Callback configuration.
4. Create a JangoPay gateway under Shop → Payment Gateways. Enter verified `USDT:chain,USDC:chain` pairs from your merchant account's payment-assets endpoint. The gateway form shows the webhook URL, normally `/api/shop/payments/jangopay/notification`.
5. Under `/admin/jangopay/payments`, use the read-only connection test and manual invoice verification tools.
6. **Fail-closed collection preflight:** `JANGOPAY_ENABLE_PAYMENTS=1`, `JANGOPAY_FINAL_INVOICE_STATUSES`, `JANGOPAY_WEBHOOK_TAG_FORMAT=appended16`, and a readable, valid RSA private key PEM must all be configured before the plugin can create a payable invoice. All settings default to disabled. Set the status and tag format only after authentic contract testing, never by guessing merely to bypass the guard.

## Checkout and safety

Shop determines the payable amount. The plugin reserves a Shop payment, stores a separate attempt, and creates a merchant invoice once per checkout reservation. The payment page lets buyers select a permitted chain and receives the **exact cryptocurrency pay amount** and address from JangoPay. A QR renderer is not yet included. Refreshing the page never creates an invoice. A create timeout leaves the result `unknown_creation`; administrator reverify only searches existing invoices and never blindly re-creates them.

Encrypted notifications or an operator-triggered reconciliation must authenticate to merchant APIs, re-check matching invoice/order, USD amount, final status and transaction ownership, then claim fulfillment in the ledger. Shop's built-in `processPayment()` performs delivery. The claim is persisted before calling Shop because external game-server commands cannot be rolled back reliably. Errors enter `delivery_attention`, requiring inspection before any manual remediation.

Browser redirects, screenshots, transaction broadcasts and public status endpoints never authorize delivery. Existing key material is never sent to the browser; merchant secrets are not stored by this plugin in the Shop gateway record.

## Operations

- Optional after real integration test: `php artisan jangopay:reconcile --limit=20` via an existing server cron.
- `quoted` = awaiting payment; `unknown_creation` = manually reconcile the remote invoice; `quote_unknown` = do not repeat quote; `manual_review` = mismatched/unverified payment; `delivery_started` or `delivery_attention` = review possible partial delivery; `delivered` = Shop completed.
- Back up the database before upgrades; the migration intentionally retains the evidence table on rollback/deactivation. Shop purges pending payments after two weeks; this plugin does not reconstruct already-purged Shop orders.
- Review `docs/API_CONTRACT.md` and `docs/TEST_REPORT.md` before further testing or publishing to the Azuriom Market.

## 1.0.2 independent-review fixes and remaining limits

- State-conditional recovery prevents stale unknown-create results from downgrading an ongoing delivery claim. Quote and invoice-creation response handling also use conditional updates.
- Concurrent first-time session snapshots now derive the same checkout key; Shop database mutations are wrapped in a transaction and unique-key collisions do not create second invoices. A successful checkout rotates the generation for a future, distinct purchase. Real shared-session database stress testing is still needed.
- Supports Shop's session-bound guest identity with a separate high-entropy payment capability, and snapshots the original asset/network allowlist.
- Read-only merchant lookups can recover a paid quote with a lost response; an unpaid quote can be restored only if merchant transaction details give a matching invoice, selected asset/chain, address and precise quoted amount. No blind POST retry. Transaction list scan is bounded to three pages / 300 entries.
- Scheduled reconciliation is fair across pending attempts using `last_checked_at`; local expiry is manual review only.
- See `docs/CODEX_REVIEW_FIXES_2026-10-14.md`. All regression tests are offline/fake-store checks, **not** real database or merchant integration proof.

## 1.0.3 session-race correction (Codex second independent recheck)

The 1.0.2 independent reviewer confirmed that a stale losing HTTP request could save an older session after the winning request. This lost the access token, checkout generation and cart-cleared state despite the DB's unique attempt index. 1.0.3 addresses this with **two layers**:

1. The plugin registers a `RouteMatched` listener and enables Laravel `Route::block(120, 45)` on upstream Shop checkout route names `shop.payments.payment`, `shop.payments.pay`, `shop.offers.buy`, and `shop.offers.pay`. Laravel 12 `StartSession` holds this lock until it saves the session. Blocking only a JangoPay-owned page route, or locking solely inside `startPayment()`, would not work.
2. New payment URL capabilities are derived from a domain-separated HMAC of the checkout key using the host application's strong `APP_KEY`, not saved only under `jangopay_access_*` in the session. This allows a retry with the original session identity and cart to recover the identical pending attempt even if some other unblocked web request overwrote the session. The database stores only the SHA-256 capability hash. Never reveal your APP_KEY or attempt to replicate the HMAC in browser code.

**Environment requirements:** use a persistent server-side Laravel session driver (`file`/`database`/`redis` etc.) and a distributed-capable, atomic-locking cache store (`database`, `redis`, etc., or `file` on a single host). `cookie`/`array` session drivers or `array`/`null` lock stores are refused before creating invoices. On multi-server deployments, use a genuinely shared session and lock store; `file` cache on isolated nodes does NOT coordinate locks. If lock acquisition fails, Laravel must abort the request before JangoPay invoices are created. The 120-second lock is longer than normal API call timeouts, but needs verifying with real infrastructure and failure-injection tests.

**Upgrade compatibility:** existing 1.0.2 payment attempts use random access tokens; they are accepted without modifying their hashes if the original matching session token is still present. If it was already overwritten and the purchaser has no valid saved checkout URL, do not reset hashes, open the order to anyone, or create another invoice blindly: audit the merchant invoice and manage the order manually. Changing `APP_KEY` invalidates recovery for newly derived capabilities (and has broader Laravel session impacts).

**Historical 1.0.3 status:** the reports in `docs/SESSION_CONCURRENCY_RECHECK_2026-10-14.md` and `docs/TEST_REPORT.md` describe the earlier simulation stage. For subsequent real-framework and live-payment results, use `docs/LIVE-COMPAT-20261015.md` and the 1.0.4 changelog.
