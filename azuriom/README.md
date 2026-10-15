<div align="center">

# JangoPay for Azuriom Shop

Accept USDT and USDC payments in your Azuriom store.

[English guide](README_EN.md) · [中文教程](README_ZH_CN.md)

[Download v1.0.4](https://github.com/jangopay/jangopay-integrations/raw/refs/heads/main/downloads/jangopay-azuriom-1.0.4.zip) · [Changelog](CHANGELOG.md) · [Support](https://github.com/jangopay/jangopay-integrations/issues)

</div>

## What it does

- Accept one-time payments for USD-priced Shop orders.
- Let customers pay using the USDT / USDC networks enabled for your merchant account.
- Show the payment amount, network and address at checkout.
- Confirm payments with JangoPay before fulfilling orders through Shop.
- Review payment status and reconcile orders from your site.

Subscriptions, automatic refunds, payouts and currency conversion are not included.

## Installation

Extract the plugin ZIP into `plugins/jangopay/`, then follow the [English installation guide](README_EN.md) or [中文商户教程](docs/MERCHANT_GUIDE_ZH_CN.md).

> **Acceptance pending:** Before going live, verify a new order completing automatically after its first official payment notification. See [validation details](docs/VALIDATION.md) for the completed tests and remaining checks.

## License

[MIT](LICENSE). See [third-party notices](docs/THIRD_PARTY_LICENSES.md) for included upstream material.
