<?php
/** V1.0.19 WordPress itemmeta short-circuit and case-insensitive SQL model.
 * Exercises the real JPWC_Items callbacks with a controlled native-API model.
 * This is NOT a WordPress/MariaDB integration test.
 */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['hooks119'] = [];
$GLOBALS['itemOwner119'] = [77=>9, 88=>10];
$GLOBALS['meta119'] = [77=>['_product_id'=>101,'_qty'=>1,'_line_total'=>'3.00','_reduced_stock'=>1,'public_note'=>'a'],
                      88=>['_qty'=>2,'public_note'=>'b']];
$GLOBALS['ledger119'] = [9=>(object)['state'=>'awaiting_payment']];
$GLOBALS['passes119']=0;
$GLOBALS['metaById119'] = [10=>(object)['order_item_id'=>77,'meta_key'=>'_QTY','meta_value'=>1],
                            11=>(object)['order_item_id'=>77,'meta_key'=>'note','meta_value'=>'hi']];
function check119(string $name,bool $ok):void {
    echo ($ok?'PASS ':'FAIL ').$name."\n";
    if(!$ok)throw new RuntimeException('failed: '.$name);
    $GLOBALS['passes119']++;
}
function add_filter($hook,$fn,$priority=10,$argc=1) {
    $GLOBALS['hooks119'][$hook][] = [$priority,$fn];
    usort($GLOBALS['hooks119'][$hook],static fn($a,$b)=>$a[0]<=>$b[0]);
}
function add_action($hook,$fn,$priority=10,$argc=1){add_filter($hook,$fn,$priority,$argc);}
function apply_filters($hook,$check,...$args) {
    foreach($GLOBALS['hooks119'][$hook]??[] as $f)$check=($f[1])($check,...$args);
    return $check;
}
function wc_get_order_id_by_order_item_id($id){return $GLOBALS['itemOwner119'][(int)$id]??0;}
function get_metadata_by_mid($type,$id){return isset($GLOBALS['metaById119'][$id])?clone $GLOBALS['metaById119'][$id]:false;}
function update_metadata_by_mid($type,$id,$value,$newKey=false){
    $check=apply_filters('update_order_item_metadata_by_mid',null,$id,$value,$newKey);
    if($check!==null)return (bool)$check;
    if(!isset($GLOBALS['metaById119'][$id]))return false;
    if(is_string($newKey))$GLOBALS['metaById119'][$id]->meta_key=$newKey;
    $GLOBALS['metaById119'][$id]->meta_value=$value;return true;
}
function delete_metadata_by_mid($type,$id){
    $check=apply_filters('delete_order_item_metadata_by_mid',null,$id);
    if($check!==null)return (bool)$check;
    if(!isset($GLOBALS['metaById119'][$id]))return false;
    unset($GLOBALS['metaById119'][$id]);return true;
}
// Equality approximates a case-insensitive PAD SPACE MySQL itemmeta collation.
function key119($s){return strtolower(rtrim((string)$s,' '));}
function find119($id,$key){foreach($GLOBALS['meta119'][(int)$id]??[] as $k=>$v)if(key119($k)===key119($key))return $k;return null;}
function update_metadata($type,$id,$key,$value,$prev='') {
    if($type!=='order_item')throw new RuntimeException('wrong_type');
    $v=apply_filters('update_order_item_metadata',null,$id,$key,$value,$prev);
    if($v!==null)return (bool)$v;
    $oldKey=find119($id,$key);if($oldKey===null)return false;
    $GLOBALS['meta119'][$id][$oldKey]=$value;
    return true;
}
function add_metadata($type,$id,$key,$value,$unique=false) {
    if($type!=='order_item')throw new RuntimeException('wrong_type');
    $v=apply_filters('add_order_item_metadata',null,$id,$key,$value,$unique);
    if($v!==null)return $v;
    if($unique && find119($id,$key)!==null)return false;
    $GLOBALS['meta119'][$id][$key]=$value;
    return 11;
}
function delete_metadata($type,$id,$key,$value='',$all=false) {
    if($type!=='order_item')throw new RuntimeException('wrong_type');
    $v=apply_filters('delete_order_item_metadata',null,$id,$key,$value,$all);
    if($v!==null)return (bool)$v;
    if($all){$found=false;foreach($GLOBALS['meta119'] as $oid=>&$data){$actual=find119($oid,$key);if($actual!==null){unset($data[$actual]);$found=true;}}unset($data);return $found;}
    $actual=find119($id,$key);if($actual===null)return false;
    unset($GLOBALS['meta119'][$id][$actual]);return true;
}
class Db119 {
    public $dbname='wp_test';public $prefix='wp_';public $locks=0;public $unlocks=0;public $held=0;
    public function prepare($sql,...$args){return [$sql,$args];}
    public function get_var($sql){
        if(str_contains($sql[0],'GET_LOCK')){$this->locks++;$this->held++;return '1';}
        if(str_contains($sql[0],'RELEASE_LOCK')){$this->unlocks++;$this->held--;return '1';}
        throw new RuntimeException('bad_sql');
    }
}
$GLOBALS['wpdb']=new Db119();
class JPWC_Ledger {public static function get($id){return $GLOBALS['ledger119'][(int)$id]??null;}}
require dirname(__DIR__).'/includes/class-jpwc-items.php';
require dirname(__DIR__).'/includes/class-jpwc-order-guard.php';
JPWC_Order_Guard::boot();
function blocked119(callable $fn, string $code='invoiced_order_item_metadata_write_rejected'):bool {
    try{$fn();return false;}catch(RuntimeException $e){return $e->getMessage()===$code;}
}
if (getenv('JPWC119_SKIP_BULK') !== '1') {
$before=json_encode($GLOBALS['meta119']);
foreach([[0,'_qty',true],[-1,'_PRODUCT_ID',true],[9999,'_line_total','1'],[0,'_QTY ','1'],[0,'_variation_id',1]] as [$id,$key,$all]) {
    check119('bulk critical delete rejected for '.json_encode([$id,$key,$all]),blocked119(fn()=>delete_metadata('order_item',$id,$key,'',$all),'bulk_item_meta_delete_not_supported'));
}
check119('bulk rejected without touching data',json_encode($GLOBALS['meta119'])===$before);
check119('bulk rejection before resolving unknown item owner',$GLOBALS['wpdb']->locks===0);
check119('ordinary global delete still available',delete_metadata('order_item',0,'public_note','',true)===true && find119(77,'public_note')===null && find119(88,'public_note')===null);
}
foreach(['_QTY','_PRODUCT_ID','_LINE_TOTAL','_qty '] as $key){
    $canonical=key119($key);
    $old=$GLOBALS['meta119'][77][$canonical];
    check119('capitalized/aliased critical update blocked '.$key,blocked119(fn()=>update_metadata('order_item',77,$key,'900')) && $GLOBALS['meta119'][77][$canonical]===$old);
    check119('capitalized/aliased critical delete blocked '.$key,blocked119(fn()=>delete_metadata('order_item',77,$key)) && $GLOBALS['meta119'][77][$canonical]===$old);
    check119('capitalized/aliased critical add blocked '.$key,blocked119(fn()=>add_metadata('order_item',77,$key,1)));
}
check119('benign bookkeeping remains writable',update_metadata('order_item',77,'_REDUCED_STOCK',2)===true && $GLOBALS['meta119'][77]['_reduced_stock']===2);
check119('unrelated order critical mixed-case update serialized',update_metadata('order_item',88,'_QTY',3)===true && $GLOBALS['meta119'][88]['_qty']===3);
check119('ordinary critical edit takes and releases lock',$GLOBALS['wpdb']->locks>0 && $GLOBALS['wpdb']->locks===$GLOBALS['wpdb']->unlocks && $GLOBALS['wpdb']->held===0);
check119('by-mid update detects existing uppercase critical key',blocked119(fn()=>update_metadata_by_mid('order_item',10,9)) && $GLOBALS['metaById119'][10]->meta_value===1);
check119('by-mid deletion detects existing uppercase critical key',blocked119(fn()=>delete_metadata_by_mid('order_item',10)) && isset($GLOBALS['metaById119'][10]));
check119('by-mid rename into uppercase critical key blocked',blocked119(fn()=>update_metadata_by_mid('order_item',11,9,'_PRODUCT_ID')) && $GLOBALS['metaById119'][11]->meta_key==='note');
check119('by-mid innocuous note update allowed',update_metadata_by_mid('order_item',11,'ok')===true);
check119('no bulk deletion leaks product values',$GLOBALS['meta119'][77]['_qty']===1 && $GLOBALS['meta119'][77]['_product_id']===101);
echo 'Total PASS: '.$GLOBALS['passes119']."\n";
