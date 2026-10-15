# V1.0.3 — Session race audit and remediation

Date: 2026-10-14. Original report: `jangopay-azuriom-1.0.2-independent-recheck-20261014.md` (separately supplied), original repro: `jangopay-azuriom-1.0.2-session-review-reproduction.zip` (separately supplied).

## Confirmed root cause

`PaymentMethod::startPayment()` ran inside Shop's web request before Laravel finished saving the session. Two requests read the same old session. The losing request could finish last and write its stale snapshot after the winner saved a new nonce, access token and empty cart. The DB unique index blocked duplicate invoices but could not protect this session snapshot. Running the unchanged `extended_review.php` from the uploaded repro confirmed the old code's failure in simulation.

## Changes

- `src/Providers/JangoPayServiceProvider.php` registers `RouteMatched` listener before dispatch; helper `src/Support/ShopSessionBlocking.php` marks the four upstream Shop payment start routes with `Route::block(120, 45)`. Laravel 12 source confirms this is consumed in `StartSession::handle()` and the lock is released after `handleStatefulRequest()` (which saves session). Without this approach, locking within `startPayment` alone would release too early. The plugin does NOT modify Shop core routes.
- `src/Support/CheckoutAccess.php` uses domain-separated HMAC-SHA256 with Laravel's strong `APP_KEY` and a 64-hex checkout key. `startPayment` stores only SHA-256(token), and uses the same derivation on retry. It no longer uses session as the single source for new payment capabilities. The original cart/identity/amount/gateway/session checkout-key derivation and 1.0.2 unique attempt index are preserved.
- On DB reservation conflict, read the winning attempt using the same checkout key; return its original URL token only when its stored hash matches. This does not issue an additional upstream request. Historic random tokens are accepted only when their original session value hashes to the persisted access hash; **never** reissue arbitrary new tokens to access existing attempts.
- Reject cookie/array session drivers and process-local array/null lock stores before invoice creation. On hosts using unsupported stores, route modification is skipped to avoid breaking other payment methods.

## Test evidence

Baseline from user's original repro: `php extended_review.php` printed `REPRODUCED` for late stale session overwrite and subsequent unrecoverable checkout. Against new source, `php tests/session_race_recovery.php` simulates the exact stale snapshot being persisted AFTER a successful checkout, then reuses the recovered attempt/capability, removes old cart and verifies one invoice. Additional checks exercise Shop route-name coverage and legacy token safety. All original offline regressions remain passing. This does NOT test actual Laravel middleware, SQL row locking, external game commands, or real credentials.

## Integration acceptance (Codex)

1. In real Azuriom + Shop, enable a server-side session driver and an atomic lock cache shared across all application nodes. Confirm actual Shop route names and listener behavior using `php artisan route:list`; inspect `locksFor()` for matched Shop routes.
2. With the SAME session cookie and initial cart, send two concurrent GET `/shop/payments/payment` requests (when auto-selected) and separately POST `/shop/payments/{gateway}/pay` requests. Delay winner before return and loser before session save. Check serialized session writes, one Shop pending payment, one plugin attempt, and only one `POST invoice/create`.
3. Race checkout against a separate Shop cart/page request that is not locked. Confirm the old session overwrite cannot permanently lose a valid new checkout URL; a retry should resume the same unpaid invoice. Re-add identical goods intentionally after original payment completion and confirm a new paid checkout can be started.
4. Verify cookie/array session and process-local array cache configurations fail closed for JangoPay without harming alternate Shop gateways. Configure multi-host file vs shared Redis/DB lock stores explicitly.
5. Migrate 1.0.2 existing sessions with saved random tokens and confirm the URL still works; ensure missing legacy token is NOT recreated automatically. Rotate APP_KEY only with audited operational plan.
6. Do not call the live merchant, confirm-coin or accept payments until the separate authentic API contract/Webhook/live-fulfillment blockers are satisfied.

## Primary technical references

- Laravel 12 `Illuminate/Routing/Router.php`: `RouteMatched` dispatch occurs before the route middleware stack.
- Laravel 12 `Illuminate/Session/Middleware/StartSession.php`: `locksFor()` causes `handleRequestWhileBlocking`, which calls `handleStatefulRequest` before releasing its lock.
- Laravel 12 `Illuminate/Routing/Route.php`: `block($lockSeconds, $waitSeconds)`.
- Azuriom Plugin-Shop `routes/web.php` and `PaymentController.php`: actual Shop checkout entrypoints.
- Original reproduction is user-supplied and represents isolated simulation, not live Laravel behavior.
