<?php
/* CLI simulation: only live JPWC controller/gateway logic, fake WP/WC/SQL + merchant HTTP. */
$scenario=$argv[1]??'';
if(!in_array($scenario,['get_reserved','retry_reserved','reject_unknown','reject_nonce'],true))exit(9);
require __DIR__.'/review-bootstrap.php';
function absint($a){return abs((int)$a);}
function sanitize_text_field($v){return trim((string)$v);}
function wp_unslash($v){return stripslashes((string)$v);}
function is_user_logged_in(){return false;}
function get_current_user_id(){return 0;}
function current_user_can($a,$b=null){return false;}
function nocache_headers(){}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function admin_url($s){return 'https://test.local/wp-admin/'.$s;}
function wp_nonce_field($action,$name='_wpnonce'){echo '<input type="hidden" name="'.esc_attr($name).'" value="test-valid-nonce">';}
function wp_verify_nonce($nonce,$action){$GLOBALS['checked_nonce_action']=$action;return $nonce==='test-valid-nonce' && $action==='jpwc_resume_7';}
function get_woocommerce_currency(){return 'USD';}
// Mirror the real WordPress redirect hooks and return contract, rather than
// treating a successful hosted redirect as a failed call to wp_safe_redirect().
function add_filter($tag,$callback,$priority=10,$argc=1){$GLOBALS['redirect_filters'][$tag][]=$callback;}
function remove_filter($tag,$callback,$priority=10){
    $GLOBALS['redirect_filters'][$tag]=array_values(array_filter(
        $GLOBALS['redirect_filters'][$tag]??[],static fn($x)=>$x!==$callback
    ));
}
function wp_safe_redirect($url,$code=302,$source=''){
    $host=parse_url($url,PHP_URL_HOST);
    $allowed=['test.local'];
    foreach($GLOBALS['redirect_filters']['allowed_redirect_hosts']??[] as $callback) $allowed=$callback($allowed);
    $GLOBALS['redirect_to']=in_array($host,$allowed,true)?$url:'https://test.local/';
    $GLOBALS['redirect_code']=$code;
    $GLOBALS['redirect_type']='safe';
    $GLOBALS['hosted_allowed_during_redirect']=in_array('app.jangopay.org',$allowed,true);
    return true;
}
// A pre-existing site's redirect host filter must survive JangoPay's cleanup.
$siteRedirectFilter=static function($hosts){$hosts[]='existing-site.example';return $hosts;};
add_filter('allowed_redirect_hosts',$siteRedirectFilter);
$GLOBALS['site_redirect_filter']=$siteRedirectFilter;

function wp_redirect($url,$code=302,$by=''){$GLOBALS['redirect_to']=$url;$GLOBALS['redirect_code']=$code;}
function wp_parse_url($url){return parse_url($url);}
function status_header($code){$GLOBALS['status_code']=$code;}
class BuyerOrder extends TestOrder {
 public function get_customer_id(){return 0;}
 public function get_order_number(){return $this->id;}
 public function get_checkout_order_received_url(){return 'https://test.local/order-received/7/';}
}
$order=new BuyerOrder();$GLOBALS['orders'][7]=$order;
$GLOBALS['reviewTransport']=static function($path,$args){
 if($path==='/api/v1/auth/apikey/token'&&($GLOBALS['httpCalls'][$path]??0)===2)
    throw new RuntimeException('fail authentication on second API object');
 return null;
};
$first=(new JPWC_Gateway())->process_payment(7);
$GLOBALS['reviewTransport']=null;
$row=JPWC_Ledger::get(7);
if($row->state!=='reserved'||($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)!==0||$first['result']!=='success')
 throw new RuntimeException('unable to reproduce prepare failure');
if($scenario==='reject_unknown')JPWC_Ledger::cas($row,['reserved'],['state'=>'creating_unknown']);
$_REQUEST=['order'=>7,'key'=>$order->get_order_key()];
$_SERVER['REQUEST_METHOD']=$scenario==='get_reserved'?'GET':'POST';
$_POST=['jpwc_action'=>'resume_reserved','jpwc_nonce'=>$scenario==='reject_nonce'?'invalid-nonce':'test-valid-nonce'];
ob_start();
register_shutdown_function(static function()use($scenario){
 $html=ob_get_clean();$row=JPWC_Ledger::get(7);$posts=$GLOBALS['httpCalls']['/api/v1/invoice/create']??0;
 $out=['scenario'=>$scenario,'state'=>$row->state,'invoice_id'=>$row->invoice_id,'posts'=>$posts,
    'http_status'=>$GLOBALS['status_code']??200,'redirect_code'=>$GLOBALS['redirect_code']??0,
    'form_count'=>substr_count($html,'<form'),'contains_resume'=>str_contains($html,'name="jpwc_action" value="resume_reserved"'),
    'redirect_filter_count'=>count($GLOBALS['redirect_filters']['allowed_redirect_hosts']??[]),
    'site_filter_preserved'=>in_array($GLOBALS['site_redirect_filter']??null,$GLOBALS['redirect_filters']['allowed_redirect_hosts']??[],true),
    'hosted_allowed_during_redirect'=>$GLOBALS['hosted_allowed_during_redirect']??false];
 $ok=match($scenario){
  'get_reserved'=>$row->state==='reserved'&&$posts===0&&$out['form_count']===1&&$out['contains_resume'],
  'retry_reserved'=>$row->state==='awaiting_payment'&&$posts===1&&$out['redirect_code']===303&&
     ($GLOBALS['redirect_to']??'')==='https://app.jangopay.org/pay/I1'&&($GLOBALS['redirect_type']??'')==='safe'&&
     ($GLOBALS['checked_nonce_action']??'')==='jpwc_resume_7'&&$out['redirect_filter_count']===1&&
     $out['site_filter_preserved']&&$out['hosted_allowed_during_redirect'],
  'reject_unknown'=>$row->state==='creating_unknown'&&$posts===0&&$out['http_status']===409,
  'reject_nonce'=>$row->state==='reserved'&&$posts===0&&$out['http_status']===403
 };
 echo ($ok?'PASS ':'FAIL ').json_encode($out,JSON_UNESCAPED_SLASHES)."\n";
 if(!$ok)exit(2);
});
JPWC_Controller::checkout();
