<?php
/**
 * Pre-ledger factory-object regression. Simulated WooCommerce, not real DB.
 * Unlike the former tests, order instances are materialized BEFORE a JPWC
 * ledger exists, and their save() is attempted AFTER ledger/payment changes.
 */
define('ABSPATH', '/fake-wp/');
define('JPWC_DIR', dirname(__DIR__).'/');
$GLOBALS['saved_orders'] = [
    12 => ['status'=>'pending','total'=>'3.00','currency'=>'USD','payment_method'=>'jangopay_woocommerce'],
    21 => ['status'=>'pending','total'=>'4.00','currency'=>'USD','payment_method'=>'cheque'],
];
$GLOBALS['ledger_records'] = [];
$GLOBALS['events'] = [];
$GLOBALS['advisory'] = ['get'=>0,'release'=>0];
function add_action($name,$callback,$priority=10,$n=1) {}
function add_filter($name,$callback,$priority=10,$n=1) {}
function get_current_blog_id() {return 1;}
class PreledgerDb {
    public $dbname='preledger', $prefix='wp_';
    public function prepare($sql,...$params) {return [$sql,$params];}
    public function get_var($tuple) {
        if (strpos($tuple[0],'GET_LOCK')!==false) {$GLOBALS['advisory']['get']++;return 1;}
        if (strpos($tuple[0],'RELEASE_LOCK')!==false) {$GLOBALS['advisory']['release']++;return 1;}
        throw new RuntimeException('Unexpected SQL');
    }
}
$GLOBALS['wpdb'] = new PreledgerDb();
class WC_Order {
    public function get_items($type='line_item'){return []; }

    protected $id, $data, $changes=[], $transition=null;
    public function __construct($id) {$this->id=(int)$id;$this->data=$GLOBALS['saved_orders'][$id] ?? [];}
    public function get_id() {return $this->id;}
    public function get_data() {return $this->data;}
    protected function val($p) {return $this->changes[$p]??$this->data[$p];}
    public function get_status($ctx='view') {return $this->val('status');}
    public function get_total($ctx='view') {return $this->val('total');}
    public function get_currency($ctx='view') {return $this->val('currency');}
    public function get_payment_method($ctx='view') {return $this->val('payment_method');}
    public function set_status($s) {$before=$this->get_status();$this->changes['status']=$s;if($s!==$before)$this->transition=[$before,$s];}
    public function set_total($n) {$this->changes['total']=$n;}
    public function save() {
        $GLOBALS['saved_orders'][$this->id]=array_merge($GLOBALS['saved_orders'][$this->id],$this->changes);
        $this->data=$GLOBALS['saved_orders'][$this->id];$this->changes=[];
        $this->status_transition();
        return $this->id;
    }
    protected function status_transition() {
        if($this->transition){$GLOBALS['events'][]=['order'=>$this->id,'from'=>$this->transition[0],'to'=>$this->transition[1]];$this->transition=null;}
    }
    public function update_status($status) {
        try {$this->set_status($status);$this->save();return true;}
        catch(Exception $e) {return false;}
    }
}
class JPWC_Ledger {
    public static function get($id) {return $GLOBALS['ledger_records'][$id]??null;}
}
class JPWC_Controller {
    public static function freshOrder($id) {return makePreledgerOrder($id);}
}
require JPWC_DIR.'includes/class-jpwc-money.php';
require JPWC_DIR.'includes/class-jpwc-order-guard.php';
require JPWC_DIR.'includes/class-jpwc-guarded-order.php';
require __DIR__.'/fixtures/woo-admin-override-order.php';
function makePreledgerOrder($id, $base='\\Automattic\\WooCommerce\\Admin\\Overrides\\Order') {
    $cls=JPWC_Order_Guard::orderClass($base,'shop_order',$id);
    return new $cls($id);
}
$n=0;
function ok113($name,$ok) {global $n;$n++;if(!$ok)throw new RuntimeException('FAIL '.$name);echo "PASS $n $name\n";}

// This was the V1.0.12 failure: factory returns unguarded Woo Admin class.
$preloadedCancel=makePreledgerOrder(12);
$preloadedPrice=makePreledgerOrder(12);
ok113('preledger factory returns guarded Admin order',$preloadedCancel instanceof JPWC_Guarded_Order_Contract);
ok113('retains WooCommerce Admin override features', $preloadedCancel instanceof \Automattic\WooCommerce\Admin\Overrides\Order && $preloadedCancel->adminOrderFeature()==='admin-core-feature-preserved');
ok113('base WC order is also guarded before JPWC ledger',makePreledgerOrder(12,'WC_Order') instanceof JPWC_Guarded_Order_Contract);
ok113('refund factory is unchanged',JPWC_Order_Guard::orderClass('WC_Order','shop_order_refund',12)==='WC_Order');
$unrelated=makePreledgerOrder(21);
$lockCount=$GLOBALS['advisory']['get'];
ok113('ordinary nonledger order may be cancelled',$unrelated->update_status('cancelled')===true && $GLOBALS['saved_orders'][21]['status']==='cancelled');
ok113('ordinary nonledger save uses short-lived common lock',$GLOBALS['advisory']['get']===$lockCount+1 && $GLOBALS['advisory']['get']===$GLOBALS['advisory']['release']);

// A second process creates invoice/ledger and updates normal order status.
$GLOBALS['ledger_records'][12]=(object)['state'=>'awaiting_payment'];
$checkout=makePreledgerOrder(12);
$checkout->set_status('on-hold');
$checkout->save();
ok113('new ledger causes protected normal save',$GLOBALS['saved_orders'][12]['status']==='on-hold' && $GLOBALS['advisory']['get']>$lockCount);

// The original objects remain the same PHP instances but must guard at save.
$GLOBALS['ledger_records'][12]->state='delivery_claimed';
$badCancelled=null;$badPrice=null;
JPWC_Order_Guard::fulfill(12,function($allow)use($preloadedCancel,$preloadedPrice,&$badCancelled,&$badPrice){
    $payment=makePreledgerOrder(12);
    $allow($payment);
    $badCancelled=$preloadedCancel->update_status('cancelled');
    $preloadedPrice->set_total('30.00');
    try {$preloadedPrice->save();$badPrice='allowed';} catch(RuntimeException $e) {$badPrice=$e->getMessage();}
    $payment->set_status('completed');
    $payment->save();
});
ok113('preledger stale cancellation rejected during fulfillment',$badCancelled===false);
ok113('preledger stale repricing rejected during fulfillment',$badPrice!==null && $badPrice!=='allowed');
ok113('only verified payment status stored',$GLOBALS['saved_orders'][12]['status']==='completed');
ok113('paid amount unchanged at original USD 3.00',$GLOBALS['saved_orders'][12]['total']==='3.00');
ok113('no ghost cancellation event',count(array_filter($GLOBALS['events'],static fn($e)=>$e['order']===12 && $e['to']==='cancelled'))===0);

$GLOBALS['ledger_records'][12]->state='completed';
ok113('preledger stale object cannot cancel after payment',$preloadedCancel->update_status('cancelled')===false);
$retryReprice=false;
try {$preloadedPrice->save();}catch(RuntimeException $e){$retryReprice=true;}
ok113('preledger stale object cannot reprice after payment',$retryReprice);
ok113('postpayment order state/amount remain consistent',$GLOBALS['saved_orders'][12]['status']==='completed' && $GLOBALS['saved_orders'][12]['total']==='3.00');
ok113('database advisory locks are balanced',$GLOBALS['advisory']['get']===$GLOBALS['advisory']['release']);
echo "NOTE: synthetic pre-ledger stale-order ordering; native CPT/HPOS two-process acceptance required.\n";
