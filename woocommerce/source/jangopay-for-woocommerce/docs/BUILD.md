# Rebuild the private phpseclib 4.0.2 runtime

Build tools require PHP 8.4.1 or later because the included PHP-Scoper lock requires it. The merchant plugin runtime requires PHP 8.1 or later. Do not ignore platform requirements.

For the exact recorded dependency set, copy build/crypto into an empty directory and use Composer install --no-dev --prefer-dist --no-plugins --no-scripts. Do not run Composer install over the plugin's scoped vendor directory. Preserve all lock files and licenses.

The automated rebuild performs isolated dependency resolution for the pinned phpseclib 4.0.2 target, PHP-Scoper prefixing, dynamic namespace correction, installed.json PSR-4 map correction, autoload regeneration, security audit, complete regressions on both runtimes and namespace-isolation checks before making ZIPs. Every build records its resolved lock file. The build tool is not a merchant runtime dependency.

Example (replace paths):

```text
python upgrade/phpseclib4/build_release.py --php /path/to/php85 --php-ini /path/to/build.ini --runtime-php /path/to/php81 --runtime-ini /path/to/runtime.ini --composer-phar /path/to/composer.phar --node /path/to/node
```

Tests need development OpenSSL CLI on PATH to generate independent OAEP-SHA256 vectors. Merchant callback decryption uses PHP only and also passed with process-execution functions disabled. The maintenance suite uses isolated WordPress/WooCommerce and merchant HTTP fixtures.
