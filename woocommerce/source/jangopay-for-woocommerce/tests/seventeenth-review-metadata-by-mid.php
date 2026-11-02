<?php
/**
 * Independent regression for WordPress's by-meta-ID metadata entry points.
 * The call shapes and short-circuit hook names follow wp-includes/meta.php.
 * This is a controlled API/hook harness, NOT a WordPress/MariaDB integration test.
 * Requires no external credentials or network.
 */
define('ABSPATH', __DIR__.'/');
$GLOBALS['t_hooks']=[];
$GLOBALS['t_meta']=[11=>(object)['order_item_id'=>77,'meta_key'=>'_product_id','meta_value'=>'101'],
                    12=>(object)['order_item_id'=>77,'meta_key'=>'public_note','meta_value'=>'hello'],
                    13=>(object)['order_item_id'=>77,'meta_key'=>'_qty','meta_value'=>'1']];
$GLOBALS['t_owners']=[77=>9];
$GLOBALS['t_ledger']=[];
$GLOBALS['t_outcomes']=[];
function t_test($name,$ok){$GLOBALS['t_outcomes'][$name]=(bool)$ok;echo ($ok?'PASS ':'FAIL ').$name."\n";if(!$ok)throw new RuntimeException('test_failed:'.$name);}
function add_filter($hook,$fn,$priority=10,$args=1){$GLOBALS['t_hooks'][$hook][]=[$priority,$fn];usort($GLOBALS['t_hooks'][$hook],static fn($a,$b)=>$a[0]<=>$b[0]);}
function add_action($hook,$fn,$priority=10,$args=1){add_filter($hook,$fn,$priority,$args);}
function apply_filters($hook,$value,...$args){foreach($GLOBALS['t_hooks'][$hook]??[] as $entry)$value=$entry[1]($value,...$args);return $value;}
function get_metadata_by_mid($type,$id){if($type!=='order_item')throw new Exception('type');return isset($GLOBALS['t_meta'][$id])?clone $GLOBALS['t_meta'][$id]:false;}
function wc_get_order_id_by_order_item_id($id){return $GLOBALS['t_owners'][$id]??0;}
function update_metadata_by_mid($type,$id,$value,$key=false){
    if($type!=='order_item')throw new Exception('type');
    $check=apply_filters('update_order_item_metadata_by_mid',null,$id,$value,$key);
    if($check!==null)return (bool)$check;
    if(!isset($GLOBALS['t_meta'][$id]))return false;
    if(is_string($key))$GLOBALS['t_meta'][$id]->meta_key=$key;
    $GLOBALS['t_meta'][$id]->meta_value=$value;
    return true;
}
function delete_metadata_by_mid($type,$id){
    if($type!=='order_item')throw new Exception('type');
    $check=apply_filters('delete_order_item_metadata_by_mid',null,$id);
    if($check!==null)return (bool)$check;
    if(!isset($GLOBALS['t_meta'][$id]))return false;
    unset($GLOBALS['t_meta'][$id]);
    return true;
}
class Twpdb {
    public $dbname='unit_wp'; public $prefix='wp_';public $lockCount=0;public $unlockCount=0;
    public $lockFailure=false;public $onLock=null;public $held=0;
    public function prepare($query,...$args){return $query;}
    public function get_var($query){
        if(str_contains($query,'GET_LOCK')){
            $this->lockCount++;
            if($this->lockFailure)return '0';
            $this->held++;if($this->onLock){$fn=$this->onLock;$this->onLock=null;$fn();}return '1';
        }
        if(str_contains($query,'RELEASE_LOCK')){$this->unlockCount++;$this->held--;return '1';}
        return null;
    }
}
$GLOBALS['wpdb']=new Twpdb();
final class JPWC_Ledger {public static function get($id){return $GLOBALS['t_ledger'][$id]??null;}}
require dirname(__DIR__).'/includes/class-jpwc-items.php';
require dirname(__DIR__).'/includes/class-jpwc-order-guard.php';
JPWC_Order_Guard::boot();
function t_blocked($fn){try{$fn();return false;}catch(RuntimeException $e){return true;}}

// Critical metadata by-ID operations must be serialized with first admission.
t_test('pre-ledger critical update succeeds',update_metadata_by_mid('order_item',11,'102'));
t_test('write really persisted',$GLOBALS['t_meta'][11]->meta_value==='102');
t_test('critical update acquired and released shared lock',$GLOBALS['wpdb']->lockCount===1 && $GLOBALS['wpdb']->unlockCount===1 && $GLOBALS['wpdb']->held===0);
$GLOBALS['t_ledger'][9]=(object)['state'=>'awaiting_payment'];
t_test('invoiced product ID by-mid update blocked',t_blocked(fn()=>update_metadata_by_mid('order_item',11,'999')));
t_test('blocked update leaves meta intact',$GLOBALS['t_meta'][11]->meta_value==='102');
t_test('invoiced quantity by-mid delete blocked',t_blocked(fn()=>delete_metadata_by_mid('order_item',13)));
t_test('blocked delete leaves meta intact',isset($GLOBALS['t_meta'][13]));
t_test('noncritical key renamed into critical key blocked',t_blocked(fn()=>update_metadata_by_mid('order_item',12,'900','_product_id')));
t_test('noncritical key stays noncritical',$GLOBALS['t_meta'][12]->meta_key==='public_note');
t_test('harmless by-mid metadata update allowed',update_metadata_by_mid('order_item',12,'world'));
t_test('harmless by-mid metadata delete allowed',delete_metadata_by_mid('order_item',12));
t_test('harmless metadata deleted',!isset($GLOBALS['t_meta'][12]));
// Fail closed without ever issuing the native data write.
unset($GLOBALS['t_ledger'][9]);
$GLOBALS['wpdb']->lockFailure=true;
t_test('advisory lock unavailable blocks write',t_blocked(fn()=>update_metadata_by_mid('order_item',11,'123')));
t_test('failed lock leaves persisted value',$GLOBALS['t_meta'][11]->meta_value==='102');
$GLOBALS['wpdb']->lockFailure=false;
// Race: another writer moves the item to an invoiced order before our lock.
$GLOBALS['t_owners'][88]=10;
$GLOBALS['t_meta'][14]=(object)['order_item_id'=>88,'meta_key'=>'_product_id','meta_value'=>'300'];
$GLOBALS['wpdb']->onLock=function(){$GLOBALS['t_owners'][88]=99; $GLOBALS['t_ledger'][99]=(object)['state'=>'awaiting_payment'];};
t_test('owner changes while waiting blocks write',t_blocked(fn()=>update_metadata_by_mid('order_item',14,'400')));
t_test('owner-change preserves metadata',$GLOBALS['t_meta'][14]->meta_value==='300');
t_test('all acquired locks released',$GLOBALS['wpdb']->held===0&&$GLOBALS['wpdb']->lockCount===$GLOBALS['wpdb']->unlockCount+1);
// The original key-based metadata path also needs owner verification after lock.
$GLOBALS['t_owners'][88]=10;
unset($GLOBALS['t_ledger'][10]);
$GLOBALS['wpdb']->onLock=function(){$GLOBALS['t_owners'][88]=99;};
t_test('ordinary metadata path detects owner move',
    t_blocked(fn()=>JPWC_Items::updateMeta(null,88,'_product_id','401')));
t_test('owner move does not leak a lock',$GLOBALS['wpdb']->held===0);
// A by-mid hook that already short-circuited must retain the previous response.
add_filter('update_order_item_metadata_by_mid',static fn($v,$id)=>$id===14?false:$v);
t_test('respect prior metadata short-circuit',update_metadata_by_mid('order_item',14,'500')===false);
t_test('short-circuit never persists',$GLOBALS['t_meta'][14]->meta_value==='300');
echo 'Passed: '.count($GLOBALS['t_outcomes'])."\n";
