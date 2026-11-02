# JangoPay × Azuriom API contract

This document describes the Azuriom Shop integration's payment flow and server-side requirements.

## Azuriom and Shop

- Register the payment method with `payment_manager()->registerPaymentMethod('jangopay', JangoPayMethod::class)`.
- Shop receives notifications at `/api/shop/payments/{gateway:type}/notification/{id?}`.
- Keep persistent server-side sessions and an atomic-lock-capable cache. Multi-node installations need shared sessions and locks.
- Fulfillment delegates to Shop. If an external game command partly completes, inspect the actual delivery before retrying.
- Preserve payment records when handling Shop's pending-record cleanup.

## JangoPay requests

Base URL: `https://api.jangopay.org`.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/api/v1/auth/apikey/token` | Exchange API Key for an external access token |
| GET | `/api/v1/invoice/payment-assets` | Query assets and networks |
| POST | `/api/v1/invoice/create` | Create a USD invoice |
| GET | `/api/v1/invoice/{invoiceId}` | Verify an invoice |
| GET | `/api/v1/invoice/transaction/{txnId}` | Verify its transaction |
| GET | `/api/v1/invoice/list` | Read-only invoice recovery |
| GET | `/api/v1/invoice/transactions` | Bounded transaction discovery |
| GET / POST | `/api/v1/invoice/{invoiceId}/pay-state` / `confirm-coin` | Obtain payment instructions |

Authentication uses `Authorization: Basic <API Key>`; merchant business calls use `Authorization: Bearer <accessToken>`. Empty authentication POST bodies are JSON objects.

The adapter converts top-level invoice-create parameter names to snake_case, following the JangoPay PHP SDK. Nested merchant metadata keys remain unchanged. USD values use decimal strings and exact arithmetic rather than floating-point rounding.

The payment instruction page uses the quoted cryptocurrency amount, network and receive address. It does not infer token quantity from the USD order price.

## Notification envelope

```text
X-JangoPay-Event: payment.completed
X-JangoPay-Encryption-Alg: AES-256-GCM
X-JangoPay-Key-Encryption: RSA-OAEP-SHA256
```

JSON contains Base64-encoded `encrypted_key`, `nonce` and `ciphertext`. Optional event/algorithm identifiers must agree with the headers. RSA-OAEP-SHA256 unwraps the AES key. Configure `appended16` framing for the trailing GCM authentication tag.

Successful decryption supplies lookup hints. The plugin queries merchant invoice and transaction APIs before fulfillment; a notification or browser return alone is not payment proof.

## Server configuration

| Setting | Purpose |
| --- | --- |
| `JANGOPAY_API_KEY` | Dedicated merchant API Key |
| `JANGOPAY_RSA_PRIVATE_KEY_PATH` | Server-only readable RSA private key |
| `JANGOPAY_FINAL_INVOICE_STATUSES` | Merchant final invoice status; current setup uses `paid` |
| `JANGOPAY_WEBHOOK_TAG_FORMAT` | `appended16` |
| `JANGOPAY_ENABLE_PAYMENTS` | Explicit collection switch |

Configure enabled asset/network pairs per gateway. Each attempt retains its allowlist snapshot. Keep API keys and private keys outside source control and browser pages.

## Verification and recovery

Require the matching merchant invoice, original order reference, USD total, settled transaction, asset/network and ownership before invoking Shop fulfillment. Merchant-paid fees must satisfy explicit gross/fee/net arithmetic.

Invoice creation timeouts enter read-only recovery. Do not blindly repeat invoice creation: `orderId` is not an idempotency key. Transaction discovery is bounded; ambiguous records go to manual review.

Read-only quote recovery can restore payment instructions only when the returned invoice, selected asset, address and pay amount match. Recovery does not create another invoice or quote. Conditional transitions and durable delivery claims prevent late responses from resetting in-flight or terminal records.

Expired, ambiguous and partly fulfilled records require merchant review rather than automatic refunds or repeated external game commands.

[Installation](../README_EN.md) · [中文商户教程](MERCHANT_GUIDE_ZH_CN.md) · [Official API reference](https://doc.jangopay.org/api-reference)
