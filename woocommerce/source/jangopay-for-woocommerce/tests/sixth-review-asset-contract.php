<?php
/**
 * Regression for verified WooCommerce 1.0.6 live contract blocker:
 * exact code:chain pairs must survive through the REAL gateway, API serialization
 * and WordPress HTTP transport. Fake order/DB/HTTP: no merchant network call.
 */
require __DIR__.'/fixtures/review-bootstrap.php';

$capture=[];
$GLOBALS['reviewTransport']=static function($path,$args) use (&$capture) {
    if($path==='/api/v1/invoice/create') {
        $capture[]=json_decode((string)($args['body']??''),true);
    }
    return null; // Execute standard fake WP transport response.
};

$order=new TestOrder();$order->id=901;
$GLOBALS['orders'][901]=$order;
$snapshot=['USDT:bsc'];
$row=JPWC_Ledger::reserve($order,300,$snapshot);
verify('BSC snapshot is persisted intact',json_decode($row->allowed_assets,true)===$snapshot);
$result=(new JPWC_Gateway())->process_payment(901);
verify('BSC checkout starts',($result['result']??null)==='success');
verify('BSC invoice request sent exactly once',count($capture)===1);
verify('BSC network retained in serialized HTTP body',($capture[0]['allowed_currency']??null)===['USDT:bsc']);
verify('HTTP body uses expected snake_case key',!array_key_exists('allowedCurrency',$capture[0]));
verify('USD amount preserved',($capture[0]['amount']??null)==='3.00' && ($capture[0]['currency_code']??null)==='USD');
verify('merchant order identity preserved',($capture[0]['order_id']??null)===$row->merchant_order_id);
verify('original immutable snapshot remains unchanged',json_decode(JPWC_Ledger::get(901)->allowed_assets,true)===$snapshot);

$second=new TestOrder();$second->id=902;
$GLOBALS['orders'][902]=$second;
$multi=['USDT:tron','USDT:bsc','USDC:ethereum'];
JPWC_Ledger::reserve($second,300,$multi);
$result=(new JPWC_Gateway())->process_payment(902);
verify('multiple networks checkout starts',($result['result']??null)==='success');
verify('all pairs, including same-currency different chains, retained',($capture[1]['allowed_currency']??null)===$multi);
verify('two orders => two invoice POSTs',count($capture)===2);

// Empty or corrupt snapshots must not become provider's unrestricted [] setting.
$before=count($capture);
$invalid=new TestOrder();$invalid->id=903;
$GLOBALS['orders'][903]=$invalid;
JPWC_Ledger::reserve($invalid,300,[]);
$result=(new JPWC_Gateway())->process_payment(903);
verify('invalid empty asset snapshot never results in invoice POST',count($capture)===$before);
verify('invalid snapshot quarantined for merchant review',JPWC_Ledger::get(903)->state==='manual_review');
verify('invalid snapshot never creates invoice id',JPWC_Ledger::get(903)->invoice_id===null);
verify('invalid snapshot does not change Woo order status or invoke hold hooks',$invalid->status==='pending');

$invalid2=new TestOrder();$invalid2->id=904;
$GLOBALS['orders'][904]=$invalid2;
JPWC_Ledger::reserve($invalid2,300,['USDT']);
(new JPWC_Gateway())->process_payment(904);
verify('token-only snapshot fails closed',count($capture)===$before && JPWC_Ledger::get(904)->state==='manual_review');

$invalid3=new TestOrder();$invalid3->id=905;
$GLOBALS['orders'][905]=$invalid3;
JPWC_Ledger::reserve($invalid3,300,['USDT:bsc','USDT:bsc']);
(new JPWC_Gateway())->process_payment(905);
verify('duplicate non-canonical snapshot fails closed',count($capture)===$before && JPWC_Ledger::get(905)->state==='manual_review');

// A repeated attempt may reuse the existing invoice but must not change the chain.
$result=(new JPWC_Gateway())->process_payment(901);
verify('repeat of successful BSC checkout reuses original attempt',($result['result']??null)==='success');
verify('repeat BSC checkout sends no second invoice POST',count($capture)===$before);
verify('BSC ledger remains bound to same network',json_decode(JPWC_Ledger::get(901)->allowed_assets,true)===['USDT:bsc']);
echo "NOTE: PHP fake transport/ledger, not a live JangoPay invoice or real WordPress database.\n";
