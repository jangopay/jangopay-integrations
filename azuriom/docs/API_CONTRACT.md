# JangoPay × Azuriom API Contract — verified evidence and unresolved fields

Checked: 2026-10-14. This file distinguishes verified publicly readable implementation evidence from production features that **remain unverified**.

## Azuriom and Shop

- Shop source plugin version: **1.2.25**, `plugin.json` blob SHA `9f677e537105d78ea5629b4067a139cd7c6f13ac`, https://github.com/Azuriom/Plugin-Shop/blob/master/plugin.json
- Shop payment base `src/Payment/PaymentMethod.php`, blob SHA `271445c020c0e309714e6ae054288a842a1b6a08`: `startPayment(Cart, float, string)`, `notification(Request, ?string)`, `view()`, `rules()`, `createPayment`, `processPayment`, `supportsSubscriptions()`. URL: https://github.com/Azuriom/Plugin-Shop/blob/master/src/Payment/PaymentMethod.php
- Shop gateway registration through `payment_manager()->registerPaymentMethod('jangopay', JangoPayMethod::class)`. Shop API route `/api/shop/payments/{gateway:type}/notification/{id?}`, via Shop's `src/Controllers/Api/PaymentController.php` and `routes/api.php`.
- Shop `Payment::deliver()` marks `status=completed` before executing each item. Later failures may produce a completed record with incomplete game-server side effects. Shop `Payment::purgePendingPayments()` removes pending Shop payments older than two weeks. URL: https://github.com/Azuriom/Plugin-Shop/blob/master/src/Models/Payment.php
- Official plugin structure, base service providers, routes, migrations: https://azuriom.com/en/docs/plugins
- Dedipass sample: https://github.com/Azuriom/Plugin-DedipassPayment
- Actual **runtime Azuriom core version not checked**; compatibility with an installed instance is still pending.

## JangoPay publicly verified API fields

Verified against JangoPay official public SDK `JangoPay_SDK_PHP` (at known project commit `7928c1b3fdb2cbe0ff34004b9a5c030df8f802ce` stated in the original requirement; file blob SHA observed `4c47864f575d1cc606405655cffc70ce789c9399`) and its public documentation:

- Base URL: `https://api.jangopay.org`.
- `POST /api/v1/auth/apikey/token` with `Authorization: Basic <API Key>`. Merchant requests use `Authorization: Bearer <accessToken>`.
- `GET /api/v1/invoice/payment-assets`: read-only merchant asset list.
- `POST /api/v1/invoice/create`: sample fields `currencyCode: USD`, `amount: "10.00"`, `orderId`, `description`, `allowedCurrency: ["USDT:tron"]`, `coverPct:100`, `mixPay:false`, `autoToU:false`, `autoWithdraw:false`, `expiresAt`, `extraInfo`.
- `GET /api/v1/invoice/{invoiceId}`: merchant-side invoice query.
- `GET /api/v1/invoice/transaction/{txnId}`: merchant-side transaction query.
- `GET /api/v1/invoice/list`: merchant-side invoice list for manual unknown-create reconciliation.
- Public payer flow `GET /api/v1/invoice/{invoiceId}/pay-state` then `POST /api/v1/invoice/{invoiceId}/confirm-coin`. The SDK maps the resulting transaction's `txnId`, `receiveAddress`, `payAmount`, `payCurrency`, `chain`, `expiresAt` fields.
- SDK `getTransactionStatus()` uses a separate **public** endpoint and regards transaction `settled` as completed. This **does not** establish the final merchant invoice status.

Sources: https://github.com/JangoPayOrganization/JangoPay_SDK_PHP/blob/main/src/JangoPayClient.php ; https://github.com/jangopay/jangopay-docs/blob/main/README.zh-CN.md ; https://github.com/jangopay/jangopay-examples/blob/main/php/payment.php

This plugin intentionally implements only narrow HTTP calls rather than embedding the larger official SDK; the standard API paths and method behavior are derived from the versioned SDK above. No Composer dependencies are added to the host.

## Webhook evidence

From official public docs checked 2026-10-12:

```text
X-JangoPay-Event: payment.completed
X-JangoPay-Encryption-Alg: AES-256-GCM
X-JangoPay-Key-Encryption: RSA-OAEP-SHA256
```

The JSON envelope defines `event_type`, `algorithm`, `key_algorithm`, `encrypted_key` (base64), `nonce` (base64), and `ciphertext` (base64). RSA-OAEP-SHA256 decrypts a 32-byte AES key. The encryption alone does not establish payment, so the plugin also queries merchant invoice and transaction APIs before delivery.

Source: https://github.com/jangopay/jangopay-docs/blob/main/docs/zh-CN/webhooks.md

### Unresolved and intentionally disabled

| Area | Current evidence | Safe behavior |
| --- | --- | --- |
| Final **invoice** status values | Official pages/snippets do not give an authoritative enum or a redacted completed-invoice response | `JANGOPAY_FINAL_INVOICE_STATUSES` empty by default; no auto delivery |
| `payedAmount` monetary unit/precision and completed invoice response shape | Official SDK maps fields; no real value sample | Require exact original USD amount; unexpected precision or missing field fails closed |
| Merchant transaction lookup response shape and invoice ownership field | API method exists; no real completed transaction JSON sample | Require `txnId`, `invoiceId`, `status=settled`, matching asset/network; if missing, do not deliver |
| GCM tag placement and any AAD | Public doc explicitly says confirm tag framing from formal samples; this source has no test vector | `JANGOPAY_WEBHOOK_TAG_FORMAT` unset. Synthetic-only `appended16` implementation must be confirmed before activation |
| Invoice creation timeout / duplicate remote `orderId` | Public docs: orderId is **not** an idempotency key | Unknown results only read-only manual reconciliation; never blind retry |
| Actual asset+network combinations for merchant | Query exists, account-specific list absent | Admin must configure and test only confirmed pairs |
| Hosted checkout URL | No contract available for redirect URL | Use local payment instruction page and `confirm-coin` |
| Expired-after-payment and overpayment handling | Official docs warn exceptional funds may exist | Set `manual_review`; no auto refund, no automatic late delivery |

`doc.jangopay.org` could not be read in full during this development attempt, so its inaccessible pages are *not* represented as independently verified. No authentic transaction, notification or paid invoice example was available in the uploaded materials. Actual contract must be confirmed with redacted responses/test vectors.

## Planned real-data contract validation

1. On an isolated merchant account, list assets and confirm enabled USDT/USDC networks.
2. Create an invoice and record a fully redacted response (`invoiceId`, `orderId`, amount, currency, final status only).
3. Call `pay-state`, `confirm-coin`, and completed merchant invoice/transaction detail queries; verify the field casing, amount interpretation and final status.
4. Capture a redacted encrypted test notification (do **not** include private key). Generate an independent RSA/AES vector that matches the production tag framing and any AAD. Confirm callback HTTP response and retry behavior.
5. Only then set verified `JANGOPAY_FINAL_INVOICE_STATUSES` and `JANGOPAY_WEBHOOK_TAG_FORMAT` in a staging server.

## 1.0.2 request encoding and read-only quote recovery (2026-10-14)

The local HTTP adapter now converts **top-level** invoice-create camelCase parameter names to snake_case before submitting, matching `JangoPayClient::toSnakeCase()` in the public SDK. Nested `extraInfo` merchant metadata keys remain unchanged. This is a source-level equivalence check, **not proof of production server acceptance**.

`quote_unknown` is no longer an unrecoverable terminal. On `payment.completed` with a transaction ID (or on a bounded merchant `GET /api/v1/invoice/transactions` list search), the plugin fetches the authenticated merchant invoice and transaction, and verifies identity, amount, USD, allowed asset and network, settled transaction and operator-confirmed invoice final status. A matching transaction in merchant status `pending` may restore the exact displayed quote *only if* address and pay amount are also returned and match the selected asset/chain. Neither recovery path calls invoice/create or confirm-coin. The read-only transactions list is bounded to 300 entries; response structure and pagination/filter conventions require real API verification.

Checkout is now blocked before invoice creation unless `JANGOPAY_ENABLE_PAYMENTS=1`, a verified final invoice status allowlist, a confirmed webhook tag framing switch, and a readable RSA PEM private key are present. These are configuration conditions rather than a guarantee that the payment contract is validated.
