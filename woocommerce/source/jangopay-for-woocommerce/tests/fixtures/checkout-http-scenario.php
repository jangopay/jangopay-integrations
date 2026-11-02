<?php
/* CLI HTTP checkout-controller scenario; exits in the real controller redirect code. */
$scenario=$argv[1]??'';
if(!in_array($scenario,['get_failure','cancel_during_get','post_timeout','quote_success'],true))exit(9);
require __DIR__.'/review-bootstrap.php';
function wp_remote_get($url,$args){return wp_remote_request($url,['method'=>'GET']+$args);}
function absint($x){return abs((int)$x);}
function sanitize_text_field($x){return trim((string)$x);}
function wp_unslash($x){return stripslashes($x);}
function is_user_logged_in(){return false;}
function get_current_user_id(){return 0;}
function wp_verify_nonce($nonce,$action){return $nonce==='test-nonce';}
function nocache_headers(){}
function wp_safe_redirect($url,$status=302){$GLOBALS['redirected_to']=$url;}
function esc_html($x){return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');}
function esc_attr($x){return esc_html($x);}
class HttpCheckoutOrder extends TestOrder {public function get_customer_id(){return 0;}}
$o=new HttpCheckoutOrder();$GLOBALS['orders'][7]=$o;
$g=new JPWC_Gateway();$start=$g->process_payment(7);if(($start['result']??'')!=='success')exit(10);
$_REQUEST=['order'=>7,'key'=>$o->get_order_key()];
$_POST=['jpwc_nonce'=>'test-nonce','asset_chain'=>'USDT:tron'];
$_SERVER['REQUEST_METHOD']='POST';
$GLOBALS['reviewTransport']=static function($path,$args)use($scenario,$o){
    if($path==='/api/v1/invoice/I1/pay-state'){
        if($scenario==='get_failure')throw new RuntimeException('simulated GET timeout');
        if($scenario==='cancel_during_get')$o->status='cancelled';
        return ['status'=>200,'body'=>json_encode(['paymentSession'=>'session-1'])];
    }
    if($path==='/api/v1/invoice/I1/confirm-coin'){
        if($scenario==='post_timeout')throw new RuntimeException('simulated POST timeout');
        return ['status'=>200,'body'=>json_encode(['transaction'=>['txnId'=>'T1','receiveAddress'=>'ExampleAddress','payAmount'=>'3.000000','payCurrency'=>'USDT','chain'=>'tron','expiresAt'=>'2099-01-01T00:00:00Z']])];
    }
    return null;
};
register_shutdown_function(static function()use($scenario){
    $r=JPWC_Ledger::get(7);
    echo json_encode(['scenario'=>$scenario,'state'=>$r->state,'post_count'=>$GLOBALS['httpCalls']['/api/v1/invoice/I1/confirm-coin']??0,'redirect'=>isset($GLOBALS['redirected_to']),'order_status'=>$GLOBALS['orders'][7]->status],JSON_UNESCAPED_SLASHES)."\n";
});
JPWC_Controller::checkout();
