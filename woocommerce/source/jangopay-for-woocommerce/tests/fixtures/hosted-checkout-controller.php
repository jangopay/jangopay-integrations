<?php
/* CLI simulates controller exits/redirects with fake WP/WC, never remote provider. */
$scenario=$argv[1]??'';
if(!in_array($scenario,['hosted_get','legacy_post','legacy_quoted','resume','return_paid','quote_unknown','wrong_key'],true))exit(9);
require __DIR__.'/review-bootstrap.php';
function absint($v){return abs((int)$v);}
function sanitize_text_field($v){return trim((string)$v);}
function wp_unslash($v){return stripslashes((string)$v);}
function is_user_logged_in(){return false;}
function get_current_user_id(){return 0;}
function current_user_can($a,$b=null){return false;}
function nocache_headers(){}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function admin_url($s){return 'https://test.local/wp-admin/'.$s;}
function get_woocommerce_currency(){return 'USD';}
function wp_parse_url($s){return parse_url($s);}
function wp_nonce_field($a,$name='_wpnonce'){echo '<input type="hidden" name="'.esc_attr($name).'" value="nonce-ok">';}
function wp_verify_nonce($nonce,$action){return $nonce==='nonce-ok'&&$action==='jpwc_resume_7';}
function add_filter($tag,$callback,$priority=10,$argc=1){$GLOBALS['redirect_filters'][$tag][]=$callback;}
function remove_filter($tag,$callback,$priority=10){$GLOBALS['redirect_filters'][$tag]=array_values(array_filter($GLOBALS['redirect_filters'][$tag]??[],static fn($x)=>$x!==$callback));}
function wp_safe_redirect($url,$code=302,$source=''){
    $host=parse_url($url,PHP_URL_HOST);
    $allowed=['test.local'];
    foreach($GLOBALS['redirect_filters']['allowed_redirect_hosts']??[] as $callback) $allowed=$callback($allowed);
    $GLOBALS['redirect_to']=in_array($host,$allowed,true)?$url:'https://test.local/';
    $GLOBALS['redirect_code']=$code;
    $GLOBALS['redirect_type']='safe';
    $GLOBALS['redirect_filter_leaked']=count($GLOBALS['redirect_filters']['allowed_redirect_hosts']??[])>0;
    return true;
}
function wp_redirect($url,$code=302,$x=''){ $GLOBALS['redirect_to']=$url;$GLOBALS['redirect_code']=$code;$GLOBALS['redirect_type']='external'; }
function status_header($code){$GLOBALS['status_code']=$code;}
class HostedBuyerOrder extends TestOrder {
 public function get_customer_id(){return 0;}
 public function get_order_number(){return $this->id;}
 public function get_checkout_order_received_url(){return 'https://test.local/order-received/7/';}
}
$o=new HostedBuyerOrder();$GLOBALS['orders'][7]=$o;
if($scenario==='resume') {
    $GLOBALS['reviewTransport']=static function($path,$args){
        if($path==='/api/v1/auth/apikey/token'&&($GLOBALS['httpCalls'][$path]??0)===2)
            throw new RuntimeException('second authentication fails before create');
        return null;
    };
}
$first=(new JPWC_Gateway())->process_payment(7);
if($first['result']!=='success')throw new RuntimeException('initial create did not succeed');
$GLOBALS['reviewTransport']=null;
$row=JPWC_Ledger::get(7);
if($scenario==='resume'&&$row->state!=='reserved')throw new RuntimeException('expected reserved pre-create outcome');
if($scenario==='legacy_quoted')JPWC_Ledger::cas($row,['awaiting_payment'],['state'=>'quoted','quote_address'=>'SHOULD_NEVER_RENDER_ADDRESS','quote_amount'=>'3.00']);
if($scenario==='quote_unknown')JPWC_Ledger::cas($row,['awaiting_payment'],['state'=>'quote_unknown']);
if($scenario==='return_paid'){
 $o->paid=true;$o->status='completed';
 JPWC_Ledger::cas($row,['awaiting_payment'],['state'=>'completed']);
}
$_REQUEST=['order'=>7,'key'=>$scenario==='wrong_key'?'guess':$o->get_order_key()];
$_SERVER['REQUEST_METHOD']=in_array($scenario,['legacy_post','resume'],true)?'POST':'GET';
$_POST=$scenario==='legacy_post'?['jpwc_nonce'=>'nonce-ok','asset_chain'=>'USDT:tron']:['jpwc_action'=>'resume_reserved','jpwc_nonce'=>'nonce-ok'];
ob_start();
register_shutdown_function(static function()use($scenario){
    $html=ob_get_clean();$row=JPWC_Ledger::get(7);$post=$GLOBALS['httpCalls']['/api/v1/invoice/create']??0;
    $quote=$GLOBALS['httpCalls']['/api/v1/invoice/I1/confirm-coin']??0;
    $data=['scenario'=>$scenario,'state'=>$row->state,'create_posts'=>$post,'quote_posts'=>$quote,
           'status'=>$GLOBALS['status_code']??200,'redirect'=>$GLOBALS['redirect_to']??null,
           'redirect_code'=>$GLOBALS['redirect_code']??0,
           'redirect_filter_leaked'=>count($GLOBALS['redirect_filters']['allowed_redirect_hosts']??[]),
           'hosted_link'=>str_contains($html,'id="jpwc-hosted-link"'),
           'selection'=>str_contains($html,'name="asset_chain"'),
           'legacy_address_leaked'=>str_contains($html,'SHOULD_NEVER_RENDER_ADDRESS')];
    $ok=match($scenario){
     'hosted_get'=>$data['hosted_link']&&!$data['selection']&&$post===1&&$quote===0,
     'legacy_post'=>$data['status']===405&&$post===1&&$quote===0,
     'legacy_quoted'=>!$data['hosted_link']&&!$data['legacy_address_leaked']&&$quote===0,
     'resume'=>$row->state==='awaiting_payment'&&$post===1&&$data['redirect']==='https://app.jangopay.org/pay/I1'&&$data['redirect_code']===303&&$data['redirect_filter_leaked']===0&&$quote===0,
     'return_paid'=>$data['redirect']==='https://test.local/order-received/7/'&&$data['redirect_code']===303&&$quote===0,
     'quote_unknown'=>!$data['hosted_link']&&$quote===0,
     'wrong_key'=>$data['status']===403&&!$data['hosted_link']&&$quote===0,
    };
    echo ($ok?'PASS ':'FAIL ').json_encode($data,JSON_UNESCAPED_SLASHES)."\n";
    if(!$ok)exit(2);
});
JPWC_Controller::checkout();
