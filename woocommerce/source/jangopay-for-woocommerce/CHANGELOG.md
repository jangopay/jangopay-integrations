# Changelog

## 1.0.2

Rebuild privately scoped phpseclib to 4.0.2 (PHP 8.1+), keeping RSA-OAEP-SHA256 and AES-256-GCM webhook verification. Regressions must pass on the actual rebuilt v4 dependency.

## 1.0.1

Use WordPress enqueue APIs for all plugin-owned frontend and admin scripts/styles. Restrict JangoPay notices to the gateway settings screen; retain existing payment flow and isolated cryptography.

## 1.0

Initial public release of JangoPay for WooCommerce.

