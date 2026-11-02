<?php
namespace Automattic\WooCommerce\Admin\Overrides;

/** Minimal synthetic stand-in: production behavior is validated only with native WooCommerce. */
class Order extends \WC_Order {
    public function adminOrderFeature() { return 'admin-core-feature-preserved'; }
}
