<?php
if (!defined('ABSPATH')) { exit; }
/** Failure occurred before any non-idempotent confirm-coin POST was sent. */
final class JPWC_QuotePreparationException extends RuntimeException {}
/** Authentication / local pre-flight failed; invoice-create POST was NOT attempted. */
final class JPWC_CreatePreparationException extends RuntimeException {}
final class JPWC_API {
    private $token=null;
    public static function field($a,$snake,$camel=null) {return is_array($a)?($a[$snake]??$a[$camel??$snake]??null):null;}
    public static function key() {return defined('JANGOPAY_WC_API_KEY')?(string)JANGOPAY_WC_API_KEY:'';}
    private function request($method,$path,$body=null,$auth=true,$retry=true) {
        $base='https://api.jangopay.org';
        $headers=['Accept'=>'application/json','Content-Type'=>'application/json'];
        if($auth) { $headers['Authorization']='Bearer '.$this->token(); }
        else { $headers['Authorization']='Basic '.self::key(); }
        $args=['method'=>$method,'headers'=>$headers,'timeout'=>16,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>262144];
        if ($body!==null) {$args['body']=wp_json_encode($body,JSON_UNESCAPED_SLASHES);}
        $reply=wp_remote_request($base.$path,$args);
        if (is_wp_error($reply)) {throw new RuntimeException('transport_uncertain');}
        $code=wp_remote_retrieve_response_code($reply);
        if ($code===401 && $auth && $retry) {$this->token=null;return $this->request($method,$path,$body,true,false);} // Only retry GET calls via caller.
        $data=json_decode(wp_remote_retrieve_body($reply),true);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception code/cause only; never rendered. User-facing errors are fixed, escaped messages.
        if ($code<200||$code>=300||!is_array($data)||($data['success']??true)===false||(isset($data['code'])&&$data['code']!=='0000')) {throw new RuntimeException('merchant_api_http_'.$code);}
        return $data;
    }
    private function token() {
        if ($this->token!==null) {return $this->token;}
        if (self::key()==='') {throw new RuntimeException('missing_api_key');}
        // Explicit JSON object {}, never [] for the empty authentication POST.
        $r=$this->request('POST','/api/v1/auth/apikey/token',new stdClass(),false,false);
        $token=self::field((array)($r['data']??[]),'access_token','accessToken');
        if(!is_string($token)||$token==='') {throw new RuntimeException('missing_access_token');}
        return $this->token=$token;
    }
    public function assets() {$r=$this->request('GET','/api/v1/invoice/payment-assets');return (array)($r['items']??[]);}
    /**
     * A failed token request is NOT a failed invoice-create POST. Separate the
     * safe preparation phase from the potentially irreversible remote POST.
     * The caller may restore 'reserved' ONLY for JPWC_CreatePreparationException.
     */
    public function create($d,$beforePost=null) { // SDK normalizes ONLY top-level keys.
        try {
            $d2=[];foreach($d as $k=>$v){$d2[strtolower(preg_replace('/(?<!^)[A-Z]/','_$0',$k))]=$v;}
            $this->token(); // All authentication network I/O happens before the create POST.
            if($beforePost!==null)$beforePost();
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception code/cause only; never rendered. User-facing errors are fixed, escaped messages.
        }catch(Throwable $e){throw new JPWC_CreatePreparationException('pre_create_failed',0,$e);}
        // Once this request starts, ANY error may mean the server created an invoice.
        // Never allow the caller to re-send it without authenticated read-only recovery.
        $r=$this->request('POST','/api/v1/invoice/create',$d2,true,false);
        return (array)($r['invoice']??[]);
    }
    /** Full authenticated invoice detail envelope. A provider may return linked
     * transactions beside the invoice; dropping that envelope prevents safe
     * read-only recovery when the local webhook transaction hint is missing. */
    public function invoiceDetails($id) {return $this->request('GET','/api/v1/invoice/'.rawurlencode($id));}
    public function invoice($id) {$r=$this->invoiceDetails($id);return (array)($r['invoice']??$r);}
    public function transaction($id) {$r=$this->request('GET','/api/v1/invoice/transaction/'.rawurlencode($id));return (array)($r['transaction']??[]);}
    public function session($id) {return $this->publicGet('/api/v1/invoice/'.rawurlencode($id).'/pay-state');}
    private function publicGet($path) {$r=wp_remote_get('https://api.jangopay.org'.$path,['timeout'=>12,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>262144]);if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)throw new RuntimeException('payment_session_unavailable');$d=json_decode(wp_remote_retrieve_body($r),true);if(!is_array($d)||($d['success']??true)===false)throw new RuntimeException('invalid_payment_session');return $d;}
    /**
     * The preliminary pay-state GET is safe to retry. Callers may supply a last-moment
     * eligibility check before the irreversible confirm-coin POST. Once that POST is
     * attempted, any failure is unknown: it MUST NOT be retried blindly.
     */
    public function quote($id,$asset,$chain,$beforePost=null) {
        try {
            $r=$this->session($id);
            $session=self::field($r,'payment_session','paymentSession');
            if(!is_string($session)||$session==='')throw new RuntimeException('missing_payment_session');
            // Authentication is also preliminary: token failures cannot imply
            // that the non-repeatable confirm-coin request was submitted.
            $this->token();
            if($beforePost!==null) { $beforePost(); }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception code/cause only; never rendered. User-facing errors are fixed, escaped messages.
        }catch(Throwable $e){throw new JPWC_QuotePreparationException('pre_quote_failed',0,$e);}
        $r=$this->request('POST','/api/v1/invoice/'.rawurlencode($id).'/confirm-coin',['invoice_id'=>$id,'pay_currency'=>$asset,'chain'=>$chain,'payment_session'=>$session],true,false);
        return (array)($r['transaction']??[]);
    }
    public function findInvoice($merchantId) {return $this->findUnique('/api/v1/invoice/list','invoices','order_id','orderId',$merchantId);}
    /** Read-only legacy fallback where invoice details supply no linked IDs.
     * A full account scan must be COMPLETE before selecting a unique matching
     * settlement. Never treat the first page as proof of uniqueness. */
    public function transactionsForInvoice($invoiceId) {
        $matches=[];
        for($page=1;$page<=5;$page++) {
            $r=$this->request('GET','/api/v1/invoice/transactions?page='.$page.'&page_size=100');
            $items=(array)($r['transactions']??[]);
            foreach($items as $item) {
                if(!is_array($item) || self::field($item,'invoice_id','invoiceId')!==$invoiceId)continue;
                $matches[]=$item;
                if(count($matches)>20)throw new RuntimeException('remote_transaction_candidates_excessive');
            }
            $pages=self::field($r,'total_pages','totalPages');
            // A short or empty page is not proof that more pages do not exist.
            // Settlement auto-delivery requires reliable pagination metadata.
            if(!is_numeric($pages) || (int)$pages<1)
                throw new RuntimeException('remote_pagination_unverified');
            if($page>=(int)$pages)return $matches;
            if(count($items)===0 || $page===5)
                throw new RuntimeException('remote_search_incomplete');
        }
        return $matches;
    }
    // Historical public helper remains strict for callers which need EXACTLY
    // one account-list match. Settlement discovery does not use it.
    public function findTransaction($invoiceId) {return $this->findUnique('/api/v1/invoice/transactions','transactions','invoice_id','invoiceId',$invoiceId);}
    private function findUnique($path,$key,$snake,$camel,$id) {
        $found=null;
        for ($page=1;$page<=5;$page++) {
            $r=$this->request('GET',$path.'?page='.$page.'&page_size=100');$items=(array)($r[$key]??[]);
            foreach($items as $item){if(!is_array($item)||self::field($item,$snake,$camel)!==$id)continue;
                if($found!==null)throw new RuntimeException('ambiguous_remote_matches');$found=$item;
            }
            $pages=self::field($r,'total_pages','totalPages');
            if((is_numeric($pages)&&$page>=(int)$pages)||count($items)===0)break;
            if($page===5)throw new RuntimeException('remote_search_incomplete');
        }
        return $found;
    }
}
