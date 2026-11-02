<?php
/** Isolated concurrency-guard state tests; native MariaDB multi-process retest REQUIRED. */
define('ABSPATH','/mock-wp/');
class GuardDb {
    public $dbname='guard_test',$prefix='wp_',$locks=[],$nAcq=0,$nRel=0,$deny=false;
    public function prepare($sql,...$args) {return [$sql,$args];}
    public function get_var($query) {
        [$sql,$args]=$query;
        if(str_contains($sql,'GET_LOCK')) {if($this->deny)return 0;$this->nAcq++;$this->locks[$args[0]]=true;return 1;}
        if(str_contains($sql,'RELEASE_LOCK')) {$this->nRel++;unset($this->locks[$args[0]]);return 1;}
        throw new RuntimeException('unexpected SQL');
    }
}
$GLOBALS['wpdb']=new GuardDb();
$GLOBALS['persisted']=['status'=>'on-hold','total'=>'3.00','currency'=>'USD','payment_method'=>'jangopay_woocommerce'];
$GLOBALS['state']='awaiting_payment';
function get_current_blog_id(){return 1;}
class GuardTestOrder {
    private $id,$baseline;
    public function __construct($id=12) {$this->id=$id;$this->baseline=$GLOBALS['persisted'];}
    public function get_id(){return $this->id;}
    public function get_data(){return $this->baseline;}
    public function get_status($ctx='edit'){return $this->baseline['status'];}
    public function get_total($ctx='edit'){return $this->baseline['total'];}
    public function get_currency($ctx='edit'){return $this->baseline['currency'];}
    public function get_payment_method($ctx='edit'){return $this->baseline['payment_method'];}
}
class JPWC_Ledger {public static function get($id){return $id===12?(object)['state'=>$GLOBALS['state']]:null;}}
class JPWC_Controller {public static function freshOrder($id){return new GuardTestOrder($id);}}
class JPWC_Money {public static function usd($amount,$positive=true){return (int)round(((float)$amount)*100);}}
require dirname(__DIR__).'/includes/class-jpwc-order-guard.php';
$n=0;
function verifyGuard($label,$ok){global $n;$n++;if(!$ok)throw new RuntimeException('FAIL '.$label);echo "PASS $n $label\n";}
function expectGuardException($callback){try{$callback();return false;}catch(RuntimeException $e){return true;}}
$first=new GuardTestOrder();
JPWC_Order_Guard::beforeSave($first);JPWC_Order_Guard::afterSave($first);
verifyGuard('normal Woo save acquires and releases advisory lock',$GLOBALS['wpdb']->nAcq===1&&$GLOBALS['wpdb']->nRel===1);
$GLOBALS['state']='delivery_claimed';
$blocked=expectGuardException(static function(){JPWC_Order_Guard::beforeSave(new GuardTestOrder());});
verifyGuard('delivery_claimed has no unguarded order writer',$blocked);
$main=new GuardTestOrder();$internal=new GuardTestOrder();$allowed=false;
JPWC_Order_Guard::fulfill(12,static function($allowPaymentSave)use($main,$internal,&$allowed){
    $allowPaymentSave($main);
    JPWC_Order_Guard::beforeSave($main);JPWC_Order_Guard::afterSave($main);
    JPWC_Order_Guard::beforeSave($internal);JPWC_Order_Guard::afterSave($internal);
    $allowed=true;
});
verifyGuard('nested Woo internal order instance allowed in same guarded fulfillment',$allowed);
verifyGuard('lock reentrant depth balanced',$GLOBALS['wpdb']->nAcq===$GLOBALS['wpdb']->nRel);
$GLOBALS['state']='completed';
$stale=new GuardTestOrder();
$GLOBALS['persisted']['status']='completed';
verifyGuard('stale admin save after completion rejected',expectGuardException(static function()use($stale){JPWC_Order_Guard::beforeSave($stale);}));
verifyGuard('stale save lock released on rejection',$GLOBALS['wpdb']->nAcq===$GLOBALS['wpdb']->nRel);
$GLOBALS['state']='awaiting_payment';
$fresh=new GuardTestOrder();$GLOBALS['wpdb']->deny=true;
verifyGuard('failed GET_LOCK fails closed',expectGuardException(static function()use($fresh){JPWC_Order_Guard::beforeSave($fresh);}));
$GLOBALS['wpdb']->deny=false;
verifyGuard('denied lock leaves no local hold',$GLOBALS['wpdb']->nAcq===$GLOBALS['wpdb']->nRel);
$GLOBALS['state']='delivery_claimed';
$completed=false;
try {JPWC_Order_Guard::fulfill(12,static function($allowPaymentSave){throw new RuntimeException('simulate Woo failure');});}catch(RuntimeException $e){$completed=true;}
verifyGuard('payment failure releases advisory lock',$completed && $GLOBALS['wpdb']->nAcq===$GLOBALS['wpdb']->nRel);
$GLOBALS['persisted']=['status'=>'on-hold','total'=>'30.00','currency'=>'USD','payment_method'=>'jangopay_woocommerce'];
$changed=new GuardTestOrder();
$GLOBALS['persisted']['total']='35.00';
verifyGuard('concurrent reprice rejects stale Woo order save',expectGuardException(static function()use($changed){JPWC_Order_Guard::beforeSave($changed);}));
verifyGuard('all advisory acquisitions balanced',$GLOBALS['wpdb']->nAcq===$GLOBALS['wpdb']->nRel);
echo "NOTE: isolated guard model only; no real WP/MySQL concurrent execution in this file.\n";
