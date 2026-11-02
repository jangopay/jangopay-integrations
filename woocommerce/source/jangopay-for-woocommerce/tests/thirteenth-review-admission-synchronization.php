<?php
/**
 * V1.0.14 admission/save serialization regression. In-memory Woo + lock-aware
 * database stub. This is NOT a native MySQL two-worker concurrency test.
 */
define('ABSPATH', '/fake-wp/');
$GLOBALS['persist']=[12=>['status'=>'pending','total'=>'3.00','currency'=>'USD','payment_method'=>'jangopay_woocommerce'],21=>['status'=>'pending','total'=>'4.00','currency'=>'USD','payment_method'=>'cheque'],31=>['status'=>'pending','total'=>'5.00','currency'=>'USD','payment_method'=>'jangopay_woocommerce']];
$GLOBALS['rows']=[];$GLOBALS['events']=[];$GLOBALS['assertions']=0;
function check114($name,$okay){$GLOBALS['assertions']++;if(!$okay)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$GLOBALS['assertions'].' '.$name."\n";}
function add_action($name,$fn,$p=10,$a=1){}function add_filter($name,$fn,$p=10,$a=1){}function get_current_blog_id(){return 1;}
function home_url($path='/'){return 'https://example.test'.$path;}function wp_json_encode($v){return json_encode($v);}function current_time($fmt,$utc=false){return '2026-10-20 00:00:00';}
class Admission114Db {
    public $dbname='test',$prefix='wp_',$lockHeld=false,$acquired=0,$released=0,$inserts=0,$failing=false;
    public function prepare($query,...$args){return [$query,$args];}
    public function get_var($query){[$sql,$args]=$query;
        if(str_contains($sql,'GET_LOCK')){
            if($this->failing)return null;
            if($this->lockHeld)throw new RuntimeException('Fake DB: non-reentrant named lock');
            $this->lockHeld=true;$this->acquired++;return 1;
        }
        if(str_contains($sql,'RELEASE_LOCK')){
            if(!$this->lockHeld)throw new RuntimeException('Fake DB: releasing unheld lock');
            $this->lockHeld=false;$this->released++;return 1;
        }
        throw new RuntimeException('Unexpected SQL');
    }
    public function insert($table,$data,$formats){
        if(!$this->lockHeld)throw new RuntimeException('unsafe_ledger_insert_not_serialized');
        $this->inserts++;$id=$data['order_id'];if(isset($GLOBALS['rows'][$id]))return false;
        $data['id']=$id;$data['invoice_id']=null;$data['txn_id']=null;
        $GLOBALS['rows'][$id]=(object)$data;return 1;
    }
    public function get_row($query){[$sql,$args]=$query;return $GLOBALS['rows'][$args[0]]??null;}
}
$GLOBALS['wpdb']=new Admission114Db();
class WC_Order {
    public function get_items($type='line_item'){return []; }

    protected $id,$data,$updates=[],$transition=null;
    public function __construct($id){$this->id=$id;$this->data=$GLOBALS['persist'][$id];}
    public function get_id(){return $this->id;}
    public function get_order_key(){return "wc_113-test-key";}
    public function get_data(){return $this->data;}
    private function get($field){return $this->updates[$field]??$this->data[$field];}
    public function get_status($ctx='view'){return $this->get('status');}
    public function get_total($ctx='view'){return $this->get('total');}
    public function get_currency($ctx='view'){return $this->get('currency');}
    public function get_payment_method($ctx='view'){return $this->get('payment_method');}
    public function is_paid(){return $this->get_status()==='completed';}
    public function set_status($status){$old=$this->get_status();$this->updates['status']=$status;if($old!==$status)$this->transition=[$old,$status];}
    public function set_total($total){$this->updates['total']=$total;}
    public function save(){
        $GLOBALS['events'][]=['phase'=>'pre-datastore','held'=>$GLOBALS['wpdb']->lockHeld,'order'=>$this->id];
        if(isset($GLOBALS['beforeDataStore']) && is_callable($GLOBALS['beforeDataStore']))($GLOBALS['beforeDataStore'])();
        $GLOBALS['persist'][$this->id]=array_merge($GLOBALS['persist'][$this->id],$this->updates);
        $this->data=$GLOBALS['persist'][$this->id];$this->updates=[];
        $this->status_transition();return $this->id;
    }
    protected function status_transition(){
        if($this->transition){$GLOBALS['events'][]=['phase'=>'status','to'=>$this->transition[1],'held'=>$GLOBALS['wpdb']->lockHeld,'order'=>$this->id];$this->transition=null;}
    }
    public function update_status($status){try{$this->set_status($status);$this->save();return true;}catch(Exception $e){return false;}}
}
class JPWC_Controller {
    public static function freshOrder($id){return new JPWC_Guarded_Order($id);}
    public static function orderPayable($order,$row){return !$order->is_paid() && in_array($order->get_status(),['pending','on-hold'],true) && $order->get_payment_method()==='jangopay_woocommerce' && $order->get_currency()==='USD' && JPWC_Money::usd((string)$order->get_total())===$row->amount_cents;}
}
require dirname(__DIR__).'/includes/class-jpwc-money.php';
require dirname(__DIR__).'/includes/class-jpwc-ledger.php';
require dirname(__DIR__).'/includes/class-jpwc-order-guard.php';
require dirname(__DIR__).'/includes/class-jpwc-guarded-order.php';

// Cancel takes shared lock BEFORE the first order has any ledger. Its outer
// save remains locked through native status hooks; a later reserve must see
// the new cancelled state and refuse creating a payable invoice.
$a=new JPWC_Guarded_Order(12);check114('initially no ledger',JPWC_Ledger::get(12)===null);
check114('pre-ledger cancellation allowed',$a->update_status('cancelled'));
$saveEvents=array_values(array_filter($GLOBALS['events'],static fn($e)=>$e['order']===12));
check114('pre-ledger datastore save was locked',$saveEvents[0]['held']===true);
check114('pre-ledger Woo status hooks remained locked',$saveEvents[1]['held']===true);
$blocked=false;try{JPWC_Ledger::reserve($a,300,['USDT:bsc']);}catch(RuntimeException $e){$blocked=$e->getMessage()==='order_changed_before_reservation';}
check114('first reservation cannot follow cancellation as if still payable',$blocked);
check114('no ledger was inserted after cancellation',JPWC_Ledger::get(12)===null && $GLOBALS['wpdb']->inserts===0);

// Reopen using an ordinary guarded save, then reserve while holding the lock.
$pending=new JPWC_Guarded_Order(12);$pending->update_status('pending');
$row=JPWC_Ledger::reserve($pending,300,['USDT:bsc']);
check114('reservation acquires the same lock and persists a single row',$row && $row->state==='reserved' && $GLOBALS['wpdb']->inserts===1);
check114('asset snapshot remains intact',json_decode($row->allowed_assets,true)===['USDT:bsc']);
// A stale order obtained before reservation remains guarded; the row now
// forces a live comparison, so it cannot overwrite a subsequent payment.
$stale=new JPWC_Guarded_Order(12);
JPWC_Order_Guard::synchronized(12,static function(){
    $GLOBALS['rows'][12]->state='completed';
    $GLOBALS['persist'][12]['status']='completed';
});
check114('stale update_status reports failure',$stale->update_status('cancelled')===false);
check114('stale cancelled status never persisted',$GLOBALS['persist'][12]['status']==='completed');
check114('no phantom cancellation event after stale save',count(array_filter($GLOBALS['events'],static fn($e)=>$e['order']===12 && $e['phase']==='status' && $e['to']==='cancelled'))===1);

// Same PHP connection can re-enter WooCommerce from a before-save hook.
// MySQL advisory locks are reentrant, so it must NOT issue a first invoice
// while the outer save is still holding staged, uncommitted state.
$save31=new JPWC_Guarded_Order(31);$nestedError=null;
$GLOBALS['beforeDataStore']=static function()use($save31,&$nestedError){
    try { JPWC_Ledger::reserve($save31,500,['USDT:bsc']); }
    catch(RuntimeException $e) { $nestedError=$e->getMessage(); }
};
check114('reentrant save is allowed to finish',$save31->save()===31);
unset($GLOBALS['beforeDataStore']);
check114('same-request reservation during uncommitted save is rejected',$nestedError==='order_save_in_progress' && JPWC_Ledger::get(31)===null);
check114('reservation after outer save can proceed',JPWC_Ledger::reserve(new JPWC_Guarded_Order(31),500,['USDT:bsc'])->state==='reserved');

// Not-JangoPay orders now take this same short-lived lock. This intentional
// design change closes races where an existing ordinary order is converted
// to JangoPay in another connection while its original save is in flight.
$ordinary=new JPWC_Guarded_Order(21);$before=$GLOBALS['wpdb']->acquired;
check114('ordinary order still supports native cancellation',$ordinary->update_status('cancelled'));
check114('ordinary order now uses bounded per-order lock',$GLOBALS['wpdb']->acquired===$before+1 && $GLOBALS['wpdb']->lockHeld===false);
$other=new JPWC_Guarded_Order(21);$GLOBALS['wpdb']->failing=true;
check114('unavailable lock fails before ordinary order side effects',$other->update_status('pending')===false && $GLOBALS['persist'][21]['status']==='cancelled');
$GLOBALS['wpdb']->failing=false;
check114('all locks released',$GLOBALS['wpdb']->acquired===$GLOBALS['wpdb']->released && !$GLOBALS['wpdb']->lockHeld);
echo "NOTE: in-memory ordering tests only, original 4 native MariaDB admission-window cases still need independent retest.\n";
