<?php
/* Cache regression with the unmodified WooCommerce 10.3 order factory. */
namespace Automattic\WooCommerce\Utilities {

    class OrderUtil {public static function orders_cache_usage_is_enabled(){return true;}}
}
namespace Automattic\WooCommerce\Caches {
    class OrderCache {} // reference type; controlled in-memory cache used for this test.
}
namespace {
require __DIR__.'/fixtures/cache-review-bootstrap.php';
require __DIR__.'/fixtures/wc-order-factory-10.3.php';
class WC_Data_Store {
    public static function load($type){return new class {
        public function has_callable($name){return true;}
        public function get_orders_type($ids){return array_fill_keys($ids,'shop_order');}
    };}
}
function wc_get_order_type($name){return ['class_name'=>CachedOrder::class];}
function apply_filters($tag,$value){return $value;}
function wc_get_container(){return new class {
    public function get($cls){return $GLOBALS['testOrderCache'];}
};}
class CachedOrder extends TestOrder {
    public $amount;
    public function __construct($id=7){
        $this->id=$id;
        $this->status=$GLOBALS['persistedOrder']['status'];
        $this->amount=$GLOBALS['persistedOrder']['total'];
        $this->paid=in_array($this->status,['processing','completed'],true);
    }
    public function get_total(){return $this->amount;}
    public function update_status($s,$note=''){$this->status=$s;$GLOBALS['persistedOrder']['status']=$s;}
    public function payment_complete($txn){
        $GLOBALS['paymentCompleteCalls']++;
        $GLOBALS['persistedOrder']['status']='processing';
        return parent::payment_complete($txn);
    }
}
$GLOBALS['persistedOrder']=['status'=>'pending','total'=>'3.00'];
$GLOBALS['paymentCompleteCalls']=0;
$GLOBALS['testOrderCache']=new class {
    public $orders=[], $removed=0;
    public function get($id){return $this->orders[$id]??null;}
    public function set($obj,$id){$this->orders[$id]=$obj;}
    public function remove($id){unset($this->orders[$id]);$this->removed++;}
};
// WooCommerce native factory returns the cached object with no DB query.
$stale=new CachedOrder(7);$GLOBALS['testOrderCache']->set($stale,7);
$GLOBALS['persistedOrder']=['status'=>'cancelled','total'=>'4.00'];
verify('official Woo factory cache returns stale instance without eviction',wc_get_order(7)===$stale);
$fresh=JPWC_Controller::freshOrder(7);
verify('freshOrder evicts native cache and reloads persisted order',$fresh!==$stale && $fresh->get_status()==='cancelled' && $fresh->get_total()==='4.00');
$GLOBALS['persistedOrder']=['status'=>'pending','total'=>'3.00'];
// Claim may race with another worker editing persistent state.
class ClaimRaceDb extends StubDb {
    public function query($sql){
        $result=parent::query($sql);
        if($result===1 && ($this->rows[7]['state']??'')==='delivery_claimed' && empty($GLOBALS['externalChange'])){
            $GLOBALS['externalChange']=true;
            $GLOBALS['persistedOrder']=['status'=>'cancelled','total'=>'4.00'];
        }
        return $result;
    }
}
$GLOBALS['wpdb']=new ClaimRaceDb();
$gateway=new JPWC_Gateway();
$first=$gateway->process_payment(7);
verify('gateway can create invoice with original order',($first['result']??'')==='success');
$row=JPWC_Ledger::get(7);$GLOBALS['merchantId']=$row->merchant_order_id;
JPWC_Ledger::cas($row,['awaiting_payment'],['state'=>'quoted','txn_id'=>'T1','asset'=>'USDT','chain'=>'tron','expires_at'=>'2099-01-01T00:00:00Z']);
$GLOBALS['testOrderCache']->set(new CachedOrder(7),7); // stale pending inside this request
$result=JPWC_Controller::verifyRow(JPWC_Ledger::get(7));
verify('recheck after CAS observes external cancellation',!empty($GLOBALS['externalChange']));
verify('cancelled or repriced order never receives payment_complete',$GLOBALS['paymentCompleteCalls']===0 && $result==='delivery_attention');
verify('ledger does not claim success after cached-order race',JPWC_Ledger::get(7)->state==='delivery_attention');
verify('cache eviction happened repeatedly',$GLOBALS['testOrderCache']->removed>=3);
$realCache=$GLOBALS['testOrderCache'];
$GLOBALS['testOrderCache']=new class {public function get($id){return null;}};
$blocked=false;
try {JPWC_Controller::freshOrder(7);} catch(RuntimeException $e){$blocked=$e->getMessage()==='woo_order_cache_cannot_invalidate';}
verify('cache API unavailable fails closed rather than trusting a cached order',$blocked);
$GLOBALS['testOrderCache']=$realCache;
echo "NOTE: Real Woo factory cache-hit branch and simulated persistence; not a real HPOS/SQL concurrency test.\n";
}
