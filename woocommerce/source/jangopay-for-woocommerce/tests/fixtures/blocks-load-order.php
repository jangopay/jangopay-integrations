<?php
namespace Automattic\WooCommerce\Blocks\Payments\Integrations { abstract class AbstractPaymentMethodType {
} }
namespace {
define('ABSPATH','/fake-wp/');define('WC_VERSION','10.0.0');
function plugin_dir_path($f){return dirname($f).'/';}
function plugin_dir_url($f){return 'https://test.local/plugins/jangopay-for-woocommerce/';}
function plugin_basename($f){return 'jangopay-for-woocommerce/'.basename($f);}
function register_activation_hook($f,$cb){}
function register_deactivation_hook($f,$cb){}
function add_action($name,$cb,$priority=10){$GLOBALS['hooks'][$name][$priority][]=$cb;}
function add_filter($name,$cb,$priority=10){add_action($name,$cb,$priority);}
function do_action($name,...$args){$groups=$GLOBALS['hooks'][$name]??[];ksort($groups);foreach($groups as $callbacks)foreach($callbacks as $cb)$cb(...$args);}
// Loading order subclass requires WooCommerce's WC_Order base type.
class WC_Order {
    public function get_items($type='line_item'){return []; }
}
class WC_Payment_Gateway {}
$woo=static function(){do_action('woocommerce_blocks_loaded');};
$wooFirst=($argv[1]??'woo-first')==='woo-first';
if($wooFirst)add_action('plugins_loaded',$woo,10);
require dirname(__DIR__,2).'/jangopay-for-woocommerce.php';
if(!$wooFirst)add_action('plugins_loaded',$woo,10);
do_action('plugins_loaded');
$count=count($GLOBALS['hooks']['woocommerce_blocks_payment_method_type_registration'][10]??[]);
if($count!==1)throw new \RuntimeException('Checkout Block did not register for both load orders');
echo json_encode(['case'=>$wooFirst?'woocommerce_loads_first':'jangopay_loads_first','registered_block_handlers'=>$count]).PHP_EOL;
}
