# Third-party licensing and dependency notes

- Azuriom / Plugin-Shop: upstream MIT licensed, **not included in production plugin source**. https://github.com/Azuriom/Plugin-Shop
- Azuriom / Plugin-DedipassPayment: upstream MIT licensed, used as architecture reference, **not copied into this plugin**. https://github.com/Azuriom/Plugin-DedipassPayment
- JangoPay_SDK_PHP: MIT licensed; its documented public API behavior was referenced, but SDK source **was not bundled**. https://github.com/JangoPayOrganization/JangoPay_SDK_PHP
- PHP/OpenSSL/Laravel HTTP client: rely on host runtime; no bundled cryptographic or Composer vendor dependencies.
- The small `assets/img/name.svg` contains only ordinary text “JangoPay” in a generic font, **not an official brand logo**. Replace only using an authorized official asset if provided.

- `tests/fixtures/ShopPaymentMethod.php` is an upstream Shop `PaymentMethod` snapshot supplied with the independent Codex reproduction package. **Test fixture only**, never registered as a plugin provider or used in production. Source: https://github.com/Azuriom/Plugin-Shop/blob/master/src/Payment/PaymentMethod.php . Retain upstream MIT attribution; this file is not original JangoPay code.
