<?php
/** V1.0.16 real-source order-item write and WP metadata prefilter regression.
 * This is a controlled same-connection interleaving model, NOT native MariaDB.
 */
define('ABSPATH','/fake/');
$GLOBALS['a']=0; $GLOBALS['stored']=[]; $GLOBALS['ledger']=[];
function ck116($description,$result){if(!$result)throw new RuntimeException('FAIL '.$description); echo 'PASS '.++$GLOBALS['a'].' '.$description."\n";}
function wp_json_encode($x){return json_encode($x);}
function get_current_blog_id(){return 1;}
$GLOBALS['registered']=[];
function add_action($name,$callback,$priority=10,$argc=1){$GLOBALS['registered'][$name]=$callback;}
function add_filter($name,$callback,$priority=10,$argc=1){$GLOBALS['registered'][$name]=$callback;}
class Lock116Db {
    public $dbname='test',$prefix='wp_',$depth=0,$acquired=0,$released=0,$fail=false;
    function prepare($sql,...$params){return [$sql,$params];}
    function get_var($query){
        if(str_contains($query[0],'GET_LOCK')){
            if($this->fail)return null;
            ++$this->depth; ++$this->acquired;return 1;
        }
        if(str_contains($query[0],'RELEASE_LOCK')){
            if($this->depth<=0)throw new RuntimeException('lock_release_without_acquire');
            --$this->depth;++$this->released;return 1;
        }
        throw new RuntimeException('unexpected_sql');
    }
}
$GLOBALS['wpdb']=new Lock116Db();
class JPWC_Ledger{static function get($id){return $GLOBALS['ledger'][(int)$id]??null;}}
class TItem {
    public function __construct(public int $id,public int $order,public int $product=101){}
    function get_id(){return $this->id;}
    function get_order_id(){return $this->order;}
    function get_product_id(){return $this->product;}
    function get_variation_id(){return 0;}
    function get_quantity(){return 1;}
    function get_total(){return '3.00';}
    function get_subtotal(){return '3.00';}
    function get_name(){return 'Product A';}
    function get_type(){return 'line_item';}
}
$GLOBALS['stored'][9]=new TItem(9,20);
$GLOBALS['metadata']=[9=>['_product_id'=>101,'_reduced_stock'=>1]];
class WC_Order_Factory {public static function get_order_item($id){return $GLOBALS['stored'][$id]??false;}}
function wc_get_order_id_by_order_item_id($id){return ($GLOBALS['stored'][$id]??null)?->order??0;}
function update_metadata($type,$id,$key,$value,$prev=''){
    $v=JPWC_Items::updateMeta(null,$id,$key,$value,$prev);
    if($v!==null)return $v;
    if(isset($GLOBALS['onMetaDataStore']))($GLOBALS['onMetaDataStore'])();
    $GLOBALS['metadata'][$id][$key]=$value;return true;
}
function add_metadata($type,$id,$key,$value,$unique=false){
    $v=JPWC_Items::addMeta(null,$id,$key,$value,$unique);
    if($v!==null)return $v;
    $GLOBALS['metadata'][$id][$key]=$value;return 17;
}
function delete_metadata($type,$id,$key,$value='',$all=false){
    $v=JPWC_Items::deleteMeta(null,$id,$key,$value,$all);
    if($v!==null)return $v;
    unset($GLOBALS['metadata'][$id][$key]);return true;
}
require dirname(__DIR__).'/includes/class-jpwc-order-guard.php';
require dirname(__DIR__).'/includes/class-jpwc-items.php';
JPWC_Order_Guard::boot();
foreach(['woocommerce_after_order_item_object_save','woocommerce_delete_order_item',
         'update_order_item_metadata','add_order_item_metadata','delete_order_item_metadata'] as $hook)
    ck116('registered '. $hook,isset($GLOBALS['registered'][$hook]));
$item=new TItem(9,20,102);
JPWC_Items::beforeItemSave($item);
ck116('pre-ledger item write holds shared lock until real datastore update',$GLOBALS['wpdb']->depth===1);
$error=null;try{JPWC_Order_Guard::synchronizeReservation(20,static fn()=>true);}catch(RuntimeException $e){$error=$e->getMessage();}
ck116('same-connection invoice reservation blocked during item save',$error==='order_save_in_progress');
$GLOBALS['stored'][9]=clone $item;
JPWC_Items::afterItemSave($item);
ck116('item lock released after datastore save',$GLOBALS['wpdb']->depth===0);
JPWC_Items::beforeDelete(9);
ck116('pre-ledger deletion holds the same lock',$GLOBALS['wpdb']->depth===1);
JPWC_Items::afterDelete(9);
ck116('delete completion releases lock',$GLOBALS['wpdb']->depth===0);
$observed=false;
$GLOBALS['onMetaDataStore']=static function()use(&$observed){
    $observed=$GLOBALS['wpdb']->depth>0;
    try{JPWC_Order_Guard::synchronizeReservation(20,static fn()=>true);}
    catch(RuntimeException $e){ck116('same-connection reserve refused during metadata write',$e->getMessage()==='order_save_in_progress');}
};
ck116('native meta API successfully updates before invoice',update_metadata('order_item',9,'_product_id',103)===true);
unset($GLOBALS['onMetaDataStore']);
ck116('underlying itemmeta operation executed while holding order lock',$observed);
ck116('metadata lock released after native operation',$GLOBALS['wpdb']->depth===0);
$GLOBALS['ledger'][20]=(object)['state'=>'awaiting_payment'];
$error=null;try{update_metadata('order_item',9,'_product_id',999);}catch(RuntimeException $e){$error=$e->getMessage();}
ck116('invoiced critical metadata update rejected',$error==='invoiced_order_item_metadata_write_rejected' && $GLOBALS['metadata'][9]['_product_id']===103);
$error=null;try{add_metadata('order_item',9,'_variation_id',300);}catch(RuntimeException $e){$error=$e->getMessage();}
ck116('invoiced critical metadata addition rejected',$error==='invoiced_order_item_metadata_write_rejected');
$error=null;try{delete_metadata('order_item',9,'_product_id');}catch(RuntimeException $e){$error=$e->getMessage();}
ck116('invoiced critical metadata deletion rejected',$error==='invoiced_order_item_metadata_write_rejected');
ck116('Woo stock bookkeeping remains permitted',update_metadata('order_item',9,'_reduced_stock',2)===true);
$error=null;try{JPWC_Items::beforeItemSave(new TItem(9,20,999));}catch(RuntimeException $e){$error=$e->getMessage();}
ck116('item object change after invoice rejected',$error==='invoiced_order_item_write_rejected');
ck116('all rejected mutations release their locks',$GLOBALS['wpdb']->depth===0);
$GLOBALS['wpdb']->fail=true;
$error=null;try{JPWC_Items::beforeItemSave(new TItem(9,20,103));}catch(RuntimeException $e){$error=$e->getMessage();}
ck116('unavailable advisory lock fails closed',$error==='order_lock_not_acquired');
$GLOBALS['wpdb']->fail=false;
unset($GLOBALS['ledger'][20]);
$GLOBALS['onMetaDataStore']=static function(){throw new RuntimeException('simulated_itemmeta_write_failure');};
$error=null;try{update_metadata('order_item',9,'_qty',5);}catch(RuntimeException $e){$error=$e->getMessage();}
ck116('metadata failure propagates',$error==='simulated_itemmeta_write_failure');
ck116('metadata finally releases lock on failure',$GLOBALS['wpdb']->depth===0);
unset($GLOBALS['onMetaDataStore']);
ck116('all advisory locks balanced',$GLOBALS['wpdb']->acquired===$GLOBALS['wpdb']->released);
echo "NOTE: isolated real source plus WP API emulation, requires native CPT/HPOS acceptance\n";
