<div align="center">

# JangoPay Integrations

Accept USDT and USDC payments on your platform with JangoPay.

**English** · [简体中文](README.zh-CN.md)

[Website](https://www.jangopay.org/) · [Documentation](https://doc.jangopay.org/api-reference) · [Support](https://github.com/jangopay/jangopay-integrations/issues)

</div>

## Plugins

| Plugin | Version | Payments | Guide |
| --- | --- | --- | --- |
| [WooCommerce](woocommerce/) | 1.0.2 | USDT / USDC · USD pricing | [WordPress.org](https://wordpress.org/plugins/jangopay-for-woocommerce/) · [Setup →](woocommerce/docs/SETUP.md) |
| [Azuriom Shop](azuriom/) | 1.0.4 | USDT / USDC · USD pricing | [Azuriom Market](https://market.azuriom.com/resources/256) · [Installation →](azuriom/README_EN.md) |

Available currencies and networks depend on your JangoPay merchant account.

## Get started

**WooCommerce merchants:** [Get JangoPay on WordPress.org](https://wordpress.org/plugins/jangopay-for-woocommerce/) or [read the merchant setup guide](woocommerce/docs/SETUP.md). The Azuriom steps below apply only to Azuriom stores.

1. [Download the Azuriom plugin](https://github.com/jangopay/jangopay-integrations/raw/refs/heads/main/downloads/jangopay-azuriom-1.0.4.zip).
2. Extract the ZIP into your site's `plugins/jangopay/` directory.
3. Follow the [installation guide](azuriom/README_EN.md) to connect your merchant account and test a payment.

Use the plugin ZIP for installation. GitHub's **Download ZIP** contains the entire repository.

> **Before going live:** Final acceptance for Azuriom 1.0.4 is pending. Verify that a new order is fulfilled automatically after the first official payment notification. See [validation details](azuriom/docs/VALIDATION.md).

<details>
<summary>Verify your download</summary>

SHA-256 of `jangopay-azuriom-1.0.4.zip`:

```text
10BCDC245302F7068CF26256DA7CB88EF16EC930CE73B26C07D6942A9F2B6054
```

</details>

## Help & updates

- [Report an issue](https://github.com/jangopay/jangopay-integrations/issues) — include your platform and plugin version. Keep API keys, private keys and passwords out of reports.
- [Azuriom changelog](azuriom/CHANGELOG.md) — version history and changes.

## License

Each plugin includes its own license and third-party notices. See [license information](LICENSE.md).
