<?php
if(!defined('ABSPATH')){exit;}
final class JPWC_Controller {
    public static function boot() {
        add_action('woocommerce_api_jangopay_wc_checkout',[__CLASS__,'checkout']);
        add_action('woocommerce_api_jangopay_wc',[__CLASS__,'webhook']);
        add_action('wp_ajax_jpwc_status',[__CLASS__,'status']);
        add_action('wp_ajax_nopriv_jpwc_status',[__CLASS__,'status']);
        add_action('wp_ajax_jpwc_connection',[__CLASS__,'connection']);
        add_action('admin_post_jpwc_reverify',[__CLASS__,'adminReverify']);
        add_action('wp_ajax_jpwc_reverify',[__CLASS__,'ajaxReverify']);
        add_action('woocommerce_admin_order_data_after_order_details',[__CLASS__,'adminOrder']);
        add_action('jpwc_reconcile_tick',[__CLASS__,'scheduled']);
        add_action('admin_notices',[__CLASS__,'notices']);
        add_action('admin_enqueue_scripts',[__CLASS__,'adminAssets']);
        add_action('init',static function(){
            if(!current_user_can('manage_woocommerce') || JPWC_Ledger::schemaReady())return;
            try{JPWC_Ledger::install();}
            catch(Throwable $e){
                // Keep the old schema version so an admin can retry the DDL.
                // No buyer checkout is permitted without a verified schema.
                add_action('admin_notices',static function(){
                    if (!JPWC_Controller::isSettingsScreen()) { return; }
                    echo '<div class="notice notice-error"><p>'.esc_html__('JangoPay database upgrade is incomplete. Payment creation has been disabled until the required schema is installed.','jangopay-for-woocommerce').'</p></div>';
                });
            }
        });
    }
    /** Keep configuration and plugin notices off unrelated WordPress admin screens. */
    public static function isSettingsScreen() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display context.
        $page=isset($_GET['page'])&&is_string($_GET['page'])?sanitize_key(wp_unslash($_GET['page'])):'';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display context.
        $tab=isset($_GET['tab'])&&is_string($_GET['tab'])?sanitize_key(wp_unslash($_GET['tab'])):'';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display context.
        $section=isset($_GET['section'])&&is_string($_GET['section'])?sanitize_key(wp_unslash($_GET['section'])):'';
        return $page==='wc-settings' && $tab==='checkout' && $section==='jangopay_woocommerce';
    }
    /** Enqueue only on WooCommerce order editing and this gateway's settings screen. */
    public static function adminAssets($hook='') {
        if (!current_user_can('manage_woocommerce')) { return; }
        $orderPage=($hook==='woocommerce_page_wc-orders');
        if ($hook==='post.php' && function_exists('get_current_screen')) {
            $screen=get_current_screen();
            $orderPage=$screen && ($screen->post_type??'')==='shop_order';
        }
        if (!$orderPage && !self::isSettingsScreen()) { return; }
        self::enqueueAdminScript();
    }
    private static function enqueueAdminScript() {
        static $added=false;
        if ($added) { return; }
        $added=true;
        wp_enqueue_script('jpwc-admin',JPWC_URL.'assets/js/admin.js',[],JPWC_VERSION,true);
        wp_add_inline_script('jpwc-admin','window.JPWC_AdminConfig = '.wp_json_encode([
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'connectionNonce'=>wp_create_nonce('jpwc_connection'),
        ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
    }
    public static function keyPath() {return defined('JANGOPAY_WC_RSA_PRIVATE_KEY_PATH')?(string)JANGOPAY_WC_RSA_PRIVATE_KEY_PATH:'';}
    public static function ready() {
        if(!str_starts_with((string)home_url('/'),'https://')||!defined('JANGOPAY_WC_ENABLE_COLLECTION')||JANGOPAY_WC_ENABLE_COLLECTION!==true||JPWC_API::key()===''||
           !defined('JANGOPAY_WC_WEBHOOK_FRAMING')||JANGOPAY_WC_WEBHOOK_FRAMING!=='appended16'||!function_exists('openssl_pkey_get_private'))return false;
        $path=self::keyPath(); if(!is_file($path)||!is_readable($path))return false;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server filesystem path is canonicalized with realpath and used only to reject keys under public roots; preserve path bytes.
        $root=realpath(ABSPATH);$docroot=!empty($_SERVER['DOCUMENT_ROOT'])?realpath((string)$_SERVER['DOCUMENT_ROOT']):false;$real=realpath($path);
        if(!$real)return false;
        foreach([$root,$docroot] as $forbidden){if($forbidden!==false&&$forbidden!==null&&is_dir($forbidden)&&str_starts_with($real,rtrim($forbidden,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))return false;}
        $rsa=@openssl_pkey_get_private('file://'.$real);if($rsa===false)return false;
        $details=openssl_pkey_get_details($rsa);return is_array($details)&&($details['type']??null)===OPENSSL_KEYTYPE_RSA;
    }
    /**
     * Invalidate ALL recognized WooCommerce / WordPress order read caches before
     * a security-sensitive reload. OrderCache eviction alone is not sufficient:
     * the legacy CPT data store also reads WP's posts and post_meta caches.
     * HPOS can additionally cache rows/meta in its own datastore.
     *
     * This narrows stale-read windows; it does NOT provide a cross-request
     * database lock between this read and payment_complete().
     */
    public static function freshOrder($id) {
        $id=(int)$id;
        if($id<=0)return false;
        $util='Automattic\WooCommerce\Utilities\OrderUtil';
        $isHpos=false;
        if(class_exists($util) && method_exists($util,'custom_orders_table_usage_is_enabled'))
            $isHpos=(bool)$util::custom_orders_table_usage_is_enabled();
        if(!$isHpos) {
            // In CPT mode a freshly constructed WC_Order can still read stale
            // get_post()/get_post_meta() values retained in THIS request.
            if(!function_exists('wp_cache_delete'))throw new RuntimeException('wp_cache_invalidation_unavailable');
            // WordPress clean_post_cache normally clears both keys, unless WP
            // cache invalidation is suspended. Delete explicitly as well.
            if(function_exists('clean_post_cache'))clean_post_cache($id);
            wp_cache_delete($id,'posts');
            wp_cache_delete($id,'post_meta');
        } elseif(class_exists($util) && method_exists($util,'custom_orders_table_datastore_cache_enabled') &&
                 $util::custom_orders_table_datastore_cache_enabled()) {
            // WooCommerce 10.3 HPOS datastore cache is independent of OrderCache.
            $storeClass='Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore';
            if(!class_exists($storeClass)||!function_exists('wc_get_container'))throw new RuntimeException('hpos_cache_invalidation_unavailable');
            $store=wc_get_container()->get($storeClass);
            if(!is_object($store)||!method_exists($store,'clear_cached_data'))throw new RuntimeException('hpos_cache_invalidation_unavailable');
            $store->clear_cached_data([$id]);
            // In WooCommerce 10.3, deleting a cache key which was ABSENT may
            // return false. Treat actual remaining cached objects as failure,
            // not a "false" deletion result alone. The datastore implementation
            // also skips clearing orders_meta if orders_data was already absent.
            $engineClass='Automattic\WooCommerce\Caching\WPCacheEngine';
            if(!class_exists($engineClass))throw new RuntimeException('hpos_cache_engine_unavailable');
            $engine=wc_get_container()->get($engineClass);
            if(!is_object($engine)||!method_exists($engine,'is_cached')||!method_exists($engine,'delete_cached_object'))
                throw new RuntimeException('hpos_cache_engine_unavailable');
            foreach(['orders_data','orders_meta'] as $group) {
                if($engine->is_cached((string)$id,$group))$engine->delete_cached_object((string)$id,$group);
                if($engine->is_cached((string)$id,$group))throw new RuntimeException('hpos_cache_invalidation_failed');
            }
        }
        if(class_exists($util) && method_exists($util,'orders_cache_usage_is_enabled') && $util::orders_cache_usage_is_enabled()) {
            $cacheClass='Automattic\WooCommerce\Caches\OrderCache';
            if(!class_exists($cacheClass) || !function_exists('wc_get_container'))throw new RuntimeException('woo_order_cache_unavailable');
            $cache=wc_get_container()->get($cacheClass);
            if(!is_object($cache) || !method_exists($cache,'remove'))throw new RuntimeException('woo_order_cache_cannot_invalidate');
            $cache->remove($id);
        }
        return wc_get_order($id);
    }
    /** Recheck the live Woo order before issuing or displaying payment instructions. */
    public static function orderPayable($order,$row) {
        if(!$order || $order->is_paid() || !in_array($order->get_status(),['pending','on-hold'],true) ||
           $order->get_payment_method()!=='jangopay_woocommerce' || strtoupper($order->get_currency())!=='USD')return false;
        try {return JPWC_Money::usd((string)$order->get_total())===(int)$row->amount_cents;}
        catch(Throwable $e){return false;}
    }
    /** Do not present an expired/unparseable quote as payable, including after a refresh. */
    /** Checkout status must only advertise a payment option while the ledger remains eligible. */
    public static function canPay($order,$row) {
        if(!$row || !self::orderPayable($order,$row))return false;
        try { JPWC_Items::assertRow($row,$order); } catch(Throwable $e) { return false; }
        if($row->state==='awaiting_payment')return true;
        return $row->state==='quoted' && self::quoteActive($row);
    }
    public static function quoteActive($row) {
        if(!$row || $row->state!=='quoted' || !is_string($row->expires_at) || $row->expires_at==='')return false;
        // The provider uses ISO-like absolute timestamps; never accept relative dates.
        if(!preg_match('/^\d{4}-\d{2}-\d{2}[T ][0-2]\d:[0-5]\d:[0-5]\d(?:\.\d{1,9})?(?:Z|[+-][0-2]\d:?[0-5]\d)$/D', $row->expires_at))return false;
        $epoch=strtotime($row->expires_at);
        return $epoch!==false && $epoch>time();
    }
    public static function webhookUrl() {return add_query_arg('wc-api','jangopay_wc',home_url('/'));}
    public static function checkoutUrl($order) {return add_query_arg(['wc-api'=>'jangopay_wc_checkout','order'=>(int)$order->get_id(),'key'=>$order->get_order_key()],home_url('/'));}
    /** Provider-owned hosted checkout. Only issue URLs with strict, safe invoice IDs. */
    public static function hostedUrl($invoiceId) {
        if(!is_string($invoiceId)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,109}$/D',$invoiceId))return null;
        // This path is currently established by the published Dujiaoka reference
        // integration. General API-merchant support is a PRE-RELEASE assumption
        // requiring explicit confirmation and a real buyer browser test.
        return 'https://app.jangopay.org/pay/'.rawurlencode($invoiceId);
    }
    /** Never redirect an unpaid/cancelled/repriced or uncertain-quote order. */
    public static function hostedForOrder($order,$row) {
        if(!$row || $row->state!=='awaiting_payment' || !self::orderPayable($order,$row))return null;
        try { JPWC_Items::assertRow($row,$order); } catch(Throwable $e) { return null; }
        return self::hostedUrl($row->invoice_id);
    }
    private static function access() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only order-key authorization / settings display; state-changing actions verify their own nonce.
        $id=isset($_REQUEST['order'])&&is_scalar($_REQUEST['order'])?absint(wp_unslash($_REQUEST['order'])):0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bearer-key access; writes have independent nonce checks.
        $key=isset($_REQUEST['key'])&&is_string($_REQUEST['key'])?sanitize_text_field(wp_unslash($_REQUEST['key'])):'';
        try {$order=$id?self::freshOrder($id):null;} catch(Throwable $e){return null;}
        if(!$order||!$key||!hash_equals((string)$order->get_order_key(),$key)||$order->get_payment_method()!=='jangopay_woocommerce')return null;
        if($order->get_customer_id()>0 && is_user_logged_in() && get_current_user_id()!==(int)$order->get_customer_id() && !current_user_can('edit_shop_order',$id))return null;
        // For guests, WooCommerce's long unguessable order key acts as bearer credential.
        // Do not reveal orders from numeric IDs alone, and send no order/customer PII to AJAX.
        return $order;
    }
    private static function fail($code=403) { status_header($code);nocache_headers();header('Content-Type: text/plain; charset=UTF-8');echo 'JangoPay request unavailable.';exit; }
    /**
     * Buyer-safe return/recovery screen, not a secondary checkout.
     * No GET or POST here may start a new coin quote or expose an address.
     * The only write is nonce-guarded recovery of an invoice create that was
     * conclusively NOT sent to JangoPay (the reserved ledger state).
     */
    public static function checkout() {
        $order=self::access();if(!$order)self::fail();
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Content-Type-Options: nosniff');
        $row=JPWC_Ledger::get($order->get_id());if(!$row)self::fail(404);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Protocol fields are compared strictly / size-bounded before use; preserve raw bytes rather than normalize invalid input into valid headers.
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
            // The previously implemented asset-selection POST is deliberately
            // removed: the provider-hosted checkout owns all coin selection.
            $action=isset($_POST['jpwc_action'])?sanitize_text_field(wp_unslash($_POST['jpwc_action'])):'';
            if($action!=='resume_reserved')self::fail(405);
            if(!isset($_POST['jpwc_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['jpwc_nonce'])),'jpwc_resume_'.$order->get_id()))self::fail(403);
            if($row->state!=='reserved'||$row->invoice_id!==null||!self::orderPayable($order,$row))self::fail(409);
            $gateway=new JPWC_Gateway();
            if(!$gateway->is_available())self::fail(503);
            // One-time CAS already guards process_payment. No remote create
            // happens from GET, from an unknown POST, or from an expired quote.
            $result=$gateway->process_payment($order->get_id());
            if(($result['result']??'')==='success') {
                $target=(string)($result['redirect']??'');
                if(self::isHostedUrl($target)) {
                    // Permit the verified JangoPay host only for this redirect.
                    // The WordPress allowlist is removed even if redirect handling fails.
                    $allowHosted = static function ($hosts) {
                        $hosts = (array) $hosts;
                        $hosts[] = 'app.jangopay.org';
                        return array_values(array_unique($hosts));
                    };
                    add_filter('allowed_redirect_hosts', $allowHosted);
                    try {
                        $redirected = wp_safe_redirect($target, 303, 'JangoPay for WooCommerce');
                    } finally {
                        remove_filter('allowed_redirect_hosts', $allowHosted);
                    }
                    if ($redirected) { exit; }
                }
            }
            wp_safe_redirect(self::checkoutUrl($order),303);exit;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Protocol fields are compared strictly / size-bounded before use; preserve raw bytes rather than normalize invalid input into valid headers.
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')self::fail(405);
        // A browser return is NEVER payment proof. The paid Woo order was
        // already checked through the signed webhook / merchant API pathway.
        if($order->is_paid() && $row->state==='completed') {
            wp_safe_redirect($order->get_checkout_order_received_url(),303);exit;
        }
        $hosted=self::hostedForOrder($order,$row);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>JangoPay Order Status</title>';
        // This is an isolated WC-API HTML response (no theme wp_head): use WP's
        // stylesheet registry and print only the registered checkout handle.
        wp_enqueue_style('jpwc-checkout',JPWC_URL.'assets/css/checkout.css',[],JPWC_VERSION);
        wp_print_styles('jpwc-checkout');
        echo '</head><body><main class="jp"><h1>JangoPay</h1>';
        echo '<p>Order <strong>#'.esc_html($order->get_order_number()).'</strong> &middot; USD '.esc_html(JPWC_Money::formatUsd((int)$row->amount_cents)).'</p>';
        if($order->is_paid()) {
            // A mismatched local ledger cannot be silently treated as fulfilled.
            echo '<p>Payment status is being reconciled. Please check your order or contact the store if it does not update.</p>';
        }elseif($row->state==='completed') {
            echo '<p>Payment has been confirmed. Your order status is updating.</p>';
        }elseif(!self::orderPayable($order,$row)) {
            echo '<p>This order is no longer eligible for payment. Do not send additional funds; contact the store.</p>';
        }elseif($row->state==='reserved' && $row->invoice_id===null) {
            echo '<p>JangoPay could not finish preparing the invoice. No invoice creation request was sent. You can safely continue this order.</p>';
            echo '<form method="post" id="jpwc-resume" action="'.esc_url(self::checkoutUrl($order)).'">';
            wp_nonce_field('jpwc_resume_'.$order->get_id(),'jpwc_nonce');
            echo '<input type="hidden" name="jpwc_action" value="resume_reserved"><button type="submit">Continue payment</button></form>';
        }elseif($hosted!==null) {
            echo '<p>Complete your payment using the secure JangoPay checkout.</p>';
            echo '<p><a class="action" id="jpwc-hosted-link" href="'.esc_url($hosted).'" rel="noreferrer noopener" referrerpolicy="no-referrer">Continue to JangoPay</a></p>';
            echo '<p class="minor">If you have already paid, please wait for confirmation. Do not send a second payment.</p>';
        }elseif(in_array($row->state,['quoted','quote_unknown'],true)) {
            // Legacy 1.0.7 quotes are intentionally NOT recreated or displayed.
            echo '<p>An earlier payment attempt is under verification. Do not send funds again or generate a new quote. Contact the store for assistance.</p>';
        }else {
            echo '<p>Payment setup or verification is still in progress. Do not send funds or start another payment. Contact the store if this does not resolve.</p>';
        }
        echo '<p id="jpwc-status" class="minor" role="status">Waiting for payment confirmation</p>';
        if(!$order->is_paid()) {
            wp_enqueue_script('jpwc-checkout',JPWC_URL.'assets/js/checkout.js',[],JPWC_VERSION,true);
            wp_add_inline_script('jpwc-checkout','window.JPWC_CheckoutConfig = '.wp_json_encode([
                'ajaxUrl'=>admin_url('admin-ajax.php'),
                'params'=>['action'=>'jpwc_status','order'=>$order->get_id(),'key'=>$order->get_order_key()],
                'receivedUrl'=>$order->get_checkout_order_received_url(),
                'initialState'=>$row->state,
            ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';','before');
        }
        echo '</main>';
        // This WC-API response has no theme wp_footer; print queued scripts.
        if (!$order->is_paid()) { wp_print_footer_scripts(); }
        echo '</body></html>';exit;
    }
    /** Validate exactly the official host/path; never allow a configurable open redirect. */
    public static function isHostedUrl($url) {
        if(!is_string($url))return false;
        $parts=wp_parse_url($url);
        return is_array($parts)&&($parts['scheme']??'')==='https'&&($parts['host']??'')==='app.jangopay.org'&&
               !isset($parts['port'])&&!isset($parts['user'])&&!isset($parts['pass'])&&
               !isset($parts['query'])&&!isset($parts['fragment'])&&
               isset($parts['path'])&&preg_match('~^/pay/[A-Za-z0-9][A-Za-z0-9_-]{0,109}$~D',$parts['path'])===1;
    }
    public static function status() {
        $order=self::access();if(!$order)wp_send_json_error(['code'=>'unauthorized'],403);
        $r=JPWC_Ledger::get($order->get_id());nocache_headers();
        wp_send_json_success(['state'=>$r?$r->state:'missing','paid'=>$order->is_paid(),'can_pay'=>self::canPay($order,$r)]);
    }
    public static function webhook() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Protocol fields are compared strictly / size-bounded before use; preserve raw bytes rather than normalize invalid input into valid headers.
        if($_SERVER['REQUEST_METHOD']!=='POST')self::fail(405);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Protocol fields are compared strictly / size-bounded before use; preserve raw bytes rather than normalize invalid input into valid headers.
        $len=(int)($_SERVER['CONTENT_LENGTH']??0);if($len>32768)self::fail(413);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Protocol fields are compared strictly / size-bounded before use; preserve raw bytes rather than normalize invalid input into valid headers.
        $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));
        if($contentType!=='application/json')self::fail(415);
        $raw=file_get_contents('php://input',false,null,0,32769);if(strlen($raw)>32768)self::fail(413);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Protocol fields are compared strictly / size-bounded before use; preserve raw bytes rather than normalize invalid input into valid headers.
        $headers=[];foreach(['event','encryption-alg','key-encryption'] as $k)$headers['x-jangopay-'.$k]=(string)($_SERVER['HTTP_X_JANGOPAY_'.strtoupper(str_replace('-','_',$k))]??'');
        try {
            $d=JPWC_Webhook::decrypt($headers,$raw);
            $invoice=JPWC_API::field($d,'invoice_id','invoiceId');$hint=JPWC_API::field($d,'txn_id','txnId');
            if(!is_string($invoice)||strlen($invoice)>110||$invoice==='')throw new RuntimeException('no_invoice_hint');
            $row=JPWC_Ledger::invoice($invoice);if(!$row)throw new RuntimeException('unknown_invoice');
            self::verifyRow($row,is_string($hint)?$hint:null);
            status_header(200);echo 'ok';
        }catch(Throwable $e){status_header(422);echo 'not accepted';}
        exit;
    }
    public static function verifyRow($row,$hint=null) {
        if(in_array($row->state,['completed','delivery_claimed','delivery_attention','manual_review'],true))return $row->state;
        $order=self::freshOrder((int)$row->order_id);if(!$order){JPWC_Ledger::cas($row,[$row->state],['state'=>'manual_review','failure_code'=>'local_order_missing']);return 'manual_review';}
        $api=new JPWC_API();JPWC_Ledger::touch($row);
        if(in_array($row->state,['creating_unknown','creating_inflight'],true)){
            $candidate=$api->findInvoice($row->merchant_order_id);
            if($candidate!==null){
                $rid=JPWC_API::field($candidate,'invoice_id','invoiceId');
                if(is_string($rid)&&$rid!==''&&JPWC_API::field($candidate,'order_id','orderId')===$row->merchant_order_id){
                    JPWC_Ledger::cas($row,['creating_unknown','creating_inflight'],['invoice_id'=>$rid,'state'=>'awaiting_payment']);
                }
            }
            $row=JPWC_Ledger::get($row->order_id);
        }
        if(!$row||!$row->invoice_id||!in_array($row->state,['awaiting_payment','quoted','quote_unknown'],true))return $row?$row->state:'missing';
        $details=$api->invoiceDetails($row->invoice_id);
        $invoice=(array)($details['invoice']??$details);
        $invoiceStatus=strtolower(trim((string)JPWC_API::field($invoice,'status','status')));
        $txnId=$hint?:$row->txn_id;
        $verifiedTxn=null;
        if(!$txnId) {
            // Hosted checkout intentionally has no local confirm-coin txn_id.
            // Recover ONLY from authenticated, invoice-linked read-only records.
            // No reason to enumerate the merchant's entire account for a still
            // unpaid invoice (legacy quote_unknown remains a separate path).
            if($invoiceStatus!=='paid' && $row->state!=='quote_unknown')return 'pending';
            if($invoiceStatus!=='paid' && $row->state==='quote_unknown') {
                // Historical V1.0.7 unfinished local quote: an UNSETTLED txn
                // can still carry the original quotation in the legacy lookup.
                // Preserve that read-only path for old outstanding attempts.
                $candidate=$api->findTransaction($row->invoice_id);
                $txnId=$candidate===null?null:JPWC_API::field($candidate,'txn_id','txnId');
            } else {
                $discovery=self::discoverSettledTransaction($api,$details,$invoice,$row,$order);
                if(($discovery['status']??null)==='manual_review') {
                    JPWC_Ledger::cas($row,['awaiting_payment','quoted','quote_unknown'],[
                        'state'=>'manual_review','failure_code'=>$discovery['reason']]);
                    return 'manual_review';
                }
                $txnId=$discovery['id']??null;
                $verifiedTxn=$discovery['transaction']??null;
            }
        }
        if($row->state==='quote_unknown' && $txnId){self::recoverQuote($row,$api,$txnId);$row=JPWC_Ledger::get($row->order_id);}
        if($invoiceStatus!=='paid'||!$txnId)return 'pending';
        // A payment noticed after quote expiry cannot be safely classified as on-time
        // without a merchant-confirmed settlement timestamp. Fail closed to review.
        if($row->expires_at!==null&&$row->expires_at!==''){
            $expiry=strtotime($row->expires_at);
            if($expiry===false || time()>$expiry){
                JPWC_Ledger::cas($row,['awaiting_payment','quoted','quote_unknown'],['state'=>'manual_review','failure_code'=>'late_or_unparseable_expiry']);
                return 'manual_review';
            }
        }
        $txn=$verifiedTxn??$api->transaction($txnId);
        $result=JPWC_Proof::check($invoice,$txn,$row,$order);
        if($result!=='verified') {
            if(in_array($result,['invoice_not_paid'],true))return 'pending';
            JPWC_Ledger::cas($row,['awaiting_payment','quoted','quote_unknown'],['state'=>'manual_review','failure_code'=>$result]);
            return 'manual_review';
        }
        try { JPWC_Items::assertRow($row,$order); }
        catch(Throwable $e) {
            JPWC_Ledger::cas($row,['awaiting_payment','quoted','quote_unknown'],['state'=>'manual_review','failure_code'=>'order_items_mismatch_or_missing']);
            return 'manual_review';
        }
        // Claim before invoking Woo side effects. No stale claim auto-retry on worker death.
        $claim=JPWC_Ledger::cas($row,['awaiting_payment','quoted','quote_unknown'],['state'=>'delivery_claimed','txn_id'=>(string)$txnId]);
        if(!$claim)return 'already_claimed';
        try {
            // This DB advisory lock is shared with WooCommerce's order-save
            // hooks (including other PHP workers' normal admin/extension saves).
            // It protects the final read THROUGH the native payment_complete()
            // and status/stock/download hooks; a freshOrder() alone did not.
            JPWC_Order_Guard::fulfill($order->get_id(), static function($allowPaymentSave)use($order,$row,$txnId){
                $fresh=self::freshOrder($order->get_id());
                if(!self::orderPayable($fresh,$row))throw new RuntimeException('order_changed_after_claim');
                JPWC_Items::assertRow($row,$fresh);
                $allowPaymentSave($fresh);
                $completed=$fresh->payment_complete($txnId);
                if($completed!==true || !$fresh->is_paid())throw new RuntimeException('woo_payment_complete_failed');
                // Complete side effects before releasing the cross-request lock.
                $persisted=self::freshOrder($order->get_id());
                JPWC_Items::assertRow($row,$persisted);
                if(!$persisted || !$persisted->is_paid() ||
                   $persisted->get_payment_method()!=='jangopay_woocommerce' ||
                   strtoupper((string)$persisted->get_currency())!=='USD' ||
                   JPWC_Money::usd((string)$persisted->get_total())!==(int)$row->amount_cents)
                    throw new RuntimeException('woo_post_payment_mismatch');
            });
            JPWC_Ledger::cas($row,['delivery_claimed'],['state'=>'completed']);
            return 'completed';
        }catch(Throwable $e){JPWC_Ledger::cas($row,['delivery_claimed'],['state'=>'delivery_attention','failure_code'=>'delivery_uncertain']);return 'delivery_attention';}
    }
    /**
     * Read-only, fail-closed missing-webhook settlement discovery.
     * An invoice detail envelope may contain a linked transactions array; each
     * candidate must STILL be fetched using authenticated merchant GET and
     * checked against the exact invoice/order/asset/amount/fee contract. The
     * returned list is NOT a replacement for merchant transaction verification.
     * If linked IDs are absent, use the legacy bounded account scan, but never
     * claim completeness when that search exceeds the scan cap.
     */
    public static function discoverSettledTransaction($api,$details,$invoice,$row,$order) {
        $linked=JPWC_API::field($details,'transactions','transactions');
        if($linked===null) $linked=JPWC_API::field($invoice,'transactions','transactions');
        if($linked!==null && !is_array($linked))
            return ['status'=>'manual_review','reason'=>'invalid_invoice_transaction_links'];
        // A non-empty authenticated invoice-linked list avoids the global
        // account-volume bound. Empty/missing lists use complete bounded scan.
        $candidates=is_array($linked)&&$linked!==[] ? $linked : $api->transactionsForInvoice($row->invoice_id);
        if(count($candidates)>20)
            return ['status'=>'manual_review','reason'=>'too_many_invoice_transactions'];
        $visited=[];$verified=[];$invalidSettled=false;
        foreach($candidates as $candidate) {
            if(!is_array($candidate))
                return ['status'=>'manual_review','reason'=>'invalid_invoice_transaction_links'];
            $id=JPWC_API::field($candidate,'txn_id','txnId');
            $linkedInvoice=JPWC_API::field($candidate,'invoice_id','invoiceId');
            if(!is_string($id)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,109}$/D',$id)||
               ($linkedInvoice!==null && $linkedInvoice!==$row->invoice_id))
                return ['status'=>'manual_review','reason'=>'invalid_invoice_transaction_links'];
            if(isset($visited[$id]))continue;
            $visited[$id]=true;
            // An invoice-linked list entry can have a stale status. Fetch each
            // candidate by ID and trust only the authenticated detail's status.
            $txn=$api->transaction($id);
            if(strtolower((string)JPWC_API::field($txn,'status','status'))!=='settled')continue;
            $proof=JPWC_Proof::check($invoice,$txn,$row,$order);
            if($proof!=='verified') { $invalidSettled=true;continue; }
            $verified[$id]=$txn;
            // There is no automatic rule to sum multiple settled transactions
            // into a single Woo checkout. Ambiguous valid settlements are held.
            if(count($verified)>1)
                return ['status'=>'manual_review','reason'=>'ambiguous_settled_transactions'];
        }
        if(count($verified)===1) {
            $id=array_key_first($verified);
            return ['status'=>'verified','id'=>$id,'transaction'=>$verified[$id]];
        }
        if($invalidSettled)
            return ['status'=>'manual_review','reason'=>'unverifiable_settled_transaction'];
        return ['status'=>'pending'];
    }
    private static function recoverQuote($row,$api,$id) {
        try {
            $txn=$api->transaction($id);
            $t=JPWC_API::field($txn,'txn_id','txnId');$i=JPWC_API::field($txn,'invoice_id','invoiceId');
            $coin=strtoupper((string)JPWC_API::field($txn,'pay_currency','payCurrency'));$chain=strtolower((string)JPWC_API::field($txn,'chain','chain'));
            $amount=JPWC_API::field($txn,'pay_amount','payAmount');$address=JPWC_API::field($txn,'receive_address','receiveAddress');
            if($t!==$id||$i!==$row->invoice_id||$coin!==$row->asset||$chain!==$row->chain||
                !is_string($address)||$address===''||strlen($address)>512||(!is_string($amount)&&!is_int($amount))||
                !preg_match('/^(?:0|[1-9][0-9]{0,20})(?:\.[0-9]{1,18})?$/D',(string)$amount)||!preg_match('/[1-9]/',(string)$amount))return;
            JPWC_Ledger::cas($row,['quote_unknown'],['state'=>'quoted','txn_id'=>$id,'quote_amount'=>(string)$amount,'quote_address'=>$address,'expires_at'=>(string)JPWC_API::field($txn,'expires_at','expiresAt')]);
        }catch(Throwable $e){}
    }
    public static function scheduled() {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fresh payment ledger reads and atomic state transitions cannot use object-cache results; identifiers are validated and values prepared.
        $table=JPWC_Ledger::table();$rows=$wpdb->get_results("SELECT * FROM $table WHERE state IN ('creating_unknown','creating_inflight','awaiting_payment','quoted','quote_unknown') ORDER BY COALESCE(last_checked_at,'1970-01-01') ASC, id ASC LIMIT 20");
        foreach((array)$rows as $r)try{self::verifyRow($r);}catch(Throwable $e){JPWC_Ledger::touch($r);}
    }
    public static function adminOrder($order) {
        if(!$order||$order->get_payment_method()!=='jangopay_woocommerce'||!current_user_can('manage_woocommerce'))return;
        $r=JPWC_Ledger::get($order->get_id());echo '<div class="jpwc-admin"><h3>JangoPay</h3>';
        if($r){echo '<p>State: '.esc_html($r->state).' / Invoice: '.esc_html((string)$r->invoice_id).' / Transaction: '.esc_html((string)$r->txn_id).'</p>';
            echo '<p>Last checked: '.esc_html((string)$r->last_checked_at).' / Issue code: '.esc_html((string)$r->failure_code).'</p>';
            if(!in_array($r->state,['completed','delivery_claimed','delivery_attention','manual_review'],true)){
                // The Woo order editor already contains an enclosing <form>. Never nest forms.
                echo '<button type="button" class="button jpwc-reverify" data-order="'.esc_attr($order->get_id()).'" data-nonce="'.esc_attr(wp_create_nonce('jpwc_reverify_'.$order->get_id())).'">Reverify with JangoPay (may complete and fulfil this order)</button><span class="jpwc-reverify-result" role="status"></span>';
                self::enqueueAdminScript();
            } elseif($r->state==='manual_review')echo '<p>Manual audit required. Automatic delivery is intentionally disabled for this record.</p>';
        }else echo '<p>No JangoPay ledger record.</p>';
        echo '</div>';
    }
    public static function ajaxReverify() {
        if(!current_user_can('manage_woocommerce'))wp_send_json_error(['message'=>'Forbidden'],403);
        $id=absint($_POST['order']??0);
        if(!$id || !check_ajax_referer('jpwc_reverify_'.$id,'nonce',false))wp_send_json_error(['message'=>'Forbidden'],403);
        $order=wc_get_order($id);
        if(!$order || $order->get_payment_method()!=='jangopay_woocommerce')wp_send_json_error(['message'=>'Not found'],404);
        $r=JPWC_Ledger::get($id);
        if(!$r)wp_send_json_error(['message'=>'No JangoPay record'],404);
        try{$state=self::verifyRow($r);wp_send_json_success(['state'=>$state]);}
        catch(Throwable $e){wp_send_json_error(['message'=>'Merchant verification unavailable'],503);}
    }
    public static function adminReverify() {
        if(!current_user_can('manage_woocommerce'))wp_die('Forbidden',403);
        $id=absint($_POST['order']??0);check_admin_referer('jpwc_reverify_'.$id);
        $r=JPWC_Ledger::get($id);if($r)try{self::verifyRow($r);}catch(Throwable $e){}
        $order=wc_get_order($id);wp_safe_redirect($order?$order->get_edit_order_url():admin_url('admin.php?page=wc-orders'));exit;
    }
    public static function connection() {
        if(!current_user_can('manage_woocommerce')||!check_ajax_referer('jpwc_connection','nonce',false))wp_send_json_error(['message'=>'Forbidden'],403);
        try{$assets=(new JPWC_API())->assets();wp_send_json_success(['message'=>'Authenticated merchant asset lookup succeeded.','count'=>count($assets)]);}
        catch(Throwable $e){wp_send_json_error(['message'=>'Connection failed; check API key, egress and merchant permissions.'],503);}
    }
    public static function notices() {
        if (!current_user_can('manage_woocommerce') || !self::isSettingsScreen()) { return; }
        $settings=get_option('woocommerce_jangopay_woocommerce_settings',[]);
        if (($settings['enabled']??'no')==='yes' && !self::ready()) {
            echo '<div class="notice notice-warning"><p>'.esc_html__('JangoPay: collection is blocked until the API key, external RSA key, verified webhook framing and explicit collection permission are configured. No payable invoice can be created.','jangopay-for-woocommerce').'</p></div>';
        }
        echo '<div class="notice notice-info"><p>JangoPay callback URL: <code>'.esc_html(self::webhookUrl()).'</code><br>Connection test: <button type="button" class="button" id="jpwc-test">Test read-only API connection</button> <span id="jpwc-test-output" role="status"></span></p></div>';
        self::enqueueAdminScript();
    }
}
