# Code map

- jangopay-for-woocommerce.php: public version, metadata, activation, scheduled task and WooCommerce/Blocks integration.
- includes/class-jpwc-gateway.php: settings, availability, payment entry and unique invoice creation.
- includes/class-jpwc-blocks.php and assets/js/blocks.js: server/client Checkout Blocks integration; plain JavaScript needs no build step.
- includes/class-jpwc-controller.php: credentialed status/return pages, encrypted callbacks, merchant re-verification and scheduled recovery.
- includes/class-jpwc-ledger.php: unique payment ledger, validated identifiers/columns, conditional atomic state transitions.
- includes/class-jpwc-order-guard.php and class-jpwc-guarded-order.php: shared order/item write locks and guarded order storage.
- includes/class-jpwc-items.php: immutable merchandise snapshot and native metadata/collation protection.
- includes/class-jpwc-money.php and class-jpwc-proof.php: decimal arithmetic, exact authenticated settlement checks.
- includes/class-jpwc-api.php: WordPress HTTP transport and bounded recovery.
- includes/class-jpwc-webhook.php and vendor/: strict notification envelope and scoped cryptography.
- tests/: isolated synthetic regressions, never included in the submission ZIP.

New orders reserve a unique ledger record in reserved before a creator claims creating_inflight. Known invoices move to awaiting_payment; uncertain create outcomes remain recovery-only. Legacy quote recovery uses quote_unknown/quoted. Verified fulfillment claims delivery_claimed before WooCommerce side effects and then completed. Ambiguous proof, mutations or a crash during irreversible delivery require manual review/attention; automatic retries do not replay fulfillment side effects.

Schema version 1.0.1 is an independent physical database schema identifier, not the plugin's public version. Internal gateway ID, ledger names, configuration constants and option names are preserved for merchant data continuity.
