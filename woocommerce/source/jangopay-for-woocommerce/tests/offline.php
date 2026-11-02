<?php
/** Pure PHP tests: no WordPress database / server / real merchant requests. */
define('JPWC_TESTING',true);
define('ABSPATH','/unmounted-wp-root/');
require dirname(__DIR__).'/includes/class-jpwc-money.php';
require dirname(__DIR__).'/includes/class-jpwc-api.php';
require dirname(__DIR__).'/includes/class-jpwc-proof.php';
$passed=0;
function test($name,$cond){global $passed;if(!$cond)throw new RuntimeException('FAIL '.$name);$passed++;echo "PASS $name\n";}
function rejected(callable $fn){try{$fn();return false;}catch(DomainException $e){return true;}}
$prices=['3'=>'300','3.0'=>'300','3.00000000'=>'300','3.01'=>'301','0.01'=>'1','10.230000'=>'1023'];
foreach($prices as $p=>$n)test('usd '.$p,JPWC_Money::usd((string)$p)===(int)$n);
foreach(['3.000000001','3.001','-3','3e0','3.99garbage','0','00.01','0.000001'] as $p)test('reject '.$p,rejected(static fn()=>JPWC_Money::usd($p)));
test('usd subtraction fee',JPWC_Money::subtract('3','0.03','2.97'));
test('ledger maximum exact int',JPWC_Money::usdLedger('92233720368.54775807')===PHP_INT_MAX);
test('ledger overflow rejected',rejected(static fn()=>JPWC_Money::usdLedger('92233720368.54775808')));
test('ledger negative rejected',rejected(static fn()=>JPWC_Money::usdLedger('-0.1')));
test('ledger extra precision rejected',rejected(static fn()=>JPWC_Money::usdLedger('3.000000001')));
test('reject wrong fee',!JPWC_Money::subtract('3','0.03','2.98'));
test('coin precision',JPWC_Money::units('3.000000000000000000',18)===3000000000000000000);
test('asset parse',JPWC_Proof::assets('USDT:tron, usdc:ethereum')===['USDT:tron','USDC:ethereum']);
test('asset reject',rejected(static fn()=>JPWC_Proof::assets('BTC:tron')));
test('disabled merchant asset',JPWC_Proof::remoteAssets([['code'=>'USDT','chain'=>'tron','invoicePaymentEnabled'=>false]],['USDT:tron'])===[]);
test('enabled merchant asset',JPWC_Proof::remoteAssets([['code'=>'USDT','chain'=>'tron','invoicePaymentEnabled'=>true]],['USDT:tron'])===['USDT:tron']);
class FakeOrder {
    public function get_items($type='line_item'){return []; }

    public $status='on-hold';public $total='3.00';public $currency='USD';public $method='jangopay_woocommerce';public $paid=false;
    public function get_payment_method(){return $this->method;}
    public function is_paid(){return $this->paid;}
    public function get_status(){return $this->status;}
    public function get_currency(){return $this->currency;}
    public function get_total(){return $this->total;}
}
$row=(object)['invoice_id'=>'INV-1','merchant_order_id'=>'MER-1','amount_cents'=>300,'allowed_assets'=>json_encode(['USDT:tron','USDC:ethereum']),'asset'=>'USDT','chain'=>'tron','txn_id'=>'TXN-1'];
$i=['invoiceId'=>'INV-1','orderId'=>'MER-1','currencyCode'=>'USD','amount'=>'3.00000000','payedAmount'=>'3.00000000','status'=>'paid'];
$t=['txnId'=>'TXN-1','invoiceId'=>'INV-1','status'=>'settled','payCurrency'=>'USDT','chain'=>'tron'];
$order=new FakeOrder();
test('merchant proof settled exact',JPWC_Proof::check($i,$t,$row,$order)==='verified');
$floating=$i;$floating['amount']=3.0;test('reject floating monetary representation',JPWC_Proof::check($floating,$t,$row,$order)==='invoice_amount_invalid');
$j=$i;$j['payedAmount']='2.97';
$k=$t;$k['currencyCode']='USD';$k['amount']='3.00000000';$k['feeAmount']='0.03000000';$k['netAmount']='2.97000000';$k['feePayByUser']=false;$j['feePayByUser']=false;
test('fee net case',JPWC_Proof::check($j,$k,$row,$order)==='verified');
test('fee contract no fields stops',JPWC_Proof::check($j,$t,$row,$order)==='fee_contract_unverified');
$j['payedAmount']='2.99900000';$k['feeAmount']='0.00100000';$k['netAmount']='2.99900000';
test('eight-place merchant fee accepted',JPWC_Proof::check($j,$k,$row,$order)==='verified');
$k['feePayByUser']=true;test('payer-borne fee cannot explain net shortfall',JPWC_Proof::check($j,$k,$row,$order)==='fee_contract_unverified');$k['feePayByUser']=false;
$k['feeAmount']='0.00200000';test('fractional merchant fee mismatch rejected',JPWC_Proof::check($j,$k,$row,$order)==='fee_math_mismatch');
$k['feeAmount']='0.00100000';$k['currencyCode']='USDT';test('non USD transaction fee record rejected',JPWC_Proof::check($j,$k,$row,$order)==='fee_contract_unverified');
$k['currencyCode']='USD';
$k['netAmount']='2.98';test('fee mismatch stops',JPWC_Proof::check($j,$k,$row,$order)==='fee_math_mismatch');
$j=$i;$j['orderId']='MER-EVIL';test('wrong merchant order',JPWC_Proof::check($j,$t,$row,$order)==='invoice_identity_mismatch');
$j=$i;$j['invoiceId']='INV-EVIL';test('wrong invoice',JPWC_Proof::check($j,$t,$row,$order)==='invoice_identity_mismatch');
$j=$i;$j['amount']='2.99';test('less than order amount',JPWC_Proof::check($j,$t,$row,$order)==='invoice_amount_mismatch');
$j=$i;$j['status']='pending';test('invoice not paid',JPWC_Proof::check($j,$t,$row,$order)==='invoice_not_paid');
$k=$t;$k['status']='pending';test('transaction not settled',JPWC_Proof::check($i,$k,$row,$order)==='transaction_not_settled_or_unbound');
$k=$t;$k['invoiceId']='INV-OTHER';test('transaction wrong invoice',JPWC_Proof::check($i,$k,$row,$order)==='transaction_not_settled_or_unbound');
$k=$t;$k['chain']='ethereum';test('transaction network mismatch',JPWC_Proof::check($i,$k,$row,$order)==='transaction_asset_or_identity_mismatch');
$order->status='cancelled';test('cancelled order refused',JPWC_Proof::check($i,$t,$row,$order)==='order_ineligible');$order->status='on-hold';
$order->paid=true;test('already paid refused',JPWC_Proof::check($i,$t,$row,$order)==='order_ineligible');$order->paid=false;
$order->total='4.00';test('WC order total changed',JPWC_Proof::check($i,$t,$row,$order)==='order_amount_changed');
echo "TOTAL $passed passed; offline pure logic only\n";
