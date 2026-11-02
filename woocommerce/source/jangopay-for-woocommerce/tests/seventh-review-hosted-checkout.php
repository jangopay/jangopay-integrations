<?php
/** Deterministic fake transport / fake WC regression for the hosted checkout migration. */
require __DIR__.'/fixtures/review-bootstrap.php';
function wp_parse_url($value) { return parse_url($value); }
function get_woocommerce_currency() { return 'USD'; }
class HostedOrder extends TestOrder {
    public function get_checkout_order_received_url() { return 'https://test.local/checkout/order-received/7/?key=wc_order_key_secret'; }
}
$order=new HostedOrder();$GLOBALS['orders'][7]=$order;
$GLOBALS['reviewTransport']=static function($path,$args) {
    if($path==='/api/v1/invoice/create')$GLOBALS['captured_create']=json_decode((string)($args['body']??''),true);
    return null;
};
$gateway=new JPWC_Gateway();
$first=$gateway->process_payment(7);
verify('first payment redirects directly to official checkout',($first['result']??'')==='success'&&($first['redirect']??'')==='https://app.jangopay.org/pay/I1');
verify('single invoice create POST',($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===1);
verify('return URL is bound to exact Woo order key',($GLOBALS['captured_create']['return_url']??'')===JPWC_Controller::checkoutUrl($order));
verify('allowed asset pair survives creation',($GLOBALS['captured_create']['allowed_currency']??null)===['USDT:tron']);
verify('no local coin quote was initiated',($GLOBALS['httpCalls']['/api/v1/invoice/I1/confirm-coin']??0)===0);
$second=$gateway->process_payment(7);
verify('second checkout returns same hosted invoice',($second['redirect']??'')===$first['redirect']);
verify('second checkout never creates another invoice',($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===1);
verify('valid invoice id yields pinned hosted URL',JPWC_Controller::hostedUrl('INV-0RZ3ENFMPXZX8392')==='https://app.jangopay.org/pay/INV-0RZ3ENFMPXZX8392');
foreach(['','x/y','../foo','%2f','x?redirect=https://evil.com','foo#fragment',str_repeat('a',111)] as $bad) {
    verify('invalid invoice id refused '.substr($bad,0,14),JPWC_Controller::hostedUrl($bad)===null);
}
foreach(['https://evil.example/pay/I1','http://app.jangopay.org/pay/I1','https://app.jangopay.org.evil.com/pay/I1',
         'https://app.jangopay.org/pay/../I1','https://app.jangopay.org/pay/I1?next=evil',
         'https://app.jangopay.org:443/pay/I1'] as $bad) {
    verify('untrusted checkout destination rejected',!JPWC_Controller::isHostedUrl($bad));
}
verify('trusted checkout destination allowed',JPWC_Controller::isHostedUrl($first['redirect']));
$row=JPWC_Ledger::get(7);
verify('awaiting invoice and payable order may reopen official checkout',JPWC_Controller::hostedForOrder($order,$row)===$first['redirect']);
$order->status='cancelled';
verify('cancelled order cannot relaunch checkout',JPWC_Controller::hostedForOrder($order,$row)===null);
$order->status='on-hold';
JPWC_Ledger::cas($row,['awaiting_payment'],['state'=>'quote_unknown']);
verify('uncertain legacy quote never launches checkout',JPWC_Controller::hostedForOrder($order,JPWC_Ledger::get(7))===null);
JPWC_Ledger::cas(JPWC_Ledger::get(7),['quote_unknown'],['state'=>'quoted']);
verify('legacy quoted state never restarts quote',JPWC_Controller::hostedForOrder($order,JPWC_Ledger::get(7))===null);
echo "NOTE: Hosted checkout URL pattern has not been tested with a real provider browser session.\n";
