<?php
if(!defined('ABSPATH')){exit;}
final class JPWC_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    protected $name='jangopay_woocommerce';
    public function initialize() {$this->settings=get_option('woocommerce_jangopay_woocommerce_settings',[]);}
    public function is_active() {
        if(($this->settings['enabled']??'no')!=='yes'||!JPWC_Controller::ready())return false;
        try {return count(JPWC_Proof::assets($this->settings['allowed_assets']??''))>0;}catch(Throwable $e){return false;}
    }
    public function get_payment_method_script_handles() {
        wp_register_script('jpwc-block',JPWC_URL.'assets/js/blocks.js',['wc-blocks-registry','wc-settings','wp-element','wp-html-entities'],JPWC_VERSION,true);
        return ['jpwc-block'];
    }
    public function get_payment_method_data() {return ['title'=>$this->settings['title']??__('JangoPay (USDT / USDC)','jangopay-for-woocommerce'),'description'=>$this->settings['description']??__('Pay with USDT or USDC through JangoPay. You will be redirected to complete your payment.','jangopay-for-woocommerce'),'icon'=>JPWC_URL.'assets/images/jangopay-icon.png','supports'=>['products']];}
}
