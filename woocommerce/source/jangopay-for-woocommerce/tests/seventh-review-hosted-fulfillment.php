<?php
/** Fake merchant GETs + real plugin verification: hosted checkout issues NO local coin quote. */
require __DIR__.'/fixtures/review-bootstrap.php';
function wp_parse_url($value){return parse_url($value);}
$order=$GLOBALS['orders'][7];
$first=(new JPWC_Gateway())->process_payment(7);
verify('hosted invoice successfully reserved',($first['redirect']??'')==='https://app.jangopay.org/pay/I1');
$row=JPWC_Ledger::get(7);
verify('no local quote stored in hosted flow',$row->state==='awaiting_payment'&&$row->txn_id===null&&$row->asset===null&&$row->chain===null&&$row->expires_at===null);
$GLOBALS['reviewTransport']=static function($path,$args)use($row){
 if($path==='/api/v1/invoice/I1')return ['status'=>200,'body'=>json_encode(['invoice'=>[
  'invoiceId'=>'I1','orderId'=>$row->merchant_order_id,'amount'=>'3.00','currencyCode'=>'USD','status'=>'paid','payedAmount'=>'2.97000000','feePayByUser'=>false]])];
 if($path==='/api/v1/invoice/transaction/T1')return ['status'=>200,'body'=>json_encode(['transaction'=>[
  'txnId'=>'T1','invoiceId'=>'I1','status'=>'settled','payCurrency'=>'USDT','chain'=>'tron',
  'currencyCode'=>'USD','feePayByUser'=>false,'amount'=>'3.00000000','feeAmount'=>'0.03000000','netAmount'=>'2.97000000']])];
 return null;
};
verify('merchant verified hosted payment completes WC order',JPWC_Controller::verifyRow($row,'T1')==='completed');
verify('Woo order paid once',$order->is_paid()&&$order->delivery===1);
verify('ledger completed after validated merchant transaction',JPWC_Ledger::get(7)->state==='completed'&&JPWC_Ledger::get(7)->txn_id==='T1');
verify('replayed merchant verification has no second delivery',JPWC_Controller::verifyRow(JPWC_Ledger::get(7),'T1')==='completed'&&$order->delivery===1);
verify('one invoice, zero merchant-side confirm-coin',($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===1&&($GLOBALS['httpCalls']['/api/v1/invoice/I1/confirm-coin']??0)===0);
// The hosted checkout does not persist a local quote txnId. If the webhook is
// delayed/missing, a read-only merchant reconciliation must find the transaction.
$other=new TestOrder();$other->id=8;$GLOBALS['orders'][8]=$other;
$GLOBALS['reviewTransport']=static function($path,$args) {
    if($path==='/api/v1/invoice/create')return ['status'=>200,'body'=>json_encode(['invoice'=>['invoiceId'=>'I8']])];
    if($path==='/api/v1/invoice/I8')return ['status'=>200,'body'=>json_encode(['invoice'=>[
      'invoiceId'=>'I8','orderId'=>JPWC_Ledger::get(8)->merchant_order_id,'amount'=>'3.00',
      'currencyCode'=>'USD','status'=>'paid','payedAmount'=>'3.00000000']])];
    if($path==='/api/v1/invoice/transactions')return ['status'=>200,'body'=>json_encode([
      'transactions'=>[['txnId'=>'T8','invoiceId'=>'I8']],'totalPages'=>1])];
    if($path==='/api/v1/invoice/transaction/T8')return ['status'=>200,'body'=>json_encode(['transaction'=>[
      'txnId'=>'T8','invoiceId'=>'I8','status'=>'settled','payCurrency'=>'USDT','chain'=>'tron']])];
    return null;
};
$next=(new JPWC_Gateway())->process_payment(8);
verify('second hosted invoice is reusable',($next['redirect']??'')==='https://app.jangopay.org/pay/I8');
verify('no local coin quote on second order',JPWC_Ledger::get(8)->txn_id===null);
verify('read-only txn discovery works without webhook txn hint',JPWC_Controller::verifyRow(JPWC_Ledger::get(8))==='completed'&&$other->delivery===1);
verify('read-only discovery is repeat-delivery safe',JPWC_Controller::verifyRow(JPWC_Ledger::get(8))==='completed'&&$other->delivery===1);
echo "NOTE: Fully simulated provider verification and WC order; not live JangoPay or MariaDB.\n";
