<?php
if (!defined('ABSPATH')) { exit; }
/**
 * Guarded WooCommerce Admin order: preserve WooCommerce Admin's native
 * Overrides\Order behavior while enforcing the same pre-save safety checks.
 * Loaded only after WooCommerce's own override class is available.
 */
if (!class_exists('\Automattic\WooCommerce\Admin\Overrides\Order')) {
    return;
}
class JPWC_Guarded_Admin_Order extends \Automattic\WooCommerce\Admin\Overrides\Order implements JPWC_Guarded_Order_Contract {
    use JPWC_Guarded_Order_Save;
}
