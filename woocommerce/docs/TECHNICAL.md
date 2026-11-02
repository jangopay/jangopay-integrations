# Technical and release notes · 1.0.2

[Product overview](../README.md) · [简体中文](TECHNICAL.zh-CN.md)

## Architecture

The plugin uses the WordPress HTTP API to communicate with `api.jangopay.org` over HTTPS. Buyers complete payment on hosted checkout at `app.jangopay.org`. The store decrypts notifications locally and verifies invoice/transaction records through the merchant API before updating WooCommerce orders.

Supports classic checkout, Checkout Blocks, CPT and HPOS. Declared minimums are WordPress 6.4, WooCommerce 8.3 and PHP 8.1 with PHP OpenSSL. Validate the actual host combination.

## Source map

| Path | Purpose |
| :--- | :--- |
| `source/jangopay-for-woocommerce/includes/` | API, gateway, controller, verification, ledger and webhook logic |
| `source/jangopay-for-woocommerce/assets/` | Runtime scripts, styles and branding |
| `source/jangopay-for-woocommerce/tests/` | PHP regressions and fixtures |
| `source/qa/` | Offline regression runner and frontend assertions |
| `source/build/` | Original runtime/build dependency locks |
| `source/upgrade/phpseclib4/` | Isolated build and namespace correction |

Browsable files include core implementation, tests and build inputs. **The complete delivered vendor tree is included in the [original full-source bundle](../../downloads/jangopay-woocommerce-1.0.2-source-tests.zip).** The archive is preserved as supplied. The separate WordPress installation ZIP bundles its runtime dependencies.

## API and fulfillment proof

An API Key obtains an external access token for invoice creation, asset queries, invoice/transaction checks and read-only recovery. HTTPS verifies certificates, disables redirects, and bounds response size and timeouts.

Fulfillment requires a paid invoice, its settled transaction, the exact merchant reference, USD amount, allowed asset/network and an unchanged order/items snapshot. Amount checks use integer decimal units. Uncertain creation requests use read-only recovery rather than blind POST retries.

See [API_CONTRACT.md](../source/jangopay-for-woocommerce/docs/API_CONTRACT.md).

## Encrypted notifications

The event is `payment.completed`. RSA-OAEP uses SHA-256 for both OAEP and MGF1. AES-256-GCM uses a 12-byte nonce and a trailing 16-byte authentication tag in ciphertext; confirm and explicitly configure `appended16`.

Version 1.0.2 bundles phpseclib 4.0.2 under the private `JangoPayWooVendor\\phpseclib4` prefix. Decryption runs in PHP without an external OpenSSL executable. Notifications provide hints for authenticated verification rather than standalone fulfillment proof.

## Build and licensing

Merchant runtime requires PHP ≥8.1. The locked build toolchain requires PHP ≥8.4.1. Do not run Composer install over the plugin's scoped vendor directory. Follow [BUILD.md](../source/BUILD.md) for an isolated rebuild, retaining lock files, namespace isolation and notices.

Runtime dependencies: phpseclib 4.0.2, constant_time_encoding v3.1.3 and polyfill-php82 v1.43.0. The plugin is GPL-2.0-or-later and dependencies retain MIT notices; see [NOTICES](../source/jangopay-for-woocommerce/NOTICES.txt).

## Maintenance

Gateway ID: `jangopay_woocommerce`. Deactivation clears reconciliation scheduling and retains order/ledger records. Fees, regions and merchant eligibility follow platform policies.

For support include platform/plugin versions, redacted identifiers and reproduction steps. Do not share keys, complete order-key URLs, customer information or database backups in public issues.
