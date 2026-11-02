<?php
// WordPress object-cache deletion stub used by the isolated PHP fixtures.
function wp_cache_delete($id,$group='') {
    if($group==='posts')unset($GLOBALS['posts_cache'][(int)$id]);
    if($group==='post_meta')unset($GLOBALS['meta_cache'][(int)$id]);
    $GLOBALS['wp_cache_invalidations'][$group]=($GLOBALS['wp_cache_invalidations'][$group]??0)+1;
    return true;
}

/* Isolated access-control branch tests: NOT a real WP cookie or HTTP session test. */
define('ABSPATH','/fake-wp/');
function absint($a){return abs((int)$a);}
function sanitize_text_field($s){return trim((string)$s);}
function wp_unslash($s){return stripslashes($s);}
function is_user_logged_in(){return !empty($GLOBALS['logged']);}
function get_current_user_id(){return (int)($GLOBALS['viewer']??0);}
function current_user_can($cap,$id=null){return !empty($GLOBALS['admin']);}
function wc_get_order($id){return $id===42?$GLOBALS['order']:null;}
class GuestOrder {
 public $customer=0;
 public function get_order_key(){return 'wc_secret_high_entropy_fake_key';}
 public function get_payment_method(){return 'jangopay_woocommerce';}
 public function get_customer_id(){return $this->customer;}
}
$GLOBALS['order']=new GuestOrder();
require dirname(__DIR__).'/includes/class-jpwc-controller.php';
$method=new ReflectionMethod(JPWC_Controller::class,'access');
function check($msg,$val){static $n=0;if(!$val)throw new RuntimeException('FAIL '.$msg);$n++;echo "PASS $n $msg\n";}
$_REQUEST=['order'=>42,'key'=>'wc_secret_high_entropy_fake_key'];
check('guest correct order key',$method->invoke(null)!==null);
$_REQUEST['key']='wrong';check('guest wrong key rejected',$method->invoke(null)===null);
$_REQUEST['key']='wc_secret_high_entropy_fake_key';$_REQUEST['order']=43;
check('numeric order enumeration rejected',$method->invoke(null)===null);
$_REQUEST['order']=42;$GLOBALS['order']->customer=7;$GLOBALS['logged']=true;$GLOBALS['viewer']=8;
check('logged user cannot read another customer order',$method->invoke(null)===null);
$GLOBALS['viewer']=7;check('correct logged customer allowed',$method->invoke(null)!==null);
$GLOBALS['viewer']=8;$GLOBALS['admin']=true;check('authorized order editor allowed',$method->invoke(null)!==null);
echo "NOTE: No real WordPress nonce/cookie/checkout Block HTTP tests performed.\n";
