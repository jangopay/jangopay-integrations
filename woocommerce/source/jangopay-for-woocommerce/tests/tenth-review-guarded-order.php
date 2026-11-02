<?php
/**
 * Model-focused test for WooCommerce's important exception boundary and
 * re-entrant HPOS order save. NOT a real WordPress/MariaDB native test.
 */
define('ABSPATH','/fake-wp/');
$GLOBALS['db_orders']=[12=>['status'=>'on-hold','total'=>'3.00','currency'=>'USD','payment_method'=>'jangopay_woocommerce']];
$GLOBALS['db_lock_fail']=false;$GLOBALS['metrics']=['completed'=>0,'cancelled'=>0,'permissions'=>0,'mark'=>false,'core_swallowed'=>0];
class Guarded110Db {
 public $dbname='review',$prefix='wp_',$locks=[],$acquired=0,$released=0;
 public function prepare($sql,...$args){return [$sql,$args];}
 public function get_var($query){[$q,$args]=$query;
   if(str_contains($q,'GET_LOCK')){if($GLOBALS['db_lock_fail'])return null;$this->acquired++;$this->locks[$args[0]]=true;return 1;}
   if(str_contains($q,'RELEASE_LOCK')){$this->released++;unset($this->locks[$args[0]]);return 1;}
   throw new RuntimeException('Unexpected advisory statement'); }
}
$GLOBALS['wpdb']=new Guarded110Db();
function add_filter($name,$f,$priority=10,$arg=1){$GLOBALS['filters'][$name][]=$f;}
function add_action($name,$f,$priority=10,$arg=1){$GLOBALS['actions'][$name][]=$f;}
function get_current_blog_id(){return 1;}
class JPWC_Ledger {public static $state='awaiting_payment';public static function get($id){return (int)$id===12?(object)['state'=>self::$state]:null;}}
class JPWC_Money {public static function usd($num,$positive=true){return (int)round((float)$num*100);}}
class JPWC_Controller {public static function freshOrder($id){return new JPWC_Guarded_Order((int)$id);}}
/** Mock replicates the relevant WC_Abstract_Order/WC_Order catch and transitions. */
class WC_Order {
    public function get_items($type='line_item'){return []; }

 protected $id,$data,$changes=[],$status_transition=false;
 public function __construct($id){$this->id=$id;$this->data=$GLOBALS['db_orders'][$id];}
 public function get_id(){return $this->id;}
 public function get_data(){return $this->data;}
 public function get_status($ctx='view'){return $this->get('status');}
 public function get_total($ctx='view'){return $this->get('total');}
 public function get_currency($ctx='view'){return $this->get('currency');}
 public function get_payment_method($ctx='view'){return $this->get('payment_method');}
 protected function get($key){return $this->changes[$key]??$this->data[$key];}
 public function set_status($val){$from=$this->get_status();$this->changes['status']=$val;if($from!==$val)$this->status_transition=['to'=>$val,'from'=>$from];}
 public function set_total($val){$this->changes['total']=$val;}
 public function save(){
   try {if(!empty($GLOBALS['store_fail']))throw new Exception('Simulated swallowed datastore exception');
       $GLOBALS['db_orders'][$this->id]=array_merge($GLOBALS['db_orders'][$this->id],$this->changes);
       $this->data=$GLOBALS['db_orders'][$this->id];$this->changes=[];
   } catch(Exception $e){$GLOBALS['metrics']['core_swallowed']++;}
   $this->status_transition();return $this->id;
 }
 protected function status_transition(){
    $t=$this->status_transition;$this->status_transition=false;
    if(!$t)return;
    $GLOBALS['metrics'][$t['to']]=($GLOBALS['metrics'][$t['to']]??0)+1;
    if($t['to']==='completed'){
      // Mimics WooCommerce HPOS getting a NEW instance for marking downloads.
      $internal=JPWC_Controller::freshOrder($this->id);
      $internal->save();
      if(!$GLOBALS['metrics']['mark']){$GLOBALS['metrics']['permissions']++;$GLOBALS['metrics']['mark']=true;}
    }
 }
 public function update_status($status){try{$this->set_status($status);$this->save();return true;}catch(Exception $e){return false;}}
 public function payment_complete($txn){$this->set_status('completed');$this->save();return true;}
 public function is_paid(){return $this->get_status()==='completed';}
}
require dirname(__DIR__).'/includes/class-jpwc-order-guard.php';
require dirname(__DIR__).'/includes/class-jpwc-guarded-order.php';
$n=0;function assert110($label,$condition){global $n;$n++;if(!$condition)throw new RuntimeException('FAIL '.$label);echo "PASS $n $label\n";}
JPWC_Order_Guard::boot();
assert110('factory routes only ledger-backed standard shop_order',JPWC_Order_Guard::orderClass('WC_Order','shop_order',12)==='JPWC_Guarded_Order');
assert110('other WC order subclasses kept intact',JPWC_Order_Guard::orderClass('SpecialOrder','shop_order',12)==='SpecialOrder');
$stale=new JPWC_Guarded_Order(12);$complete=new JPWC_Guarded_Order(12);
JPWC_Ledger::$state='delivery_claimed';
JPWC_Order_Guard::fulfill(12,function($allow)use($complete){
    $allow($complete);
    $intruder=new JPWC_Guarded_Order(12);$intruder->set_status('cancelled');
    assert110('different in-process instance cannot change critical status during delivery',$intruder->update_status('cancelled')===false);
    $complete->payment_complete('T1');
});
JPWC_Ledger::$state='completed';
assert110('normal completed status stored', $GLOBALS['db_orders'][12]['status']==='completed');
assert110('nested HPOS object save accepted and one download grant',$GLOBALS['metrics']['permissions']===1 && $GLOBALS['metrics']['mark']);
assert110('completed transition ran exactly once',$GLOBALS['metrics']['completed']===1);
assert110('stale cancellation reports false', $stale->update_status('cancelled')===false);
assert110('stale cancellation causes no ghost transition',$GLOBALS['metrics']['cancelled']===0 && $GLOBALS['db_orders'][12]['status']==='completed');
$fresh=new JPWC_Guarded_Order(12);$GLOBALS['db_lock_fail']=true;
assert110('GET_LOCK unavailable yields failed update_status',$fresh->update_status('cancelled')===false);
assert110('unavailable lock causes no ghost transitions or persistence',$GLOBALS['metrics']['cancelled']===0 && $GLOBALS['db_orders'][12]['status']==='completed');
$GLOBALS['db_lock_fail']=false;
$failed=new JPWC_Guarded_Order(12);$GLOBALS['store_fail']=true;
assert110('swallowed datastore error detected before transition',$failed->update_status('cancelled')===false);
assert110('swallowed store error has zero cancellation effects',$GLOBALS['metrics']['cancelled']===0 && $GLOBALS['db_orders'][12]['status']==='completed');
unset($GLOBALS['store_fail']);
assert110('balanced MySQL advisory lock acquisition/release',$GLOBALS['wpdb']->acquired===$GLOBALS['wpdb']->released);
assert110('non-ledger existing order also receives a guarded class',JPWC_Order_Guard::orderClass('WC_Order','shop_order',99)==='JPWC_Guarded_Order');
echo "NOTE: Synthetic Woo save semantics, not native WooCommerce double-process verification.\n";
