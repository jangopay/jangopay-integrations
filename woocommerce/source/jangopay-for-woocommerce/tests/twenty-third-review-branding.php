<?php
namespace Automattic\WooCommerce\Blocks\Payments\Integrations { abstract class AbstractPaymentMethodType {} }
namespace {
define('ABSPATH',__DIR__.'/');define('JPWC_URL','https://store.example/plugins/jangopay-for-woocommerce/');
function __($text,$domain=null){return $text;}function esc_url($text){return htmlspecialchars($text,ENT_QUOTES);}function esc_html__($text,$domain=null){return htmlspecialchars($text,ENT_QUOTES);}function add_action(...$args){}
function get_option($name,$default=[]){return $GLOBALS['merchant']??$default;}
#[\AllowDynamicProperties]
class WC_Payment_Gateway {
 public function init_settings(){}public function init_form_fields(){}
 public function get_option($name,$empty=null){$value=$GLOBALS['merchant'][$name]??($this->form_fields[$name]['default']??'');return $value===''&&$empty!==null?$empty:$value;}
 public function admin_options(){echo '<form id="native-settings"></form>';}
}
class JPWC_Controller {public static function webhookUrl(){return 'https://store.example/?wc-api=jangopay_wc';}}
require dirname(__DIR__).'/includes/class-jpwc-gateway.php';require dirname(__DIR__).'/includes/class-jpwc-blocks.php';
function verify123($name,$ok){if(!$ok)throw new \RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;}
foreach([[],['title'=>'Merchant crypto','description'=>'Merchant instructions'],['title'=>'','description'=>'']] as $settings){
 $GLOBALS['merchant']=$settings;$gateway=new JPWC_Gateway();$blocks=new JPWC_Blocks();$blocks->initialize();$data=$blocks->get_payment_method_data();
 verify123('gateway ID unchanged',$gateway->id==='jangopay_woocommerce');
 verify123('classic title defaults only when absent',$gateway->title===($settings['title']??'JangoPay (USDT / USDC)'));
 verify123('classic custom description and empty value preserved',$gateway->description===($settings['description']??'Pay with USDT or USDC through JangoPay. You will be redirected to complete your payment.'));
 verify123('Blocks and classic title/description match',$data['title']===$gateway->title&&$data['description']===$gateway->description);
 verify123('local icon URL shared by both integrations',$gateway->icon===JPWC_URL.'assets/images/jangopay-icon.png'&&$data['icon']===$gateway->icon);
}
ob_start();(new JPWC_Gateway())->admin_options();$html=ob_get_clean();
verify123('branding keeps native settings form',str_contains($html,'jangopay-wordmark.png')&&str_contains($html,'native-settings'));
verify123('settings link published service policies',str_contains($html,'/en/legal/terms')&&str_contains($html,'/en/legal/privacy'));
verify123('settings do not display API credentials',!str_contains($html,'API_KEY')&&!str_contains($html,'PRIVATE KEY'));
}
