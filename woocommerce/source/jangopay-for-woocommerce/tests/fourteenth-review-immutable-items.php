<?php
/** V1.0.15 real source item fingerprint and independent CRUD hook unit tests. */
define('ABSPATH','/fake/');
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE);}
class Items115Line {
    public $id,$p,$v,$q,$total,$subtotal,$name,$order;
    function __construct($id,$p,$v,$q,$total,$name,$order=123){$this->id=$id;$this->p=$p;$this->v=$v;$this->q=$q;$this->total=$total;$this->subtotal=$total;$this->name=$name;$this->order=$order;}
    function get_id(){return $this->id;} function get_product_id(){return $this->p;} function get_variation_id(){return $this->v;}
    function get_quantity(){return $this->q;} function get_total(){return $this->total;} function get_subtotal(){return $this->subtotal;}
    function get_name(){return $this->name;} function get_order_id(){return $this->order;} function get_type(){return 'line_item';}
}
class Items115Order {public $lines=[]; function __construct($lines){$this->lines=$lines;} function get_items($type){return $this->lines;} function is_paid(){return false;} function get_status(){return 'on-hold';} function get_payment_method(){return 'jangopay_woocommerce';} function get_currency(){return 'USD';} function get_total(){return '3.00';}}
class JPWC_Ledger {public static $orders=[]; public static function get($id){return self::$orders[$id]??null;}}
$GLOBALS['persistedItems']=[];class WC_Order_Factory {public static function get_order_item($id){return $GLOBALS['persistedItems'][$id]??false;}}
class Item115Db {
    public $dbname='test', $prefix='wp_', $taken=0, $released=0;
    public function prepare($sql,...$args){return [$sql,$args];}
    public function get_var($q){if(str_contains($q[0],'GET_LOCK')){$this->taken++;return '1';}if(str_contains($q[0],'RELEASE_LOCK')){$this->released++;return '1';}throw new RuntimeException('Unexpected SQL');}
}
$GLOBALS['wpdb']=new Item115Db();
require dirname(__DIR__).'/includes/class-jpwc-order-guard.php';
require dirname(__DIR__).'/includes/class-jpwc-items.php';
require dirname(__DIR__).'/includes/class-jpwc-money.php';
require dirname(__DIR__).'/includes/class-jpwc-controller.php';
$n=0;function v115($s,$ok){global $n;$n++;if(!$ok)throw new RuntimeException('FAIL '.$s);echo 'PASS '.$n.' '.$s."\n";}
$a=new Items115Line(18,201,0,1,'3.00','eBook A');
$b=new Items115Line(19,202,11,2,'1.00','Subscription B');
$order=new Items115Order([$a,$b]);
$hash=JPWC_Items::fingerprint($order);$row=(object)['items_hash'=>$hash,'state'=>'awaiting_payment'];JPWC_Ledger::$orders=[123=>$row];
JPWC_Items::assertRow($row,$order);v115('stored product snapshot matches',true);
v115('order of loaded item array does not matter',$hash===JPWC_Items::fingerprint(new Items115Order([$b,$a])));
v115('different numeric presentation remains stable',$hash===JPWC_Items::fingerprint(new Items115Order([new Items115Line(18,201,0,1,'3','eBook A'),$b])));
foreach ([
 ['same price different product',new Items115Line(18,999,0,1,'3.00','eBook A')],
 ['same total different variation',new Items115Line(18,201,99,1,'3.00','eBook A')],
 ['same total different quantity',new Items115Line(18,201,0,2,'3.00','eBook A')],
 ['same total different fulfillment item name',new Items115Line(18,201,0,1,'3.00','eBook Z')],
 ['replaced line item id',new Items115Line(22,201,0,1,'3.00','eBook A')]
] as [$name,$changed]) {
  $bad=false;try{JPWC_Items::assertRow($row,new Items115Order([$changed,$b]));}catch(RuntimeException $e){$bad=$e->getMessage()==='order_items_changed';}
  v115($name.' must block delivery',$bad);
}
$bad=false;try{JPWC_Items::assertRow((object)['items_hash'=>null],$order);}catch(RuntimeException $e){$bad=true;}
v115('legacy invoice without snapshot cannot auto-deliver',$bad);
$bad=false;try{JPWC_Items::assertRow((object)[],$order);}catch(RuntimeException $e){$bad=true;}
v115('absent migration column fails closed',$bad);
$row->invoice_id='INV-115';$row->amount_cents=300;
v115('valid unchanged order has official hosted URL',JPWC_Controller::hostedForOrder($order,$row)==='https://app.jangopay.org/pay/INV-115');
v115('valid unchanged order advertised payable',JPWC_Controller::canPay($order,$row)===true);
$swapped=new Items115Order([new Items115Line(18,999,0,1,'3.00','eBook A'),$b]);
v115('changed order cannot redirect to hosted payment',JPWC_Controller::hostedForOrder($swapped,$row)===null);
v115('changed order is not presented as payable',JPWC_Controller::canPay($swapped,$row)===false);
$GLOBALS['persistedItems']=[18=>$a,19=>$b];
$unchanged=new Items115Line(18,201,0,1,'3','eBook A');
JPWC_Items::beforeItemSave($unchanged);JPWC_Items::afterItemSave($unchanged);v115('unchanged WC order item save accepted',true);
$bad=false;try{JPWC_Items::beforeItemSave(new Items115Line(18,999,0,1,'3.00','eBook A'));}catch(RuntimeException $e){$bad=true;}
v115('independently saved modified line item rejected',$bad);
$bad=false;try{JPWC_Items::beforeDelete(18);}catch(RuntimeException $e){$bad=true;}
v115('direct deletion of invoiced order line rejected',$bad);
$bad=false;try{JPWC_Items::beforeItemSave(new Items115Line(0,999,0,1,'3.00','eBook C'));}catch(RuntimeException $e){$bad=true;}
v115('direct addition to invoiced order rejected',$bad);
$c=new Items115Line(55,901,0,1,'2.00','Ordinary item',456);
$GLOBALS['persistedItems'][55]=$c;
$ordinary=new Items115Line(55,902,0,1,'2.00','Changed ordinary item',456);
JPWC_Items::beforeItemSave($ordinary); JPWC_Items::afterItemSave($ordinary);
JPWC_Items::beforeDelete(55); JPWC_Items::afterDelete(55);
v115('non-JangoPay order item edit and delete unaffected',true);
JPWC_Items::assertRow($row,$order);v115('rejected attempts do not modify snapshot',$hash===JPWC_Items::fingerprint($order));
echo "NOTE isolated real-source hooks, not native WooCommerce/MariaDB or remote provider acceptance\n";
