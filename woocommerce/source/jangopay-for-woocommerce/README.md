# JangoPay for WooCommerce

[简体中文](README.zh-CN.md) · [Documentation](https://doc.jangopay.org/documentation) · [Support](https://www.jangopay.org/zh/about)

**V1.0 · Initial public release**

Accept USDT and USDC for one-time USD WooCommerce orders through JangoPay hosted checkout. Supports classic checkout, Checkout Blocks, CPT and HPOS.

## Merchant setup

Use HTTPS, WordPress 6.4+, WooCommerce 8.3+, PHP 8.1+ and PHP OpenSSL. Validate your actual hosting combination. An eligible JangoPay account and appropriate API permissions are required.

1. Back up the staging store. Install the submission ZIP; the full developer bundle is not an install package.
2. Add the following server-side configuration before WordPress loads. Supply the environment variable through your hosting secret configuration, replace the example key path, and confirm the appended16 notification format with JangoPay.

```php
define('JANGOPAY_WC_API_KEY', getenv('JANGOPAY_WC_API_KEY'));
define('JANGOPAY_WC_RSA_PRIVATE_KEY_PATH', '/private/jangopay-webhook.pem');
define('JANGOPAY_WC_WEBHOOK_FRAMING', 'appended16');
define('JANGOPAY_WC_ENABLE_COLLECTION', true);
```

The private key must be outside WordPress and the HTTP document root, readable only by the appropriate server account. Do not commit keys or include credentials in support requests. RSA-OAEP uses SHA-256 for both OAEP and MGF1; the scoped phpseclib dependency is bundled, so Composer and an OpenSSL executable are not needed on the merchant's host.

3. Open **WooCommerce → Settings → Payments → JangoPay**. Enable collection only on the intended store. Select account-enabled pairs such as `USDT:bsc` and configure the displayed HTTPS callback in the merchant console.
4. Run the connection test. Use a staging order for a genuine hosted payment; check API verification, encrypted callback, duplicate notifications and recovery before live use.

## Operation

The browser return is not payment proof. Keep uncertain invoices for read-only recovery and manual review; never delete their ledger to force a new invoice. Use the order's JangoPay re-verification control when appropriate. WordPress cron runs reconciliation hourly; configure dependable cron execution on low-traffic stores.

Only one-time USD orders are supported. No subscriptions, automatic gateway refunds, payouts or other fiat order currencies. Manual WooCommerce refunds do not return cryptocurrency. Deactivation clears the schedule and retains ledger/order data.

For development-package users: deactivate the old development plugin before activating this public package. The folder/text domain is `jangopay-for-woocommerce`; the `jangopay_woocommerce` gateway ID, configuration constants and ledger table remain unchanged. Do not run both copies simultaneously. Back up first; unresolved historical invoices without item snapshots need manual review.

## External service and privacy

See **External services** in [readme.txt](readme.txt) for exact hosts, all request triggers, credentials, order-key return links and browser processing. No customer email, addresses or product line items are included in invoice creation. The private key stays local; the API key is sent to the merchant API for authentication over HTTPS.

[Terms](https://www.jangopay.org/en/legal/terms) · [Privacy](https://www.jangopay.org/en/legal/privacy)

## Source and directory assets

The full source bundle contains tests and build instructions. The WordPress submission ZIP contains runtime files, required crypto dependencies and licenses. No test scripts, directory icons/banners/screenshots, .po or .mo files are included. Directory materials belong in the separate SVN top-level `assets/`, beside `trunk/`; local checkout/settings logos remain runtime assets.

GPL-2.0-or-later; bundled crypto components retain their MIT notices. JangoPay branding identifies the service; no WordPress or WooCommerce endorsement is implied.
