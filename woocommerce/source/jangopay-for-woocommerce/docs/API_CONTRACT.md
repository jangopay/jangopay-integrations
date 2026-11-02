# Runtime API contract

The integration sends HTTPS requests through the WordPress HTTP API to https://api.jangopay.org with certificate verification, no HTTP redirects, bounded responses and timeouts. Current provider documentation: https://doc.jangopay.org/documentation. This file describes what this plugin verifies; it does not establish a provider completeness or availability guarantee.

## Requests

- POST /api/v1/auth/apikey/token: empty JSON object, Basic API credential; receive access token.
- GET /api/v1/invoice/payment-assets: merchant-enabled assets/networks.
- POST /api/v1/invoice/create: reserved order reference, USD amount, immutable allowed_currency pairs, minimal metadata and sensitive return URL. An uncertain POST is not blindly repeated.
- GET /api/v1/invoice/{id}, /api/v1/invoice/transaction/{id}, /api/v1/invoice/list: authenticated proof and read-only recovery.
- GET /api/v1/invoice/transactions: bounded legacy discovery with explicit pagination completeness checks. Uncertain, incomplete or ambiguous results require manual review.
- Legacy quote recovery uses pay-state GET and confirm-coin POST with a payment session. New buyer flows use https://app.jangopay.org hosted checkout.

## Fulfillment proof

Require an authenticated paid invoice, settled transaction bound to that invoice, exact merchant order reference, USD amount, allowed asset/network and the unchanged WooCommerce order/items snapshot. Fee deduction requires explicit merchant-paid fee fields and exact gross = fee + net arithmetic; do not infer missing fields or accept browser returns as proof. Amount checks use integer decimal units.

Linked transaction candidates are read from the full authenticated invoice response. Candidate IDs are individually verified. The formal API's guarantee of a complete transaction list remains an external acceptance item; if completeness or uniqueness cannot be established the plugin does not automatically deliver.

## Encrypted notification

Required headers: X-JangoPay-Event: payment.completed; X-JangoPay-Encryption-Alg: AES-256-GCM; X-JangoPay-Key-Encryption: RSA-OAEP-SHA256. JSON contains encrypted_key, nonce and ciphertext; optional duplicate event/algorithm identifiers must agree. RSA OAEP and MGF1 both use SHA-256 via the privately scoped phpseclib library. AES-256-GCM uses a 12-byte nonce and the final 16 ciphertext bytes as the tag. This framing requires explicit appended16 configuration. The notification is size-bounded and only supplies hints for authenticated merchant verification.

Synthetic interoperability, downgrade rejection and tamper tests validate implementation behavior. They do not replace genuine hosted-payment and provider HTTPS callback acceptance for the merchant's account.
