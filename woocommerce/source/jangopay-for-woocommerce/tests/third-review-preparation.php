<?php
/* 1.0.4: an auth error before invoice/create POST must be safely retriable. */
require __DIR__.'/fixtures/review-bootstrap.php';
$gw=new JPWC_Gateway();
$GLOBALS['reviewTransport']=static function($path,$args) {
    if($path==='/api/v1/auth/apikey/token' && ($GLOBALS['httpCalls'][$path]??0)===2)
        throw new RuntimeException('auth transport failed before create POST');
    return null;
};
$r=$gw->process_payment(7);
verify('auth failure remains recoverable',($r['result']??'')==='success' && JPWC_Ledger::get(7)->state==='reserved');
verify('no invoice POST after auth failure',($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===0);
verify('first attempt failure is pre-create, not unknown',JPWC_Ledger::get(7)->failure_code==='invoice_preparation_failed');
$GLOBALS['reviewTransport']=null;
$r=$gw->process_payment(7);
verify('same order safely resumes reserved creation',($r['result']??'')==='success' && JPWC_Ledger::get(7)->state==='awaiting_payment');
verify('only one remote invoice create POST on retry',($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===1);
$gw->process_payment(7);
verify('successively repeated checkout does not create another invoice',($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===1);

$o=new TestOrder();$o->id=8;$GLOBALS['orders'][8]=$o;
$GLOBALS['reviewTransport']=static function($path,$args)use($o) {
    if($path==='/api/v1/auth/apikey/token' && ($GLOBALS['httpCalls'][$path]??0)>=5) $o->status='cancelled';
    return null;
};
$r=$gw->process_payment(8);
verify('order cancelled during pre-create auth cannot create invoice',($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===1);
verify('cancelled order routed to manual review before POST',JPWC_Ledger::get(8)->state==='manual_review');

$o9=new TestOrder();$o9->id=9;$GLOBALS['orders'][9]=$o9;
$GLOBALS['reviewTransport']=null;
$GLOBALS['simulateTimeout']=true;
$gw->process_payment(9);
verify('POST timeout is unknown, never safely retryable',JPWC_Ledger::get(9)->state==='creating_unknown');
$before=$GLOBALS['httpCalls']['/api/v1/invoice/create'];
$GLOBALS['simulateTimeout']=false;
$gw->process_payment(9);
verify('unknown create result never re-POSTs',($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===$before);
echo "NOTE: fake WC and SQL; no live network.\n";
