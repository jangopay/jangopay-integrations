<?php
/**
 * Plugin Name: JangoPay for WooCommerce
 * Description: Accept USDT and USDC through JangoPay hosted checkout for USD WooCommerce orders.
 * Version: 1.0.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.3
 * Requires Plugins: woocommerce
 * Author: JangoPay
 * Author URI: https://www.jangopay.org/
 * Text Domain: jangopay-for-woocommerce
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
if (!defined('ABSPATH')) { exit; }
define('JPWC_VERSION', '1.0.2');
define('JPWC_DIR', plugin_dir_path(__FILE__));
define('JPWC_URL', plugin_dir_url(__FILE__));

add_filter('plugin_action_links_'.plugin_basename(__FILE__), static function ($links) {
    if (current_user_can('manage_woocommerce')) {
        array_unshift($links, '<a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=jangopay_woocommerce')).'">'.esc_html__('Settings', 'jangopay-for-woocommerce').'</a>');
    }
    return $links;
});

add_action('before_woocommerce_init', static function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_activation_hook(__FILE__, static function () {
    require_once JPWC_DIR.'includes/class-jpwc-ledger.php';
    JPWC_Ledger::install();
    if (!wp_next_scheduled('jpwc_reconcile_tick')) {
        wp_schedule_event(time()+120, 'hourly', 'jpwc_reconcile_tick');
    }
});
register_deactivation_hook(__FILE__, static function () { wp_clear_scheduled_hook('jpwc_reconcile_tick'); });

add_action('plugins_loaded', static function () {
    if (!class_exists('WC_Payment_Gateway') || !defined('WC_VERSION') || version_compare(WC_VERSION, '8.3', '<') || PHP_VERSION_ID < 80100) {
        add_action('admin_notices', static function () {
            if (!current_user_can('activate_plugins')) { return; }
            echo '<div class="notice notice-warning"><p>'.esc_html__('JangoPay requires WooCommerce 8.3+ and PHP 8.1+.', 'jangopay-for-woocommerce').'</p></div>';
        });
        return;
    }
    foreach (['money','api','ledger','proof','items','webhook','gateway','controller','order-guard','guarded-order'] as $file) {
        require_once JPWC_DIR.'includes/class-jpwc-'.$file.'.php';
    }
    add_filter('woocommerce_payment_gateways', static function ($gateways) { $gateways[] = 'JPWC_Gateway'; return $gateways; });
    JPWC_Order_Guard::boot();
    JPWC_Controller::boot();
});

// Listen early: WooCommerce can fire Blocks-loaded before our plugins_loaded callback.
add_action('woocommerce_blocks_loaded', static function () {
    static $registered = false;
    if ($registered || !class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) { return; }
    $registered = true;
    require_once JPWC_DIR.'includes/class-jpwc-blocks.php';
    add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry) {
        $registry->register(new JPWC_Blocks());
    });
});
