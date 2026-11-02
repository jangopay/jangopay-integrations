<?php
/** V1.0.17 WooCommerce public order-item factory contract regression.
 * This is a real-source isolated test, not a native WordPress/MariaDB run.
 * Deliberately DO NOT define the non-existent global wc_get_order_item().
 */
define('ABSPATH','/fake/');
$GLOBALS['lines117']=[];$GLOBALS['ledger117']=[];$GLOBALS['pass117']=0;
function pass117($name,$condition){if(!$condition)throw new RuntimeException('FAIL '.$name);++$GLOBALS['pass117'];echo 'PASS '.$GLOBALS['pass117'].' '.$name."\n";}
class FactoryLine117 {
    function __construct(public int $id,public int $order,public int $product=101, public array $meta=['_reduced_stock'=>0]){}
    function get_id(){return $this->id;}
    function get_order_id(){return $this->order;}
    function get_product_id(){return $this->product;}
    function get_variation_id(){return 0;}
    function get_quantity(){return 1;}
    function get_total(){return '3.00';}
    function get_subtotal(){return '3.00';}
    function get_name(){return 'Download';}
    function get_type(){return 'line_item';}
}
class WC_Order_Factory {
    static $reads=[];static $onRead=null;
    static function get_order_item($item_id){self::$reads[]=$item_id;if(is_callable(self::$onRead))(self::$onRead)();return $GLOBALS['lines117'][(int)$item_id]??false;}
}
class JPWC_Ledger {static function get($id){return $GLOBALS['ledger117'][(int)$id]??null;}}
class Db117 {
    public $dbname='test',$prefix='wp_',$depth=0,$taken=0,$released=0;
    function prepare($sql,...$args){return [$sql,$args];}
    function get_var($q){
        if(str_contains($q[0],'GET_LOCK')){++$this->depth;++$this->taken;return 1;}
        if(str_contains($q[0],'RELEASE_LOCK')){--$this->depth;++$this->released;return 1;}
        throw new RuntimeException('bad sql');
    }
}
$GLOBALS['wpdb']=new Db117();
function get_current_blog_id(){return 1;}
require dirname(__DIR__).'/includes/class-jpwc-order-guard.php';
require dirname(__DIR__).'/includes/class-jpwc-items.php';
pass117('no invented global wc_get_order_item alias',!function_exists('wc_get_order_item'));
pass117('real native factory method available',is_callable(['WC_Order_Factory','get_order_item']));
$stored=new FactoryLine117(55,100);
$GLOBALS['lines117'][55]=$stored;
$GLOBALS['ledger117'][100]=(object)['state'=>'awaiting_payment'];
// WooCommerce stock first reduces inventory, then saves _reduced_stock on an unchanged WC_Order_Item_Product.
$stockMarker=new FactoryLine117(55,100,101,['_reduced_stock'=>1]);
JPWC_Items::beforeItemSave($stockMarker);
pass117('stock-bookkeeping save accepted on invoiced line', $GLOBALS['wpdb']->depth===1);
pass117('source came through native factory',WC_Order_Factory::$reads===[55,55]);
$GLOBALS['lines117'][55]=$stockMarker;
JPWC_Items::afterItemSave($stockMarker);
pass117('stock marker persisted after write', $GLOBALS['lines117'][55]->meta['_reduced_stock']===1);
pass117('stock marker update released advisory lock',$GLOBALS['wpdb']->depth===0);
// Repeated harmless native save can never unset the marker or block core bookkeeping.
$repeat=clone $stockMarker;
JPWC_Items::beforeItemSave($repeat);JPWC_Items::afterItemSave($repeat);
pass117('second unchanged item save permitted', $GLOBALS['lines117'][55]->meta['_reduced_stock']===1);
// Product mutation must be denied without modifying original stock/bookkeeping.
$changed=new FactoryLine117(55,100,999,['_reduced_stock'=>1]);
$failed=null;try{JPWC_Items::beforeItemSave($changed);}catch(RuntimeException $e){$failed=$e->getMessage();}
pass117('changed product refused before write',$failed==='invoiced_order_item_write_rejected');
pass117('rejected edit never cleared stock marker',$GLOBALS['lines117'][55]->meta['_reduced_stock']===1);
pass117('rejected edit released lock',$GLOBALS['wpdb']->depth===0);
$failed=null;try{JPWC_Items::beforeDelete(55);}catch(RuntimeException $e){$failed=$e->getMessage();}
pass117('direct wc_delete_order_item hook refuses invoiced line',$failed==='invoiced_order_item_delete_rejected');
pass117('refused delete preserved persisted line',isset($GLOBALS['lines117'][55]));
pass117('refused delete released lock',$GLOBALS['wpdb']->depth===0);
// Source and target owner must both be checked. Both owners are locked in deterministic order.
$move=new FactoryLine117(55,200);
$failed=null;try{JPWC_Items::beforeItemSave($move);}catch(RuntimeException $e){$failed=$e->getMessage();}
pass117('cross-order move of invoiced item refused',$failed==='invoiced_order_item_write_rejected');
pass117('cross-order refusal released both locks',$GLOBALS['wpdb']->depth===0);
$GLOBALS['ledger117']=[200=>(object)['state'=>'awaiting_payment']];
$move=new FactoryLine117(55,200);
$failed=null;try{JPWC_Items::beforeItemSave($move);}catch(RuntimeException $e){$failed=$e->getMessage();}
pass117('move INTO invoiced order also refused',$failed==='invoiced_order_item_write_rejected');
$GLOBALS['ledger117']=[];
$ordinary=new FactoryLine117(55,100,999);
JPWC_Items::beforeItemSave($ordinary);
pass117('ordinary uninvoiced edit holds lock while persisting',$GLOBALS['wpdb']->depth===2 || $GLOBALS['wpdb']->depth===1);
$GLOBALS['lines117'][55]=$ordinary;JPWC_Items::afterItemSave($ordinary);
JPWC_Items::beforeDelete(55);
pass117('ordinary uninvoiced deletion allowed',$GLOBALS['wpdb']->depth>0);
unset($GLOBALS['lines117'][55]);JPWC_Items::afterDelete(55);
pass117('ordinary deletion completed and released lock',!isset($GLOBALS['lines117'][55]) && $GLOBALS['wpdb']->depth===0);
// Simulate an item owner changing while our request waits for its lock.
$GLOBALS['lines117'][55]=new FactoryLine117(55,100);
$readsBefore=count(WC_Order_Factory::$reads);
WC_Order_Factory::$onRead=static function() use ($readsBefore){
    if(count(WC_Order_Factory::$reads)===$readsBefore+2)
        $GLOBALS['lines117'][55]=new FactoryLine117(55,300);
};
$failed=null;try{JPWC_Items::beforeItemSave(new FactoryLine117(55,100));}catch(RuntimeException $e){$failed=$e->getMessage();}
WC_Order_Factory::$onRead=null;
pass117('owner moved between pre-lock and post-lock factory reads is rejected',$failed==='order_item_owner_changed_during_write');
pass117('owner change does not leak lock',$GLOBALS['wpdb']->depth===0);
// Likewise guard the native delete hook against an item owner changing while waiting.
$GLOBALS['lines117'][55]=new FactoryLine117(55,100);
$readsBefore=count(WC_Order_Factory::$reads);
WC_Order_Factory::$onRead=static function() use ($readsBefore){
    if(count(WC_Order_Factory::$reads)===$readsBefore+2)
        $GLOBALS['lines117'][55]=new FactoryLine117(55,300);
};
$failed=null;try{JPWC_Items::beforeDelete(55);}catch(RuntimeException $e){$failed=$e->getMessage();}
WC_Order_Factory::$onRead=null;
pass117('owner moved during delete is rejected',$failed==='order_item_owner_changed_during_delete');
pass117('delete owner change does not leak lock',$GLOBALS['wpdb']->depth===0);
// A newly inserted line into already invoiced order cannot be accepted without a persisted source.
$GLOBALS['ledger117']=[100=>(object)['state'=>'awaiting_payment']];
$failed=null;try{JPWC_Items::beforeItemSave(new FactoryLine117(0,100,42));}catch(RuntimeException $e){$failed=$e->getMessage();}
pass117('new line on invoiced order refused',$failed==='invoiced_order_item_write_rejected');
pass117('all advisory locks balanced',$GLOBALS['wpdb']->depth===0 && $GLOBALS['wpdb']->taken===$GLOBALS['wpdb']->released);
echo "NOTE: real JPWC_Items with WC_Order_Factory::get_order_item stub, NOT native WooCommerce stock/HPOS\n";
