# JangoPay for Azuriom Shop — market listing

[View on Azuriom Market](https://market.azuriom.com/resources/256)

Accept USDT and USDC payments for one-time, USD-priced Azuriom Shop orders. Customers follow payment instructions for the networks enabled by the merchant.

## Features

- Azuriom Shop checkout and native fulfillment.
- Exact cryptocurrency amount, network and receiving address.
- Authenticated merchant API verification before fulfillment.
- RSA-OAEP-SHA256 and AES-256-GCM encrypted notifications.
- Persistent tracking, duplicate-processing protection and administrator reconciliation.

## Requirements

An Azuriom site with Shop and HTTPS, an eligible JangoPay account, PHP OpenSSL support, accessible OpenSSL CLI and `proc_open()`. Use persistent server-side sessions and a cache supporting atomic locks. Follow the PHP requirements of the installed host framework and Shop version.

Configure the merchant API Key, server-only RSA private key, callback public key, USD pricing and enabled asset/network pairs. Subscriptions, automatic refunds, payouts and exchange are outside the plugin's scope.

[Product overview](../README.md) · [中文介绍](../README.zh-CN.md) · [Installation](../README_EN.md) · [contact@jangopay.org](mailto:contact@jangopay.org)
