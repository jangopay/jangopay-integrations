# TEST REPORT — 2026-10-14 (v1.0.3 session-race remediation)

## V1.0.3 second independent-review retest

Original Codex reproduction from `jangopay-azuriom-1.0.2-session-review-reproduction.zip`, run *unchanged* on PHP 8.4.24: three expected reproduction assertions confirm the v1.0.2 stale-session overwrite and subsequent `processing` rejection. This is a BEFORE baseline, **not a success test**.

After v1.0.3 repair, using the bundled simulated session write-back tests:

| Run | Verified result | Scope / limitation |
| --- | --- | --- |
| `php tests/session_race_recovery.php` | 36 PASS output lines (14 previously existing Codex assertions + **22** new assertions) | Controlled fake sessions, route stubs, in-memory DB. Simulates winner-then-stale-overwrite then retry, validates same token and only one invoice; **does not execute real StartSession**. |
| `php tests/codex_regressions.php` | 14 PASS | In-memory store, no actual SQL transactions. |
| `php tests/state_regression.php` | 10 PASS | In-memory state transition assertions. |
| `php tests/readiness.php` | 6 PASS | Synthetic RSA key and operator flags only. |
| `php tests/offline.php` | 17 PASS | Synthetic locally generated encryption fixture, not a JangoPay notification. |
| PHP syntax `php -l` | All PHP files PASS | Static syntax only. |

Avoid double-counting the 14 Codex checks invoked by `session_race_recovery.php`. 1.0.3 adds a replayable `stale session overwritten` scenario, safe recoverability of deterministic HMAC capabilities, one-invoice reuse, legacy token fallback with strict hash matching, relevant Shop route blocking, unsafe session/cache driver rejection and weak APP_KEY rejection.

### Still NOT executed

- Laravel 12's **actual** StartSession saved-session lock behavior in an installed Azuriom environment, including route cache, alternate middleware order and lock timeouts.
- Database concurrency with two real PHP workers using shared session cookies; shared Redis or SQL cache lock and multi-host deployment.
- Existing 1.0.2 sessions that have *already lost* their original random token: these remain intentionally inaccessible without manual recovery.
- Authentic JangoPay invoice/transaction schema, encrypted notification payload, final invoice status, live checkout, and native in-game delivery.

**Status:** corrected candidate source, NOT a verified install or production payment gateway. Do not turn on merchant collections or market submission solely on these tests.

---

# TEST REPORT — 2026-10-14 (updated for 1.0.2 Codex remediation)

## Result status

**Candidate source implemented; syntax and offline synthetic tests passed.** This does **not** establish installability or correct receipt and fulfillment for live JangoPay payments. There is no authorized merchant key, game test environment, or official encrypted event vector attached in this session.

### Performed

| Item | Method | Result |
| --- | --- | --- |
| PHP version | `php -v` | PHP 8.4.24 CLI present |
| PHP syntax | `find . -name '*.php' -print0 \| xargs -0 -n1 php -l` | All PHP files syntax-valid |
| Money exact cents, formatting, underpayment mismatch | `php tests/offline.php` | PASS |
| Invalid/negative/zero/overprecision amount | `php tests/offline.php` | PASS |
| Allowed asset deduplication, unknown asset/invalid network rejection | `php tests/offline.php` | PASS |
| Test-only OAEP-SHA256 RSA key unwrap & AES-256-GCM decode | Locally generated OpenSSL key and synthetic GCM envelope, `php tests/offline.php` | PASS; **not a real webhook** |
| Tampered synthetic ciphertext/wrong event/untested tag format | `php tests/offline.php` | Rejected as expected |
| Cart fingerprint with alternate server variable/coupon | `php tests/offline.php` | PASS |
| State regression / CAS race simulation | `php tests/state_regression.php` | PASS (10 assertions; fake store only) |

**Offline helper test count: 17 passed; deterministic state-regression assertions: 10 passed.** These cover pure PHP helpers and a test-only envelope; database concurrency and real Shop delivery are not covered by them. The deterministic state test uses an in-memory simulated state store, not a database or parallel workers.

### Not run / blocked

| Requirement | Status | Reason |
| --- | --- | --- |
| Real Azuriom + Shop plugin ZIP installation and enable/disable | NOT RUN | No Azuriom test site or its database was provided |
| Shop cart → pending payment → invoice creation | NOT RUN | No JangoPay test API Key |
| `confirm-coin` quote from actual merchant assets | NOT RUN | Network list and merchant credentials unavailable |
| Authentic `payment.completed` envelope and decrypt | NOT RUN | No official tag format vector; default fail closed |
| Completed invoice/transaction merchant query and final status | NOT RUN | Formal final status enum/complete real responses not verified |
| Shop delivery of an actual item/rank/game command | NOT RUN | No sandbox game server/test package |
| Duplicate/concurrent webhooks, recovery and idempotent Shop delivery integration | NOT RUN | Real database/Shop environment absent |
| Unknown-create outcome, two-week pending-order purge and late incoming payment | NOT RUN | Requires staging setup and controlled time/failure injection |
| CSRF, session ownership and privilege escalation in installed Azuriom | NOT RUN | Code inspection only, no framework integration tests |
| Non-USD shop and subscription prevention on a live site | NOT RUN | Implemented code guards; no real site execution |
| User QR, phone UI screenshots, browser polling | NOT IMPLEMENTED | QR dependency and tested UI pending; address/value text only |

## 1.0.2 independent Codex-review regression tests

The user provided an independent Codex report and isolated reproduction of five remaining defects. Source code was changed, with deterministic repair assertions added in `tests/codex_regressions.php`. **These are not real database concurrency tests.**

| Execution | Result | Limits |
| --- | --- | --- |
| `php tests/codex_regressions.php` | 14 PASS (controlled fake store, simulated Fiber interleavings) | No actual Azuriom, SQL locks, or HTTP sessions |
| `php tests/readiness.php` | 6 PASS | Configuration gates only; no actual provider or valid webhook vector |
| `php tests/offline.php` | 17 PASS | Local synthetic key and encryption only |
| `php tests/state_regression.php` | 10 PASS | Fake storage and CAS semantics |

New regression coverage: guarded unknown-invoice recovery, matching Shop guest session and token, lost-quote read-only recovery (including a pending instruction), 21 pending-order fair rotation, and duplicate first-session checkout key avoidance. The mock transaction API models only the documented fields, and a real merchant response can still differ. See `CODEX_REVIEW_FIXES_2026-10-14.md`.

## 1.0.1 reviewer notes

See `CODE_REVIEW_2026-10-14.md` for resolved and outstanding blockers.

## Immediate staging checklist

- Provision Azuriom 1.x + Shop 1.2.25 on isolated HTTPS staging, create USD Shop and two sample products (standard and rank).
- Install extracted `jangopay` folder; run migrations; verify enabled, disabled and re-enabled gateway behavior.
- Provide test account key via server environment, verify `/admin/jangopay/payments` read-only connection and merchant asset list.
- Capture scrubbed invoice, merchant transaction, and encrypted webhook examples; update/verify API contract. Do not hardcode guessed final states.
- Use controlled small payment if JangoPay has no sandbox, by separate explicit operator approval. Confirm exact once delivery with repeated webhook and manual reconciliation.
- Test incorrect amount, wrong order/invoice, other merchant transaction, partial/late payment, API timeouts, quote unknown, replay, request authorization and concurrency against a real database.
- Verify that all four readiness markers are supplied by authentic staging contract evidence **before** setting `JANGOPAY_ENABLE_PAYMENTS=1` in staging; never bypass the gate with guessed values on a live domain.
- Confirm Shop's pending-record cleanup cannot silently lose an accepted payment; document manual handling.
- Confirm and implement invoice/payment status in UI, local QR generation, and any missing Shop packaging compatibility.

## Release gates

1. **Source candidate** — YES (source, documentation, archive, offline tests).
2. **Install verified in real Azuriom + Shop** — NO.
3. **Real payment / Shop item delivery verified** — NO.
4. **Ready for Azuriom Market submission** — NO.

No live credentials or merchant funds have been used. No deployment or market submission occurred.
