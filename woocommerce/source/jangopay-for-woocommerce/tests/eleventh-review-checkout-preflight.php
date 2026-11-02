<?php
/** Fake gateway transport; proves unsupported class prevents invoice API calls. */
require __DIR__.'/fixtures/review-bootstrap.php';
class UnsupportedCheckoutOrder {
    public function get_id(){return 7;}
    public function get_payment_method(){return 'jangopay_woocommerce';}
    public function is_paid(){return false;}
    public function get_status(){return 'pending';}
    public function get_currency(){return 'USD';}
    public function get_total(){return '3.00';}
}
$GLOBALS['orders'][7]=new UnsupportedCheckoutOrder();
$gateway=new JPWC_Gateway();
$result=$gateway->process_payment(7);
verify('unsupported Woo order fails closed',($result['result']??'')==='failure');
verify('unsupported order creates no invoice',empty($GLOBALS['httpCalls']['/api/v1/invoice/create']));
verify('unsupported order does not even request payment assets',empty($GLOBALS['httpCalls']['/api/v1/invoice/payment-assets']));
verify('unsupported order reserves no payment ledger',JPWC_Ledger::get(7)===null);
