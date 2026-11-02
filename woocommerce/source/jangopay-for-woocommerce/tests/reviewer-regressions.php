<?php
/* Execute the actual V1.0.2 gateway/controller against isolated WP/SQL doubles.
 * These are controlled race simulations, NOT real MariaDB or WP HTTP tests.
 */
require __DIR__.'/fixtures/review-bootstrap.php';
$GLOBALS['interleave']=true;
$GLOBALS['reviewTransport']=static function($path,$args) {
    if($path==='/api/v1/invoice/payment-assets' && !empty($GLOBALS['interleave'])) {
        $GLOBALS['interleave']=false;
        $GLOBALS['simulateTimeout']=true;
        $GLOBALS['requestA']=(new JPWC_Gateway())->process_payment(7);
        $GLOBALS['simulateTimeout']=false;
    }
    return null;
};
$requestB=(new JPWC_Gateway())->process_payment(7);
verify('simulated overlapping checkouts each return usable pending URL',($GLOBALS['requestA']['result']??'')==='success'&&($requestB['result']??'')==='success');
verify('create timeout race sends exactly one remote POST',($httpCalls['/api/v1/invoice/create']??0)===1);
$row=JPWC_Ledger::get(7);
verify('remote timeout never returns to fresh reservation', $row->state==='creating_unknown');
$before=$httpCalls['/api/v1/invoice/create'];
(new JPWC_Gateway())->process_payment(7);
verify('later retry cannot POST unknown invoice',$httpCalls['/api/v1/invoice/create']===$before);
// Crash between local INSERT and creation claim leaves 'reserved', where POST is safe once.
$o8=new TestOrder();$o8->id=8;$GLOBALS['orders'][8]=$o8;
$reserved=JPWC_Ledger::reserve($o8,300,['USDT:tron']);
verify('unclaimed reservation is visibly fresh', $reserved->state==='reserved');
$pre=$httpCalls['/api/v1/invoice/create'];
$g=new JPWC_Gateway();$g->process_payment(8);
verify('later checkout can consume abandoned fresh reservation exactly once',($httpCalls['/api/v1/invoice/create']??0)===$pre+1);
$g->process_payment(8);
verify('consumed reservation never sends second POST',($httpCalls['/api/v1/invoice/create']??0)===$pre+1);
// Keep status eligible; test platform-verified merchant fee schema and 8-decimal ledger.
$row->invoice_id='I1';$row->txn_id='T1';$row->asset='USDT';$row->chain='tron';
$i=['invoiceId'=>'I1','orderId'=>$row->merchant_order_id,'amount'=>'3.00000000','currencyCode'=>'USD','status'=>'paid','payedAmount'=>'2.97000000','feePayByUser'=>false];
$t=['txnId'=>'T1','invoiceId'=>'I1','status'=>'settled','payCurrency'=>'USDT','chain'=>'tron','currencyCode'=>'USD','amount'=>'3.00000000','feeAmount'=>'0.03000000','netAmount'=>'2.97000000','feePayByUser'=>false];
verify('verified merchant fee fields accepted',JPWC_Proof::check($i,$t,$row,$GLOBALS['orders'][7])==='verified');
$i['payedAmount']='2.99900000';$t['feeAmount']='0.00100000';$t['netAmount']='2.99900000';
verify('8-decimal merchant fee arithmetic accepted',JPWC_Proof::check($i,$t,$row,$GLOBALS['orders'][7])==='verified');
$t['feePayByUser']=true;
verify('merchant fee requires explicit payer flag',JPWC_Proof::check($i,$t,$row,$GLOBALS['orders'][7])==='fee_contract_unverified');
$t['feePayByUser']=false;$t['netAmount']='2.99900001';
verify('net mismatch refused',JPWC_Proof::check($i,$t,$row,$GLOBALS['orders'][7])==='fee_math_mismatch');
verify('expired quote is not payable',!JPWC_Controller::quoteActive((object)['state'=>'quoted','expires_at'=>'2000-01-01T00:00:00Z']));
verify('invalid expiration refused',!JPWC_Controller::quoteActive((object)['state'=>'quoted','expires_at'=>'tomorrow']));
verify('future absolute expiration accepted',JPWC_Controller::quoteActive((object)['state'=>'quoted','expires_at'=>'2099-01-01T00:00:00Z']));
verify('missing expiry refused',!JPWC_Controller::quoteActive((object)['state'=>'quoted','expires_at'=>'']));
// Explicitly avoid nested <form> in the Woo order editor.
function current_user_can($cap){return true;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function admin_url($v){return 'https://test.local/wp-admin/'.$v;}
function wp_create_nonce($action){return 'review_nonce';}
ob_start();JPWC_Controller::adminOrder($GLOBALS['orders'][7]);$admin=ob_get_clean();
verify('admin reverify does not nest HTML forms',!str_contains($admin,'<form'));
verify('admin reverify uses AJAX nonce and explicit type button',str_contains($admin,'type="button"')&&str_contains($admin,'class="button jpwc-reverify"')&&str_contains($admin,'data-nonce=')&&isset($GLOBALS['jpwc_test_scripts']['jpwc-admin'])&&str_contains(file_get_contents(dirname(__DIR__).'/assets/js/admin.js'),'jpwc_reverify'));
echo 'NOTE: Simulated server and payment instructions, no WooCommerce site or browser.\n';
