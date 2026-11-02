<?php
// Historical rendering fixture; the shared review-bootstrap supplies WP cache mocks.
require __DIR__.'/review-bootstrap.php';
function absint($v){return abs((int)$v);}
function sanitize_text_field($v){return trim((string)$v);}
function wp_unslash($v){return $v;}
function is_user_logged_in(){return false;}
function get_current_user_id(){return 0;}
function current_user_can($cap,$id=null){return true;}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function nocache_headers(){}
function admin_url($v){return 'https://test.local/wp-admin/'.$v;}
function wp_create_nonce($a){return 'review_nonce';}
function wp_nonce_field($a,$name='_wpnonce'){echo '<input type="hidden" name="'.esc_attr($name).'" value="review_nonce">';}
class RenderOrder extends TestOrder {
    public function get_customer_id(){return 0;}
    public function get_order_number(){return $this->id;}
    public function get_checkout_order_received_url(){return 'https://test.local/order-received/7/';}
}
$order=new RenderOrder();$GLOBALS['orders'][7]=$order;
(new JPWC_Gateway())->process_payment(7);
$row=JPWC_Ledger::get(7);
JPWC_Ledger::cas($row,['awaiting_payment'],['state'=>'quoted','txn_id'=>'T1','asset'=>'USDT','chain'=>'tron','quote_amount'=>'3.00','quote_address'=>'expired_example_address','expires_at'=>'2000-01-01T00:00:00Z']);
if(($argv[1]??'')==='admin') {
    // WooCommerce's order editor already wraps this hook in its own form.
    echo '<!doctype html><html><body><form id="order" action="/wp-admin/admin.php?page=wc-orders&amp;action=edit" method="post"><input name="action" value="edit_order" type="hidden"><input name="_wpnonce" value="woo_save_nonce" type="hidden">';
    JPWC_Controller::adminOrder($order);
    echo '<button id="save-order">Save order</button></form></body></html>';
} else {
    $_REQUEST=['order'=>7,'key'=>$order->get_order_key()];
    $_SERVER['REQUEST_METHOD']='GET';
    JPWC_Controller::checkout();
}
