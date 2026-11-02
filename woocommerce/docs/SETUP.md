# Merchant installation and operation · 1.0.2

[Product overview](../README.md) · [简体中文](SETUP.zh-CN.md)

## 1. Prepare your store

Use HTTPS, WordPress 6.4+, WooCommerce 8.3+, PHP 8.1+ and the PHP OpenSSL extension. Set order currency to USD and use one-time orders. Prepare an eligible JangoPay merchant account with appropriate API permissions. Back up the staging database, plugin and payment ledger before installation or upgrades.

## 2. Install

Search for **JangoPay for WooCommerce** in WordPress, or visit the [official plugin directory](https://wordpress.org/plugins/jangopay-for-woocommerce/).

For manual installation, upload the [1.0.2 install ZIP](../../downloads/jangopay-woocommerce-1.0.2.zip) through Plugins → Add New → Upload Plugin, then activate it. The full development bundle and entire GitHub repository ZIP are not installation packages.

## 3. Configure server credentials

Store the API Key through your host's environment or secret configuration. Generate an RSA key pair, keep the private key outside both WordPress and the HTTP document root, and restrict read access to the necessary server account. Configure the public key in JangoPay Console.

Set these constants before WordPress loads. Replace the example path and confirm the merchant's appended16 notification framing. Enable collection only on the intended, ready store.

```php
define('JANGOPAY_WC_API_KEY', getenv('JANGOPAY_WC_API_KEY'));
define('JANGOPAY_WC_RSA_PRIVATE_KEY_PATH', '/private/jangopay-webhook.pem');
define('JANGOPAY_WC_WEBHOOK_FRAMING', 'appended16');
define('JANGOPAY_WC_ENABLE_COLLECTION', true);
```

The installation ZIP bundles scoped crypto dependencies. Merchant hosting does not need Composer or a command-line OpenSSL executable, but does require the PHP OpenSSL extension. Keep API keys, private keys and order-key return URLs out of public issues.

## 4. Enable JangoPay

Open **WooCommerce → Settings → Payments → JangoPay**.

1. Configure the payment title and description.
2. Select account-enabled asset/network pairs, such as `USDT:bsc`.
3. Save the displayed HTTPS callback URL and matching public key in Console.
4. Run the API connection test and enable the payment method after confirming configuration.

## 5. Validate payment

Complete a genuine hosted payment with a staging one-time USD order. Verify the store return, encrypted callback, authenticated merchant checks and order updates. Repeated notifications must not repeat fulfillment. Test invoice timeouts, missing notifications and recovery from temporary network failures.

A browser return does not prove payment. The plugin checks amounts, assets and the original order/items snapshot; retain uncertain records for reconciliation.

## Operation and upgrades

- WordPress cron reconciles outstanding records hourly. Configure dependable cron execution for low-traffic stores.
- Use the order's JangoPay re-verification control for investigation.
- Do not delete uncertain ledger records to force invoice recreation.
- Deactivation removes the schedule and retains order/ledger data.
- Deactivate older development copies before enabling this package. The gateway ID remains `jangopay_woocommerce`.
- Manual WooCommerce refunds do not send cryptocurrency. Arrange any actual return of funds separately.

## Data and external services

Invoice creation sends the merchant reference, USD amount, allowed assets, minimal order ID metadata and a return URL containing the WooCommerce order key. It does not send customer email, billing/shipping addresses or product line items in invoice creation. Authentication sends the API Key to the merchant API over HTTPS; the private key stays local.

Buyers interact directly with hosted checkout on `app.jangopay.org`. See the original [readme.txt](../source/jangopay-for-woocommerce/readme.txt) for exact request triggers and data disclosure.

[Terms](https://www.jangopay.org/en/legal/terms) · [Privacy](https://www.jangopay.org/en/legal/privacy) · [WordPress support](https://wordpress.org/support/plugin/jangopay-for-woocommerce/)
