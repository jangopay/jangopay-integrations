# JangoPay for Azuriom Shop

**English** · [简体中文](README_ZH_CN.md) · [Back to plugins](../README.md)

Accept USDT and USDC for one-time, USD-priced orders in Azuriom Shop.

## Requirements

- An Azuriom site with Shop enabled and HTTPS available.
- A JangoPay merchant account with the required payment currencies and networks enabled.
- PHP and extensions that satisfy Azuriom and Shop requirements.
- OpenSSL CLI available to PHP, with `proc_open()` permitted.
- Persistent server-side sessions and a cache store that supports atomic locks.

Verified setup: Azuriom 1.2.13, Shop 1.2.25, PHP 8.5.11, SQLite and single-server file sessions/cache. That Azuriom version requires PHP `^8.2`; check your host's requirements before installation.

## 1. Install the plugin

1. Back up your site and database.
2. [Download v1.0.4](https://github.com/jangopay/jangopay-integrations/raw/refs/heads/main/downloads/jangopay-azuriom-1.0.4.zip).
3. Extract its contents into `plugins/jangopay/`. The resulting path must be `plugins/jangopay/plugin.json`.
4. Enable Shop, then JangoPay in Azuriom administration.

The install ZIP has no outer `jangopay/` folder. GitHub's repository ZIP is not the plugin installation package.

## 2. Connect your merchant account

Create a dedicated API key with invoice creation and order/transaction lookup permissions. The plugin does not need payout or exchange permissions.

Generate an RSA keypair for payment notifications. Keep the private key outside the public web directory and allow only the PHP service account to read it.

Configure the following in the actual environment of both the web process and reconciliation commands:

| Setting | Value |
| --- | --- |
| `JANGOPAY_API_KEY` | Your site's dedicated merchant API key |
| `JANGOPAY_RSA_PRIVATE_KEY_PATH` | Absolute path to the RSA private key |
| `JANGOPAY_FINAL_INVOICE_STATUSES` | `paid` |
| `JANGOPAY_WEBHOOK_TAG_FORMAT` | `appended16` |
| `JANGOPAY_ENABLE_PAYMENTS` | Start with `0`; set to `1` when ready to test |

These settings are read with `getenv()`. Adding them only to Laravel's `.env` may not expose them to PHP-FPM or scheduled commands; have your administrator verify the running processes.

In JangoPay Console → Developer settings → Callback configuration, register your RSA **public** key and the notification URL shown in the site's JangoPay settings. The usual URL is:

```text
https://your-site.example/api/shop/payments/jangopay/notification
```

Keep the API key and private key out of source control, gateway form fields, screenshots and support reports.

## 3. Set up the payment method

1. Use USD pricing in Shop.
2. Add a JangoPay payment gateway under Shop → Payment Gateways.
3. Configure allowed currencies and networks, such as `USDT:bsc`. Enable a pair only after confirming it is available to your merchant account.
4. Open `/admin/jangopay/payments` and run **Test API connection (read-only)**.

The connection test checks API access. A real payment is still required to verify notifications and fulfillment.

Use persistent server-side sessions. Cookie/array sessions and array/null lock stores cannot create new JangoPay invoices. Multi-server deployments require shared sessions and locks.

## 4. Test before going live

Enable payment creation for a controlled test order. Follow the exact cryptocurrency amount, network and address shown on the checkout page; do not infer the amount from the USD price.

Verify that the order completes automatically after the first official payment notification, without manually changing its state or using a synthetic notification. Confirm that Shop delivers the intended benefit once, and that duplicate notifications do not deliver it again.

**Version 1.0.4 is awaiting this final acceptance check.** One real USDT/BSC payment has been verified; its fulfillment completed after audited recovery and synthetic callback replay. USDC, other networks, real game-server delivery and production database/multi-server operation remain outside the verified scope. See [validation details](docs/VALIDATION.md).

## Managing payments

Review orders at `/admin/jangopay/payments`.

| Status | Action |
| --- | --- |
| `quoted` | Await payment using the displayed instructions. |
| `delivered` | Check that the customer received the purchased benefit. |
| `unknown_creation` / `quote_unknown` | Check the existing remote record before attempting another payment. |
| `manual_review` | Ask your administrator to investigate the payment details. |
| `delivery_started` / `delivery_attention` | Inspect actual delivery before retrying; fulfillment may have partly completed. |

Your administrator can schedule `php artisan jangopay:reconcile --limit=20` after testing. This queries existing payments and may trigger Shop fulfillment when verification succeeds.

Set `JANGOPAY_ENABLE_PAYMENTS=0` to stop creating new invoices. Existing payments can still be verified and fulfilled. Back up the database before upgrades and retain evidence for pending orders.

## Help

[Changelog](CHANGELOG.md) · [Validation](docs/VALIDATION.md) · [Report an issue](https://github.com/jangopay/jangopay-integrations/issues) · [License](LICENSE)
