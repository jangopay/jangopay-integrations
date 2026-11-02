<?php
// Isolated Woo test double explicitly models an order with save guard support.
interface JPWC_Guarded_Order_Contract {}

define('JPWC_REVIEW_PLUGIN', dirname(__DIR__, 2));
/* Deterministic PHP simulation of WC order + DB conditional transitions + transport.
   Not a substitute for MySQL/Postgres or full WP/WooCommerce HTTP integration tests. */
define('ABSPATH','/nonexistent-wordpress-install/');
define('JPWC_URL','https://example.com/wp-content/plugins/jangopay-for-woocommerce/');
define('JPWC_VERSION','1.0.4');
define('JANGOPAY_WC_API_KEY','JangoPay_example_only');
define('JANGOPAY_WC_ENABLE_COLLECTION',true);
define('JANGOPAY_WC_WEBHOOK_FRAMING','appended16');
$key=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($key,$pem);
$GLOBALS['keyfile']=tempnam(sys_get_temp_dir(),'jpwc-key-');file_put_contents($GLOBALS['keyfile'],$pem);
define('JANGOPAY_WC_RSA_PRIVATE_KEY_PATH',$GLOBALS['keyfile']);
function home_url($x='/'){return 'https://test.local'.$x;}
function add_query_arg($a,$b=null,$c=null){$params=is_array($a)?$a:[$a=>$b];$url=$c??(is_array($a)?$b:'https://test.local/');return $url.'?'.http_build_query($params);}
function wp_json_encode($x,$opts=0){return json_encode($x,$opts);}
function current_time($x,$gmt=false){return '2026-10-15 00:00:00';}
function add_action($name,$callback){}
function __($x,$domain=''){return $x;}
function esc_html__($x,$domain=''){return $x;}
function esc_url($x){return $x;}
function wp_remote_retrieve_response_code($r){return $r['status'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function is_wp_error($r){return false;}
function wp_remote_request($url,$args){global $httpCalls;
    $path=parse_url($url,PHP_URL_PATH);$httpCalls[$path]=($httpCalls[$path]??0)+1;
    if (isset($GLOBALS['reviewTransport'])) { $override=($GLOBALS['reviewTransport'])($path,$args); if($override!==null)return $override; }
    if($path==='/api/v1/invoice/create'&&!empty($GLOBALS['simulateTimeout']))throw new RuntimeException('simulated_api_timeout');
    $responses=[
     '/api/v1/auth/apikey/token'=>['code'=>'0000','data'=>['accessToken'=>'mock_token']],
     '/api/v1/invoice/payment-assets'=>['items'=>[['code'=>'USDT','chain'=>'tron','invoicePaymentEnabled'=>true]]],
     '/api/v1/invoice/create'=>['invoice'=>['invoiceId'=>'I1']],
     '/api/v1/invoice/I1'=>['invoice'=>['invoiceId'=>'I1','orderId'=>$GLOBALS['merchantId']??'','amount'=>'3.00','currencyCode'=>'USD','status'=>'paid','payedAmount'=>'3.00000000']],
     '/api/v1/invoice/transaction/T1'=>['transaction'=>['txnId'=>'T1','invoiceId'=>'I1','status'=>'settled','payCurrency'=>'USDT','chain'=>'tron']],
     '/api/v1/invoice/list'=>['invoices'=>[['invoiceId'=>'I2','orderId'=>$GLOBALS['merchantId8']??'']],'totalPages'=>1],
     '/api/v1/invoice/I2'=>['invoice'=>['invoiceId'=>'I2','orderId'=>$GLOBALS['merchantId8']??'','amount'=>'3.00','currencyCode'=>'USD','status'=>!empty($GLOBALS['remote8Paid'])?'paid':'pending','payedAmount'=>!empty($GLOBALS['remote8Paid'])?'3.00':'0']],
     '/api/v1/invoice/transactions'=>['transactions'=>[['txnId'=>'T2','invoiceId'=>'I2']],'totalPages'=>1],
     '/api/v1/invoice/transaction/T2'=>['transaction'=>['txnId'=>'T2','invoiceId'=>'I2','status'=>!empty($GLOBALS['remote8Paid'])?'settled':'pending','payCurrency'=>'USDT','chain'=>'tron','payAmount'=>'3','receiveAddress'=>'TAddress']],
    ];
    if(!isset($responses[$path]))throw new RuntimeException('Unexpected '.$path);
    return ['status'=>200,'body'=>json_encode($responses[$path])];
}
function wc_get_order($id){return WC_Order_Factory::get_order($id);}
function wc_add_notice($text,$kind){$GLOBALS['notice'][]=$kind;}
class WC_Payment_Gateway {
   public $supports=[];public function init_settings(){}public function get_option($key,$default=null){return ['enabled'=>'yes','allowed_assets'=>'USDT:tron','title'=>'USDT','description'=>'Pay'][ $key ]??$default;}
   public function is_available(){return true;}
}
class WC_Abstract_Legacy_Order {}
class TestOrder extends WC_Abstract_Legacy_Order implements JPWC_Guarded_Order_Contract {
    public function get_items($type='line_item'){return []; }

    public $id=7,$status='pending',$paid=false,$delivery=0;
    public function get_id(){return $this->id;}
    public function get_order_key(){return 'wc_order_key_secret';}
    public function get_payment_method(){return 'jangopay_woocommerce';}
    public function get_currency(){return 'USD';}
    public function get_total(){return '3.00';}
    public function is_paid(){return $this->paid;}
    public function get_status(){return $this->status;}
    public function has_status($s){return $this->status===$s;}
    public function update_status($s,$note=''){$this->status=$s;}
    public function payment_complete($txn){$this->delivery++;$this->paid=true;$this->status='processing';return true;}
}
class StubDb {
    public $dbname='fixture',$prefix='wp_',$rows=[],$next=1,$columns=[],$arguments=[];
    public function insert($table,$data,$formats=[]) {if(isset($this->rows[$data['order_id']]))return false;$data['id']=$this->next++;$data['invoice_id']=null;$data['txn_id']=null;$data['last_checked_at']=null;$data['failure_code']=null;$data['asset']=null;$data['chain']=null;$data['quote_amount']=null;$data['quote_address']=null;$data['expires_at']=null;$this->rows[$data['order_id']]=$data;return 1;}
    public function prepare($sql,...$args) {if(count($args)===1&&is_array($args[0]))$args=$args[0];if(str_starts_with($sql,'SHOW COLUMNS'))return 'SCHEMA:'.$args[0];if(str_starts_with($sql,'SELECT GET_LOCK('))return 'LOCK:'.(string)$args[0];
        if(str_starts_with($sql,'SELECT RELEASE_LOCK('))return 'UNLOCK:'.(string)$args[0];
        if(str_starts_with($sql,'SELECT'))return 'LOOKUP:'.(int)$args[0];
        preg_match('/SET (.*) WHERE id=%d AND state IN/',$sql,$m);preg_match_all('/`([^`]+)`=%s/',$m[1]??'',$names);
        $this->columns=$names[1];$this->arguments=$args;return 'CAS';}
    public function get_var($sql){
        if(str_starts_with((string)$sql,'SCHEMA:'))return 'items_hash';
        if(str_starts_with((string)$sql,'LOCK:')||str_starts_with((string)$sql,'UNLOCK:'))return 1;
        throw new RuntimeException('Unexpected advisory lock SQL '.$sql);
    }
    public function get_row($sql){$id=(int)substr($sql,7);return isset($this->rows[$id])?(object)$this->rows[$id]:null;}
    public function query($sql){$a=$this->arguments;$len=count($this->columns);$id=(int)$a[$len];$allowed=array_slice($a,$len+1);
        $key=null;foreach($this->rows as $oid=>$rec)if($rec['id']===$id)$key=$oid;
        if($key===null||!in_array($this->rows[$key]['state'],$allowed,true))return 0;
        for($i=0;$i<$len;$i++)$this->rows[$key][$this->columns[$i]]=$a[$i];return 1;}
    public function update($table,$updates,$where,$f=null,$wf=null){$id=$where['id'];foreach($this->rows as &$r)if($r['id']===$id){$r=array_merge($r,$updates);return 1;}return 0;}
}
$GLOBALS['wpdb']=new StubDb();$GLOBALS['orders']=[7=>new TestOrder()];
require JPWC_REVIEW_PLUGIN.'/includes/class-jpwc-money.php';
require JPWC_REVIEW_PLUGIN.'/includes/class-jpwc-api.php';
require JPWC_REVIEW_PLUGIN.'/includes/class-jpwc-ledger.php';
require JPWC_REVIEW_PLUGIN.'/includes/class-jpwc-proof.php';
require JPWC_REVIEW_PLUGIN.'/includes/class-jpwc-controller.php';
require JPWC_REVIEW_PLUGIN.'/includes/class-jpwc-order-guard.php';
require JPWC_REVIEW_PLUGIN.'/includes/class-jpwc-gateway.php';
function verify($label,$v){static $n=0;if(!$v)throw new RuntimeException('FAIL '.$label);$n++;echo "PASS $n $label\n";}

register_shutdown_function(static function(){ if(is_file($GLOBALS['keyfile']))unlink($GLOBALS['keyfile']); });
