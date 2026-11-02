<?php
/** Simulated default WooCommerce Admin factory: not a real CPT/HPOS test. */
define('ABSPATH', '/fake-wp/');
define('JPWC_DIR', dirname(__DIR__).'/');
$GLOBALS['order_db'] = [12=>['status'=>'on-hold','total'=>'3.00','currency'=>'USD','payment_method'=>'jangopay_woocommerce']];
$GLOBALS['ledger_on']=true;
$GLOBALS['saved_status_hooks']=0;
function get_current_blog_id() {return 1;}
class AdminFactoryDb {
    public $dbname='review', $prefix='wp_', $locked=0, $released=0;
    public function prepare($q, ...$args) {return [$q,$args];}
    public function get_var($query) {
        if (str_contains($query[0],'GET_LOCK')) { $this->locked++;return 1; }
        if (str_contains($query[0],'RELEASE_LOCK')) { $this->released++;return 1; }
        throw new RuntimeException('unexpected query');
    }
}
$GLOBALS['wpdb']=new AdminFactoryDb();
function add_filter($hook,$callback,$priority=10,$args=1) {}
function add_action($hook,$callback,$priority=10,$args=1) {}
class WC_Order {
    public function get_items($type='line_item'){return []; }

    protected $id, $baseline, $changes=[], $transition=false;
    public function __construct($id) { $this->id=$id; $this->baseline=$GLOBALS['order_db'][$id]??[]; }
    public function get_id(){return $this->id;}
    public function get_data(){return $this->baseline;}
    public function get_status($c='view'){return $this->changes['status']??$this->baseline['status'];}
    public function get_total($c='view'){return $this->changes['total']??$this->baseline['total'];}
    public function get_currency($c='view'){return $this->changes['currency']??$this->baseline['currency'];}
    public function get_payment_method($c='view'){return $this->changes['payment_method']??$this->baseline['payment_method'];}
    public function set_status($status){$from=$this->get_status();$this->changes['status']=$status;if($from!==$status)$this->transition=true;}
    public function save(){
        $GLOBALS['order_db'][$this->id]=array_merge($GLOBALS['order_db'][$this->id], $this->changes);
        $this->baseline=$GLOBALS['order_db'][$this->id];$this->changes=[];
        $this->status_transition();return $this->id;
    }
    protected function status_transition(){if($this->transition) $GLOBALS['saved_status_hooks']++;$this->transition=false;}
}
require dirname(__DIR__).'/includes/class-jpwc-money.php';
class JPWC_Ledger {public static $state='delivery_claimed'; public static function get($id){return $id===12 && $GLOBALS['ledger_on'] ? (object)['state'=>self::$state] : null;}}
class JPWC_Controller {
    public static function freshOrder($id){return new (JPWC_Order_Guard::orderClass('\\Automattic\\WooCommerce\\Admin\\Overrides\\Order','shop_order',$id))($id);}
}
require dirname(__DIR__).'/includes/class-jpwc-order-guard.php';
require dirname(__DIR__).'/includes/class-jpwc-guarded-order.php';
require __DIR__.'/fixtures/woo-admin-override-order.php';
$n=0;
function check112($label,$cond){global $n;$n++;if(!$cond)throw new RuntimeException('FAIL '.$label);echo "PASS $n $label\n";}
check112('native Woo Admin subclass is supported before ledger exists',JPWC_Order_Guard::supportsOrder(new \Automattic\WooCommerce\Admin\Overrides\Order(12)));
$GLOBALS['ledger_on']=false;
check112('native Woo Admin class guarded even before ledger exists',JPWC_Order_Guard::orderClass('\\Automattic\\WooCommerce\\Admin\\Overrides\\Order','shop_order',12)==='JPWC_Guarded_Admin_Order');
$GLOBALS['ledger_on']=true;
$actual=JPWC_Order_Guard::orderClass('\\Automattic\\WooCommerce\\Admin\\Overrides\\Order','shop_order',12);
check112('default Woo Admin factory selects guarded subclass',$actual==='JPWC_Guarded_Admin_Order');
$order=new $actual(12);
check112('guarded subclass keeps native Admin behavior',$order instanceof \Automattic\WooCommerce\Admin\Overrides\Order && $order->adminOrderFeature()==='admin-core-feature-preserved');
check112('guarded subclass accepted as delivery proof',JPWC_Order_Guard::isGuarded($order));
check112('base WC_Order keeps own guarded class',JPWC_Order_Guard::orderClass('WC_Order','shop_order',12)==='JPWC_Guarded_Order');
check112('refunds are not rewritten',JPWC_Order_Guard::orderClass('WC_Order','shop_order_refund',12)==='WC_Order');
class ThirdPartyOrder extends WC_Order {}
check112('unknown subclass not rewritten',JPWC_Order_Guard::orderClass('ThirdPartyOrder','shop_order',12)==='ThirdPartyOrder');
check112('third-party subclass rejected before invoice',!JPWC_Order_Guard::supportsOrder(new ThirdPartyOrder(12)));
JPWC_Order_Guard::fulfill(12, function($allow) use($order){
   $allow($order);
   $order->set_status('completed');$order->save();
});
check112('Admin order payment save accepted with guard',$GLOBALS['order_db'][12]['status']==='completed');
check112('payment status transition invoked once',$GLOBALS['saved_status_hooks']===1);
check112('shared advisory locks balanced',$GLOBALS['wpdb']->locked===$GLOBALS['wpdb']->released);
