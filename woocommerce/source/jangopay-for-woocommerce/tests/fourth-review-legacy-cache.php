<?php
namespace Automattic\WooCommerce\Utilities {

    class OrderUtil {public static function orders_cache_usage_is_enabled(){return false;}}
}
namespace {
require __DIR__.'/fixtures/legacy-cpt-bootstrap.php';
interface WC_Abstract_Order_Data_Store_Interface {}
interface WC_Object_Data_Store_Interface {}
interface WC_Order_Data_Store_Interface {}
class WC_Data_Store_WP {protected function string_to_timestamp($v){return strtotime($v);}}
function wp_cache_delete($id,$group='') {
    if($group==='posts')unset($GLOBALS['posts_cache'][(int)$id]);
    if($group==='post_meta')unset($GLOBALS['meta_cache'][(int)$id]);
    $GLOBALS['deletions'][$group]=($GLOBALS['deletions'][$group]??0)+1;
    return true;
}
// Some sites suspend WordPress clean_post_cache; the explicit WP cache
// deletions must still prevent a stale order in that situation.
function clean_post_cache($id){$GLOBALS['clean_post_cache_called']=true;}

require __DIR__.'/fixtures/legacy-cpt/class-wc-order-factory.php';
require __DIR__.'/fixtures/legacy-cpt/abstract-wc-order-data-store-cpt.php';
require __DIR__.'/fixtures/legacy-cpt/class-wc-order-data-store-cpt.php';
class WC_Data_Store {public static function load($t){return new class {
    public function has_callable($m){return true;}
    public function get_orders_type($ids){return array_fill_keys($ids,'shop_order');}
};}}
function wc_get_order_type($t){return ['class_name'=>NativeCptOrder::class];}
function wc_get_order_types(){return ['shop_order'];}
function apply_filters($tag,$value){return $value;}
function metadata_exists($type,$id,$key){return false;}
function get_option($name,$default=false){return $name==='jpwc_schema_version'?'1.0.1':$default;}
function wc_string_to_bool($v){return in_array($v,['yes','true',true,1,'1'],true);}
// Model WordPress request-local posts/post_meta caching. A second worker can
// update persistence but cannot mutate the cache already held by this request.
function get_post($id){
    if(!isset($GLOBALS['posts_cache'][$id]))$GLOBALS['posts_cache'][$id]=(object)[
        'ID'=>$id,'post_type'=>'shop_order','post_parent'=>0,
        'post_date_gmt'=>'2026-10-16 00:00:00','post_modified_gmt'=>'2026-10-16 00:00:00',
        'post_status'=>'wc-'.$GLOBALS['persistent']['status'],'post_excerpt'=>''
    ];
    return clone $GLOBALS['posts_cache'][$id];
}
function get_post_meta($id,$key='',$single=false){
    if(!isset($GLOBALS['meta_cache'][$id]))$GLOBALS['meta_cache'][$id]=[
        '_order_total'=>[$GLOBALS['persistent']['total']],
        '_order_currency'=>['USD'],'_order_version'=>['10.3.0'],
        '_order_key'=>['wc_order_key_secret'],'_payment_method'=>['jangopay_woocommerce']
    ];
    $all=$GLOBALS['meta_cache'][$id];return $key===''?$all:($single?($all[$key][0]??''):($all[$key]??[]));
}
class NativeCptOrder extends TestOrder {
    public function get_items($type='line_item'){return []; }

    private $props=[];
    public function __construct($id=7){$this->id=$id;(new WC_Order_Data_Store_CPT())->read($this);}
    public function set_defaults(){$this->props=[];}
    public function set_props($props){
        $this->props=array_merge($this->props,$props);
        if(isset($props['status']))$this->status=preg_replace('/^wc-/','',$props['status']);
        return null;
    }
    public function read_meta_data(){}
    public function set_object_read($v){}
    public function get_version($context='view'){return '10.3.0';}
    public function get_extra_data_keys(){return [];}
    public function get_total(){return $this->props['total'];}
    public function update_status($s,$note=''){
        $this->status=$s;$GLOBALS['persistent']['status']=$s;
        unset($GLOBALS['posts_cache'][$this->id],$GLOBALS['meta_cache'][$this->id]);
    }
    public function payment_complete($txn){
        $GLOBALS['delivery_calls']++;
        $GLOBALS['persistent']['status']='processing';
        return parent::payment_complete($txn);
    }
}
class NativeReadRaceDb extends StubDb {
    public function query($sql){
        $n=parent::query($sql);
        if($n===1&&($this->rows[7]['state']??'')==='delivery_claimed'&&empty($GLOBALS['external_change'])){
            $GLOBALS['external_change']=true;
            $GLOBALS['persistent']=['status'=>'cancelled','total'=>'4.00'];
            $GLOBALS['at_claim']=$GLOBALS['persistent'];
        }
        return $n;
    }
}
$GLOBALS['persistent']=['status'=>'pending','total'=>'3.00'];$GLOBALS['delivery_calls']=0;
$GLOBALS['wpdb']=new NativeReadRaceDb();
(new JPWC_Gateway())->process_payment(7);
$row=JPWC_Ledger::get(7);$GLOBALS['merchantId']=$row->merchant_order_id;
JPWC_Ledger::cas($row,['awaiting_payment'],['state'=>'quoted','txn_id'=>'T1','asset'=>'USDT','chain'=>'tron','expires_at'=>'2099-01-01T00:00:00Z']);
$outcome=JPWC_Controller::verifyRow(JPWC_Ledger::get(7));
if($outcome!=='delivery_attention'||$GLOBALS['delivery_calls']!==0||$GLOBALS['at_claim']['status']!=='cancelled' ||
   ($GLOBALS['deletions']['posts']??0)<1 || ($GLOBALS['deletions']['post_meta']??0)<1)
    throw new \RuntimeException('Stale legacy CPT cache was not safely invalidated');
echo json_encode(['case'=>'legacy_post_meta_cache_invalidated','orders_cache_enabled'=>false,
    'persistent_at_claim'=>$GLOBALS['at_claim'],'plugin_result'=>$outcome,
    'payment_complete_calls'=>$GLOBALS['delivery_calls'],'persistent_after'=>$GLOBALS['persistent'],'deletions'=>$GLOBALS['deletions']]).PHP_EOL;
echo "PASS legacy CPT posts/meta cache refresh before Woo delivery\n";
}
