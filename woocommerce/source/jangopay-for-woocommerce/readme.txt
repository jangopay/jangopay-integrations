=== JangoPay for WooCommerce ===
Contributors: hijangopay
Tags: woocommerce, payments, usdt, usdc, stablecoin
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 8.3
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDT and USDC through JangoPay hosted checkout for USD WooCommerce orders.

== Description ==

JangoPay for WooCommerce adds a stablecoin payment method for one-time USD orders. Customers continue to JangoPay hosted checkout to select an account-enabled asset and network and complete payment.

* Classic checkout and WooCommerce Checkout Blocks, with local JangoPay branding.
* Traditional order storage (CPT) and High-Performance Order Storage (HPOS).
* Merchant-configurable checkout text and enabled assets/networks.
* Authenticated invoice and transaction checks, encrypted notifications and reconciliation.
* Local RSA-OAEP-SHA256 / AES-256-GCM notification decryption, without an OpenSSL executable or process execution.

One-time USD orders only. Subscriptions, automatic gateway refunds, payouts, exchange services and other fiat order currencies are not supported. WooCommerce manual refunds are local bookkeeping; they do not send cryptocurrency.

= External services =

This plugin requires an eligible JangoPay merchant account. It uses WordPress HTTP APIs over verified HTTPS with redirects disabled; it does not use AI services, telemetry, remote scripts or remote fonts.

1. Merchant API: https://api.jangopay.org
When: enabled checkout creates an invoice; a customer explicitly resumes a safely retryable invoice; the merchant runs the connection test or re-verification; a store return or encrypted webhook triggers verification; the hourly WordPress cron reconciles outstanding ledger records. Connection tests also request supported assets. Authentication can occur before these requests.
Data: the API key is sent server-to-server as the authentication credential and exchanged for a bearer token. Invoice creation sends a merchant order reference, USD amount, enabled asset/network pairs, minimal local order ID metadata and the HTTPS return URL. This URL contains the WooCommerce order key, which grants access to that order's payment page. Read-only recovery and verification send invoice/transaction/reference IDs and pagination parameters. Retained legacy quote recovery can also send asset, network and payment-session fields. The API sees the store server IP and normal HTTPS request metadata. The plugin does not include customer email, billing/shipping addresses or product line items in invoice creation. The API key and RSA private key are never embedded in buyer pages; the private key is not sent to JangoPay.

2. Hosted payment page: https://app.jangopay.org
When: a buyer follows the payment redirect. The invoice ID is included in the hosted URL. The buyer communicates directly with JangoPay, whose page may collect payment information and receives the buyer's IP and browser request metadata under its policies. This plugin does not control the hosted page's separate processing. Store return links must be treated as sensitive.

JangoPay sends an encrypted payment notification to the merchant-configured store callback. The store decrypts locally and verifies invoice and transaction data using the merchant API before fulfillment. Browser return parameters or notification hints alone do not confirm payment.

Service/network fees and merchant eligibility follow JangoPay policies. Review them before enabling collection:
* Service: https://www.jangopay.org/
* Documentation: https://doc.jangopay.org/documentation
* Terms of service: https://www.jangopay.org/en/legal/terms
* Privacy policy: https://www.jangopay.org/en/legal/privacy
* Official support: https://www.jangopay.org/zh/about

== Installation ==

1. Back up the store and payment ledger. Install WooCommerce and configure HTTPS.
2. Upload the submission/install ZIP in Plugins > Add New and activate JangoPay for WooCommerce.
3. Set JANGOPAY_WC_API_KEY and JANGOPAY_WC_RSA_PRIVATE_KEY_PATH in server-side configuration. The RSA private key must be readable by PHP and outside both WordPress and the HTTP document root.
4. Confirm the merchant notification format is appended16 before setting JANGOPAY_WC_WEBHOOK_FRAMING to appended16. Set JANGOPAY_WC_ENABLE_COLLECTION to true only on the intended store.
5. Open WooCommerce > Settings > Payments > JangoPay. Select only account-enabled asset/network pairs and enable the gateway. Configure the displayed HTTPS webhook URL in the merchant console.
6. Run the connection test, then validate a real hosted payment, return, encrypted callback, duplicate notification and recovery on staging before production collection.

PHP OpenSSL is required; no command-line OpenSSL program is required. The declared minimum versions should be validated on the merchant's actual hosting combination. Add server-side configuration before WordPress loads, using your host secret configuration and a private key path outside the public directory:

`define('JANGOPAY_WC_API_KEY', getenv('JANGOPAY_WC_API_KEY'));`
`define('JANGOPAY_WC_RSA_PRIVATE_KEY_PATH', '/private/jangopay-webhook.pem');`
`define('JANGOPAY_WC_WEBHOOK_FRAMING', 'appended16');`
`define('JANGOPAY_WC_ENABLE_COLLECTION', true);`

The example path must be replaced; confirm the format and merchant configuration before enabling collection.

== Frequently Asked Questions ==

= Does returning to the store prove payment? =
No. Authenticated merchant verification is required before fulfillment.

= Where do customers select the coin and network? =
On JangoPay hosted checkout. The store status page does not issue another quote or payment address.

= Does a manual refund return cryptocurrency? =
No. It records a local WooCommerce refund and can restore stock. Arrange any actual funds return separately.

= What if invoice creation times out? =
Keep the ledger for read-only recovery or manual review. Never delete an uncertain record and blindly create another invoice.

= What does disabling or uninstalling retain? =
Deactivation stops the reconciliation schedule. Order and payment ledger data are retained for accounting and recovery. Private keys remain in the server location selected by the merchant. Remove sensitive records only under a deliberate retention policy and backup plan.

== Screenshots ==

1. Merchant settings, local branding and documentation/policy links.
2. JangoPay in classic WooCommerce checkout.
3. JangoPay in WooCommerce Checkout Blocks.

== Changelog ==

= 1.0.2 =
Rebuild privately scoped phpseclib 4.0.2 and verify RSA-OAEP-SHA256 / AES-GCM on PHP 8.1 and the build runtime.

= 1.0.1 =
WordPress.org review fixes: use the WordPress asset loader for isolated checkout and admin scripts/styles; scope gateway notices to the JangoPay payment settings screen. No changes to the gateway payment protocol or ledger.

= 1.0 =
Initial public release.

