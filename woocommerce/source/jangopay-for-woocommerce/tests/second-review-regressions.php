<?php
/* New V1.0.3 second-pass error-path tests: real API and controller helpers, fake WP/Woo/SQL. */
require __DIR__.'/fixtures/review-bootstrap.php';
function wp_remote_get($url,$args){return wp_remote_request($url,['method'=>'GET']+$args);}
$g=new JPWC_Gateway();
verify('normal gateway checkout reserves invoice',($g->process_payment(7)['result']??'')==='success');
$o=$GLOBALS['orders'][7];$r=JPWC_Ledger::get(7);
verify('invoice has recoverable payable state',$r->state==='awaiting_payment');
verify('selection form is allowed before quote',JPWC_Controller::canPay($o,$r));
JPWC_Ledger::cas($r,['awaiting_payment'],['state'=>'quoted','expires_at'=>'2099-01-01T00:00:00Z']);
$r=JPWC_Ledger::get(7);
verify('current quote can pay',JPWC_Controller::canPay($o,$r));
JPWC_Ledger::cas($r,['quoted'],['state'=>'manual_review']);$r=JPWC_Ledger::get(7);
verify('manual review can no longer pay',!JPWC_Controller::canPay($o,$r));
verify('old V1.0.2 status logic was vulnerable',(JPWC_Controller::orderPayable($o,$r)&&($r->state!=='quoted'||JPWC_Controller::quoteActive($r)))===true);
JPWC_Ledger::cas($r,['manual_review'],['state'=>'manual_review']);
verify('missing row cannot pay',!JPWC_Controller::canPay($o,null));
$o->status='cancelled';verify('cancelled order cannot pay',!JPWC_Controller::canPay($o,$r));
$o->status='on-hold';
$GLOBALS['reviewTransport']=static function($path,$args){
    if($path==='/api/v1/invoice/I1/pay-state')throw new RuntimeException('preparing GET timed out');
    return null;
};
$before=$httpCalls['/api/v1/invoice/I1/confirm-coin']??0;
try{(new JPWC_API())->quote('I1','USDT','tron');throw new RuntimeException('unexpected_success');}
catch(JPWC_QuotePreparationException $e){verify('GET timeout correctly distinguished as pre-POST failure',true);}
verify('failed preflight did not send confirm coin POST',($httpCalls['/api/v1/invoice/I1/confirm-coin']??0)===$before);
$GLOBALS['reviewTransport']=static function($path,$args){
    if($path==='/api/v1/invoice/I1/pay-state')return ['status'=>200,'body'=>json_encode(['paymentSession'=>'session-1'])];
    return null;
};
try{(new JPWC_API())->quote('I1','USDT','tron',static function(){throw new RuntimeException('order cancelled during session GET');});throw new RuntimeException('unexpected_success');}
catch(JPWC_QuotePreparationException $e){verify('pre-POST order change blocks quote safely',true);}
verify('order-change preflight sent no POST',($httpCalls['/api/v1/invoice/I1/confirm-coin']??0)===$before);
$GLOBALS['reviewTransport']=static function($path,$args){
    if($path==='/api/v1/invoice/I1/pay-state')return ['status'=>200,'body'=>json_encode(['paymentSession'=>'session-1'])];
    if($path==='/api/v1/invoice/I1/confirm-coin')throw new RuntimeException('remote POST timed out after submission');
    return null;
};
try{(new JPWC_API())->quote('I1','USDT','tron',static function(){});throw new RuntimeException('unexpected_success');}
catch(JPWC_QuotePreparationException $e){throw new RuntimeException('POST timeout misclassified as safely retryable');}
catch(RuntimeException $e){verify('POST timeout is not labelled pre-POST failure',$e->getMessage()==='remote POST timed out after submission');}
verify('unsafe confirm coin was sent exactly once',($httpCalls['/api/v1/invoice/I1/confirm-coin']??0)===$before+1);
// The HTTP controller catches JPWC_QuotePreparationException and performs a CAS
// back to awaiting_payment only when the Woo order is still payable; independent
// audit here verifies the exact state guards rather than faking PHP exit/redirect.
JPWC_Ledger::cas($r,['manual_review'],['state'=>'quote_unknown']);$r=JPWC_Ledger::get(7);
verify('preparation outcome may safely return to selection',JPWC_Ledger::cas($r,['quote_unknown'],['state'=>'awaiting_payment','failure_code'=>'quote_preparation_failed']));
$r=JPWC_Ledger::get(7);verify('safe retry returns to pending selection',$r->state==='awaiting_payment'&&JPWC_Controller::canPay($o,$r));
echo "NOTE: synthetic transport / in-memory CAS; no real WordPress, WooCommerce or JangoPay request.\n";
