<?php
namespace Automattic\WooCommerce\Utilities {

class OrderUtil {
    public static function custom_orders_table_usage_is_enabled(){return true;}
    public static function custom_orders_table_datastore_cache_enabled(){return true;}
    public static function orders_cache_usage_is_enabled(){return true;}
}}
namespace Automattic\WooCommerce\Internal\DataStores\Orders {
class OrdersTableDataStore {
    public function clear_cached_data(array $ids):array {$GLOBALS['store_clear']++;return [$ids[0]=>$GLOBALS['store_clear_result']];}
}}
namespace Automattic\WooCommerce\Caching {
class WPCacheEngine {
    public function is_cached(string $id,string $group=''):bool{return !empty($GLOBALS['hpos_keys'][$group]);}
    public function delete_cached_object(string $id,string $group=''):bool {
        if(!empty($GLOBALS['hpos_stubborn']))return false;
        unset($GLOBALS['hpos_keys'][$group]);return true;
    }
}}
namespace Automattic\WooCommerce\Caches {class OrderCache {public function remove($id){$GLOBALS['order_cache_removed']++;}}}
namespace {
define('ABSPATH','/fake-wordpress/');
require dirname(__DIR__).'/includes/class-jpwc-controller.php';
$GLOBALS['store_clear']=0;$GLOBALS['store_clear_result']=true;$GLOBALS['order_cache_removed']=0;$GLOBALS['order_loaded']=0;
$GLOBALS['hpos_keys']=['orders_meta'=>true,'orders_data'=>true];$GLOBALS['hpos_stubborn']=false;
function wc_get_container(){return new class {
    public function get($class){return new $class();}
};}
function wc_get_order($id){$GLOBALS['order_loaded']++;return (object)['id'=>$id];}
function verify($name,$ok){static $i=0;if(!$ok)throw new \RuntimeException('FAIL '.$name);$i++;echo 'PASS '.$i.' '.$name."\n";}
$o=\JPWC_Controller::freshOrder(7);
verify('HPOS cache invalidated before order read',is_object($o)&&$GLOBALS['store_clear']===1 && $GLOBALS['order_cache_removed']===1&&$GLOBALS['order_loaded']===1);
$GLOBALS['store_clear_result']=false;
$o=\JPWC_Controller::freshOrder(7);
verify('cache-miss deletion false is safe when no cached data remains',is_object($o)&&$GLOBALS['order_loaded']===2);
$GLOBALS['hpos_keys']=['orders_meta'=>true,'orders_data'=>true];$GLOBALS['hpos_stubborn']=true;
try { \JPWC_Controller::freshOrder(7); verify('failure should reject stale data',false); }
catch(\RuntimeException $e){verify('HPOS invalidation failure is fail closed',$e->getMessage()==='hpos_cache_invalidation_failed');}
verify('no further order read on failed HPOS invalidation',$GLOBALS['order_loaded']===2);
}
