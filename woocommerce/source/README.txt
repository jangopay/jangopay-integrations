JangoPay for WooCommerce 1.0.2 — full source and local validation

Upload the separate 01 WordPress ZIP. This developer bundle is not an installation ZIP.

jangopay-for-woocommerce/: runtime, private phpseclib 4.0.2 dependencies, developer docs and tests.
build/: original Composer dependency/build-tool locks.
upgrade/phpseclib4/: isolated build and namespace correction. See BUILD.md.
qa/: offline regression runners.
validation/: genuine local build/test evidence with phase labels.

Build tools require PHP >=8.4.1. Store runtime requires PHP >=8.1. Development tests need OpenSSL CLI, Python and Node.js; store decryption needs PHP OpenSSL, with no process execution.

Use the separate install ZIP for WordPress. Preserve server-side credentials, private key paths and existing payment records during upgrades.
