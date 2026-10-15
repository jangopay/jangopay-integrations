# Changelog

## 1.0.4 — 2026-10-15 (live integration compatibility fixes)

- Serialize empty authentication POST bodies as JSON objects (`{}`), fixing HTTP 400 responses from the live API.
- Accept official webhook payloads without a redundant inner `event_type`; retain rejection of conflicting event types and invalid encryption.
- Accept zero-padded API USD amounts such as `3.00000000` without rounding nonzero sub-cent order amounts.
- Reconcile merchant-paid collection fees against authenticated settled transaction gross, fee and net amounts using exact 8-decimal integer arithmetic.
- Preserve the existing session-locking, order ownership, conditional-state recovery and delivery-claim protections.
- Prepare the marketplace ZIP with `plugin.json` directly at its root; synchronize version metadata and installation documentation.
- Validation inherited from the final tested code: five regression suites, 99 non-nested passing assertions, real Azuriom/Shop integration, and one USDT/BSC payment followed by audited recovery and synthetic callback replay. The owner will perform a fresh-order first official callback acceptance test before public rollout; production database/multi-node and real game-server delivery coverage remains outstanding.

## 1.0.3 — 2026-10-14 (session concurrency independent-recheck remediation)

- Attach Laravel's native route session blocking to **upstream Shop payment entrypoints** via `RouteMatched` before `StartSession`; this holds the session lock through final middleware persistence rather than only within `startPayment()`.
- New checkout URL capabilities are derived using a domain-separated HMAC keyed with the host's 256-bit-or-stronger `APP_KEY`, and only the SHA-256 capability hash is recorded on the attempt. The new checkout no longer stores its *only* usable token in a mutable session snapshot.
- After a stale session write-back, a same-session retry derives the same capability, reuses the same attempt and remote invoice, and clears the resurrected cart; transaction conflicts also attempt a safe read-only reuse.
- Existing 1.0.2 attempts with a random session token continue to work **when the original token remains in that session**. Lost legacy tokens cannot be reconstructed and require manual investigation; historical access hashes are never replaced.
- Fail closed for cookie/array session drivers and process-local array/null session lock stores. Avoid forcing a session lock on unrelated payment gateways in these unsupported configurations.
- Add an isolated stale-session overwrite reproduction and 22 targeted checks; preserve original source and review evidence. **Real HTTP + shared cache/database concurrency tests are still outstanding.**
- No merchant keys, funds, external checkout requests or production deployment used.

## 1.0.2 — 2026-10-14 (Codex review remediation candidate)

- Repair critical `unknown_creation` read-after-write race via CAS, including mismatched recovery; verify order ID.
- Deterministic initial checkout generation, DB-transactional local reservation, rotate generation on completed initial checkout.
- Honor Shop guest checkout identity only with a matching session and valid private payment access token.
- Fair cron reconciliation using persisted `last_checked_at`; expire old local attempts into manual review.
- Read-only recovery of confirmed `quote_unknown` payments and provable pending payer instructions, without repeating `confirm-coin`.
- Snapshot asset/network allowlist per attempt; avoid stale quote/create responses downgrading state.
- Align top-level invoice request casing with the official JangoPay PHP SDK; block invoice creation until explicit readiness preflight passes.
- Add third-party independent reproduction regression tests, collection gate tests, and updated evidence-based docs.
- **Not production ready:** real API field names, final statuses, tag framing, real database concurrency and Shop delivery remain unverified.


## 1.0.1 — 2026-10-14 (review candidate)

- Fix verified/terminal attempt state regression under concurrent reconciliations by conditional database state transitions.
- Prevent late mismatches from reverting an in-progress delivery to manual_review.
- Correct Azuriom published gateway image path.
- Include Shop cart variables, identity, coupons and giftcards in duplicate-checkout fingerprint.
- Add reviewer findings and a focused offline state regression test.

## 1.0.0 — 2026-10-14 (integration candidate)

- Independent Shop PaymentMethod registration, admin gateway configuration, USD-only checkout.
- One-time invoice creation, per-checkout local reservation, order correlation and unknown-outcome handling.
- Payer asset/network choice and local payment details, no browser secrets.
- Merchant-side invoice/transaction validation with fail-closed status allowlist, durable delivery claim and Shop-native delivery call.
- Candidate encrypted webhook RSA-OAEP-SHA256/AES-GCM handler disabled until real vector verification.
- Operator read-only connection check, reconciliation page and manual Artisan command.
- English/Simplified Chinese interface strings and bilingual installation guides.
- 14 passing isolated offline tests, API contract and staging test report.

Known gaps: no installed Azuriom tests, real payment/webhook validation, local QR renderer or phone screenshot. Not production-ready.
