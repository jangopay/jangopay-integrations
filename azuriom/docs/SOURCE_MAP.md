# Implemented source map

- `src/JangoPayMethod.php`: Shop adapter, create-once checkout, notification and delivery claim.
- `src/Services/JangoPayApi.php`: authenticated narrow invoice API and public quote flow, bounded read-only recovery.
- `src/Services/Reconciler.php`: invoice/order/amount/merchant txn reconciliation; fail closed without final-status allowlist.
- `src/Services/WebhookEnvelope.php`: candidate envelope decrypt; disabled until live tag contract validated.
- `src/Controllers/CheckoutController.php`: ownership-restricted payment instruction page, token-secured read-only status.
- `src/Controllers/AdminController.php`: permission-controlled read-only health check and per-attempt reverify.
- `src/Commands/ReconcilePayments.php`: optional operator-run pending reconciler.
- `src/Models/Attempt.php` and `database/migrations/*`: separate durable attempt ledger.
- `resources/views/*`, `resources/lang/*`: gateway config, player payment UI and bilingual strings.
- `tests/offline.php`: pure PHP offline synthetic tests; does not simulate whole Laravel Shop.
