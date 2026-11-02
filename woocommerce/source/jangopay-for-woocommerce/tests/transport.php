<?php
/* Real API class with stubbed WP HTTP transport, no network. */
define('ABSPATH','/test-wp/');define('JANGOPAY_WC_API_KEY','JangoPay_example_not_secret');
$requests=[];$mock=[];
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function is_wp_error($r){return false;}
function wp_remote_retrieve_response_code($r){return $r['status'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function wp_remote_request($url,$args){global $requests,$mock;$requests[]=[$url,$args];
   $path=parse_url($url,PHP_URL_PATH);if(!isset($mock[$path]))throw new RuntimeException('Unexpected '.$path);
   return ['status'=>200,'body'=>json_encode($mock[$path])];
}
function wp_remote_get($url,$args){return wp_remote_request($url,['method'=>'GET','headers'=>[]]+$args);}
require dirname(__DIR__).'/includes/class-jpwc-api.php';
function test($name,$ok){static $n=0;if(!$ok)throw new RuntimeException('FAIL '.$name);$n++;echo "PASS $n $name\n";}
$mock=[
'/api/v1/auth/apikey/token'=>['code'=>'0000','data'=>['accessToken'=>'mock_token']],
'/api/v1/invoice/payment-assets'=>['items'=>[['code'=>'USDT','chain'=>'tron','invoicePaymentEnabled'=>true]]],
'/api/v1/invoice/create'=>['invoice'=>['invoiceId'=>'I-1']],
'/api/v1/invoice/I-1/pay-state'=>['paymentSession'=>'S-1'],
'/api/v1/invoice/I-1/confirm-coin'=>['transaction'=>['txnId'=>'T-1','receiveAddress'=>'address-1']],
'/api/v1/invoice/I-1'=>['invoice'=>['invoiceId'=>'I-1','status'=>'paid'],'transactions'=>[['txnId'=>'T-1','invoiceId'=>'I-1','status'=>'settled']]],
'/api/v1/invoice/transaction/T-1'=>['transaction'=>['txnId'=>'T-1','invoiceId'=>'I-1']],
'/api/v1/invoice/list'=>['invoices'=>[['invoiceId'=>'I-1','orderId'=>'O-1']],'totalPages'=>1],
'/api/v1/invoice/transactions'=>['transactions'=>[['txnId'=>'T-1','invoiceId'=>'I-1']],'totalPages'=>1],
];
$api=new JPWC_API();
test('merchant assets',count($api->assets())===1);
test('auth POST is JSON object {}',($requests[0][1]['body']??null)==='{}');
test('auth uses Basic API key',($requests[0][1]['headers']['Authorization']??null)==='Basic JangoPay_example_not_secret');
test('merchant read uses bearer',($requests[1][1]['headers']['Authorization']??null)==='Bearer mock_token');
$created=$api->create(['orderId'=>'O-1','amount'=>'3.00','currencyCode'=>'USD','allowedCurrency'=>['USDT:tron','USDC:ethereum'],'extraInfo'=>['customer_id'=>'test']]);
test('invoice creation mapped',($created['invoiceId']??'')==='I-1');
$sent=json_decode($requests[2][1]['body'],true);
test('top level snake case',isset($sent['order_id'],$sent['currency_code'],$sent['allowed_currency'],$sent['extra_info']));
test('allowed currencies preserve exact code:chain pairs',($sent['allowed_currency']??null)===['USDT:tron','USDC:ethereum']);
test('allowed currencies do not become unrestricted',!empty($sent['allowed_currency']) && !in_array('USDT',$sent['allowed_currency'],true));
test('nested custom keys retained',isset($sent['extra_info']['customer_id']));
$quote=$api->quote('I-1','USDT','tron');test('confirmed quote mapped',($quote['txnId']??'')==='T-1');
$body=json_decode($requests[4][1]['body'],true);test('confirm coin requires session token',($body['payment_session']??'')==='S-1');
test('authenticated invoice GET',($api->invoice('I-1')['status']??'')==='paid');
test('invoice detail envelope preserves associated transactions',($api->invoiceDetails('I-1')['transactions'][0]['txnId']??null)==='T-1');
test('merchant txn lookup',($api->transaction('T-1')['invoiceId']??'')==='I-1');
test('read-only find invoice',($api->findInvoice('O-1')['invoiceId']??'')==='I-1');
test('read-only find transaction',($api->findTransaction('I-1')['txnId']??'')==='T-1');
foreach($requests as $r){test('HTTPS, no certificate bypass',str_starts_with($r[0],'https://api.jangopay.org/')&&($r[1]['sslverify']??false)===true);}
echo 'TOTAL '.count($requests).' HTTP interactions inspected\n';
