# Azuriom regression suite reference

The plugin includes focused regression scripts for payment helpers, configuration, order state handling, guarded recovery and session concurrency.

Run from the `azuriom/` directory with PHP and an accessible OpenSSL CLI:

```sh
php tests/offline.php
php tests/readiness.php
php tests/state_regression.php
php tests/codex_regressions.php
php tests/session_race_recovery.php
```

These scripts use isolated fixtures and temporary keys. They do not require merchant credentials.

For installation and operation, use the [merchant guide](MERCHANT_GUIDE_ZH_CN.md). For request formats, payment verification and recovery rules, see the [API contract](API_CONTRACT.md).

The source tests remain part of the repository for maintenance.
