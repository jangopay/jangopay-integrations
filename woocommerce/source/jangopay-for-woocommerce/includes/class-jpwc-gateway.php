<?php
if(!defined('ABSPATH')){exit;}
final class JPWC_Gateway extends WC_Payment_Gateway {
    public function __construct() {
        $this->id='jangopay_woocommerce';
        $this->method_title='JangoPay';
        $this->icon=JPWC_URL.'assets/images/jangopay-icon.png';
        $this->method_description=__('USDT / USDC through JangoPay (USD orders only).','jangopay-for-woocommerce');
        $this->has_fields=false;
        $this->supports=['products'];
        $this->init_form_fields();$this->init_settings();
        $this->title=$this->get_option('title');
        $this->description=$this->get_option('description');
        $this->enabled=$this->get_option('enabled','no');
        add_action('woocommerce_update_options_payment_gateways_'.$this->id,[$this,'process_admin_options']);
    }
    public function init_form_fields() {
        $this->form_fields=[
            'enabled'=>['title'=>__('Enable JangoPay','jangopay-for-woocommerce'),'type'=>'checkbox','label'=>__('Accept stablecoin payments','jangopay-for-woocommerce'),'default'=>'no'],
            'title'=>['title'=>__('Display title','jangopay-for-woocommerce'),'type'=>'text','default'=>__('JangoPay (USDT / USDC)','jangopay-for-woocommerce')],
            'description'=>['title'=>__('Checkout description','jangopay-for-woocommerce'),'type'=>'textarea','default'=>__('Pay with USDT or USDC through JangoPay. You will be redirected to complete your payment.','jangopay-for-woocommerce')],
            'allowed_assets'=>['title'=>__('Enabled assets / networks','jangopay-for-woocommerce'),'type'=>'textarea','description'=>__('Comma-separated: USDT:tron, USDC:ethereum. Only assets enabled by the merchant account will be offered.','jangopay-for-woocommerce'),'default'=>'USDT:tron','desc_tip'=>false],
            'callback'=>['title'=>__('Webhook URL','jangopay-for-woocommerce'),'type'=>'title','description'=>esc_url(JPWC_Controller::webhookUrl())],
            'credentials'=>['title'=>__('Credential setup','jangopay-for-woocommerce'),'type'=>'title','description'=>esc_html__('Configure JANGOPAY_WC_API_KEY and JANGOPAY_WC_RSA_PRIVATE_KEY_PATH in wp-config.php. Full credentials are never stored or displayed in this panel.','jangopay-for-woocommerce')],
        ];
    }
    public function get_icon() {
        // Themes may otherwise expand the original brand image to the full checkout width.
        $icon='<img src="'.esc_url($this->icon).'" alt="" aria-hidden="true" width="28" height="28" style="width:28px;height:28px;object-fit:contain;vertical-align:middle">';
        return apply_filters('woocommerce_gateway_icon',$icon,$this->id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve WooCommerce's native gateway icon hook.
    }
    public function admin_options() {
        echo '<div class="jpwc-brand"><img src="'.esc_url(JPWC_URL.'assets/images/jangopay-wordmark.png').'" alt="JangoPay" width="220" style="max-width:100%;height:auto"><p>'.esc_html__('Hosted USDT / USDC payments for USD orders. Configure your merchant credentials before enabling payments.','jangopay-for-woocommerce').'</p><p>';
        echo '<a href="'.esc_url('https://doc.jangopay.org/documentation').'" target="_blank" rel="noopener noreferrer">'.esc_html__('Documentation','jangopay-for-woocommerce').'</a> &middot; ';
        echo '<a href="'.esc_url('https://www.jangopay.org/zh/about').'" target="_blank" rel="noopener noreferrer">'.esc_html__('Support','jangopay-for-woocommerce').'</a> &middot; ';
        echo '<a href="'.esc_url('https://www.jangopay.org/en/legal/terms').'" target="_blank" rel="noopener noreferrer">'.esc_html__('Terms of service','jangopay-for-woocommerce').'</a> &middot; ';
        echo '<a href="'.esc_url('https://www.jangopay.org/en/legal/privacy').'" target="_blank" rel="noopener noreferrer">'.esc_html__('Privacy policy','jangopay-for-woocommerce').'</a></p></div>';
        parent::admin_options();
    }
    public function is_available() {
        if (!parent::is_available() || !function_exists('get_woocommerce_currency') || strtoupper(get_woocommerce_currency())!=='USD')return false;
        try {return JPWC_Controller::ready() && count(JPWC_Proof::assets($this->get_option('allowed_assets','')))>0;}
        catch(Throwable $e){return false;}
    }
    public function process_payment($order_id) {
        try { $order=JPWC_Controller::freshOrder($order_id); } catch(Throwable $e) { $order=false; }
        if(!$order || $order->get_payment_method()!==$this->id || $order->is_paid() || !in_array($order->get_status(),['pending','on-hold'],true)) {
            wc_add_notice(__('Unable to start JangoPay payment.','jangopay-for-woocommerce'),'error');return ['result'=>'failure'];
        }
        try {
            // The order factory can select a third-party subclass. Refuse to
            // issue a payable invoice if our save guard cannot protect it.
            if(!JPWC_Order_Guard::supportsOrder($order))
                throw new RuntimeException('unsupported_order_class');
            if(JPWC_Ledger::get($order_id) && !JPWC_Order_Guard::isGuarded($order))
                throw new RuntimeException('order_not_guarded');
            if(!JPWC_Controller::ready())throw new RuntimeException('not_ready');
            if(strtoupper($order->get_currency())!=='USD')throw new RuntimeException('unsupported_currency');
            $amount=JPWC_Money::usd((string)$order->get_total());
            $row=JPWC_Ledger::get($order_id);
            if(!$row) {
                $wanted=JPWC_Proof::assets($this->get_option('allowed_assets',''));
                $available=JPWC_Proof::remoteAssets((new JPWC_API())->assets(),$wanted);
                if(!$available)throw new RuntimeException('account_has_no_enabled_assets');
                // Merchant asset lookup performs external I/O. Verify the order is still payable.
                $fresh=JPWC_Controller::freshOrder($order_id);
                if(!$fresh || $fresh->is_paid() || !in_array($fresh->get_status(),['pending','on-hold'],true) ||
                   $fresh->get_payment_method()!==$this->id || strtoupper($fresh->get_currency())!=='USD' ||
                   JPWC_Money::usd((string)$fresh->get_total())!==$amount)throw new RuntimeException('order_changed_during_asset_lookup');
                // Do NOT transition pending -> on-hold here. Two competing checkout
                // requests may both reach this point, and WooCommerce will run
                // stock/email hooks before the payment ledger can elect a winner.
                $row=JPWC_Ledger::reserve($order,$amount,$available);
                if(!$row)throw new RuntimeException('cannot_reserve');
            }
            if((int)$row->amount_cents!==$amount)throw new RuntimeException('order_total_changed');
            if(!JPWC_Order_Guard::isGuarded(JPWC_Controller::freshOrder($order_id)))
                throw new RuntimeException('order_not_guarded_after_reserve');
            // A crash after the local INSERT but before the creation claim leaves a
            // safe 'reserved' state. Another checkout can finish this one-time claim.
            // An unknown/inflight attempt must NEVER re-POST, even after a timeout.
            if($row->invoice_id===null && $row->state==='reserved') {
                $this->maybeCreateOnce($row,$order,$amount);
            }
            if(in_array(JPWC_Ledger::get($order_id)->state,['manual_review','completed','delivery_claimed','delivery_attention'],true))
                throw new RuntimeException('payment_not_available_for_checkout');
            // Redirect directly to JangoPay's official hosted checkout ONLY for
            // an already-persisted, payable invoice. Unknown/unclaimed attempts
            // stay on the local read-only recovery page; they are never re-POSTed.
            $row=JPWC_Ledger::get($order_id);
            $liveOrder=JPWC_Controller::freshOrder($order_id);
            if($liveOrder && $liveOrder->is_paid())
                return ['result'=>'success','redirect'=>$liveOrder->get_checkout_order_received_url()];
            $hosted=JPWC_Controller::hostedForOrder($liveOrder,$row);
            return ['result'=>'success','redirect'=>$hosted ?: JPWC_Controller::checkoutUrl($order)];
        }catch(Throwable $e) {
            wc_add_notice(__('JangoPay is temporarily unavailable. No payment has been confirmed.','jangopay-for-woocommerce'),'error');return ['result'=>'failure'];
        }
    }
    private function maybeCreateOnce($row,$order,$cents) {
        // Two checkout requests can both observe the reserved row. A one-time
        // atomic transition elects the ONLY invoice creator; uncertainty is permanent.
        if(!JPWC_Ledger::cas($row,['reserved'],['state'=>'creating_inflight']))return;
        // Fail closed BEFORE changing the Woo order to on-hold: an invalid
        // provider allowlist must not reserve stock or emit initial emails.
        try {
            $invoiceAssets=JPWC_Proof::invoiceAllowedAssets($row->allowed_assets);
        } catch(Throwable $e) {
            JPWC_Ledger::cas($row,['creating_inflight'],['state'=>'manual_review','failure_code'=>'invalid_asset_snapshot']);
            return;
        }
        // Only the winner of the ledger CAS may make the FIRST WooCommerce
        // pending -> on-hold transition. Woo's native stock and email hooks run
        // during update_status(); moving it outside the winning claim caused
        // duplicate side effects under real, independent PHP worker contention.
        // Hold/stock mutations are not safely retryable if a hook throws midway:
        // quarantine any uncertain transition for a human, with no invoice POST.
        try {
            $fresh=JPWC_Controller::freshOrder($order->get_id());
            JPWC_Items::assertRow($row,$fresh);
            if(!JPWC_Controller::orderPayable($fresh,$row)) {
                JPWC_Ledger::cas($row,['creating_inflight'],['state'=>'manual_review','failure_code'=>'order_changed_before_invoice']);
                return;
            }
            if($fresh->has_status('pending')) {
                $fresh->update_status('on-hold',__('Waiting for JangoPay payment verification.','jangopay-for-woocommerce'));
                $fresh=JPWC_Controller::freshOrder($order->get_id());
                if(!$fresh || !$fresh->has_status('on-hold') || !JPWC_Controller::orderPayable($fresh,$row))
                    throw new RuntimeException('woo_initial_hold_not_confirmed');
            }
        } catch(Throwable $e) {
            JPWC_Ledger::cas($row,['creating_inflight'],['state'=>'manual_review','failure_code'=>'woo_initial_hold_uncertain']);
            return;
        }
        try {
            $invoice=(new JPWC_API())->create([
                'orderId'=>$row->merchant_order_id,'amount'=>JPWC_Money::formatUsd($cents),
                'currencyCode'=>'USD','mixPay'=>false,
                'allowedCurrency'=>$invoiceAssets,
                // The hosted checkout uses this only for shopper navigation.
                // Fulfillment still requires merchant-authenticated verification.
                'returnUrl'=>JPWC_Controller::checkoutUrl($order),
                'extraInfo'=>['woocommerce_order_id'=>(string)$order->get_id()],
            ],static function()use($order,$row){
                // Token acquisition may take time. Recheck authoritative WC state
                // before invoking the non-repeatable provider create POST.
                $live=JPWC_Ledger::get($order->get_id());
                if(!$live || $live->state!=='creating_inflight' || $live->invoice_id!==null ||
                   !JPWC_Controller::orderPayable(JPWC_Controller::freshOrder($order->get_id()),$live) ||
                   !hash_equals((string)$live->items_hash, JPWC_Items::fingerprint(JPWC_Controller::freshOrder($order->get_id()))))
                    throw new RuntimeException('order_changed_before_invoice_post');
            });
            $id=JPWC_API::field($invoice,'invoice_id','invoiceId');
            if(!is_string($id)||$id==='')throw new RuntimeException('invoice_id_missing');
            JPWC_Ledger::cas($row,['creating_inflight'],['invoice_id'=>$id,'state'=>'awaiting_payment']);
        }catch(JPWC_CreatePreparationException $e) {
            // The API transport has NOT attempted invoice/create. Only in this
            // exact exception case may the unique attempt return to reserved.
            $live=JPWC_Ledger::get($order->get_id());
            if($live && $live->state==='creating_inflight') {
                try {
                    $payable=JPWC_Controller::orderPayable(JPWC_Controller::freshOrder($order->get_id()),$live);
                }catch(Throwable $refreshError){$payable=false;}
                JPWC_Ledger::cas($live,['creating_inflight'], $payable
                    ? ['state'=>'reserved','failure_code'=>'invoice_preparation_failed']
                    : ['state'=>'manual_review','failure_code'=>'order_changed_before_invoice']);
            }
        }catch(Throwable $e) {
            JPWC_Ledger::cas($row,['creating_inflight'],['state'=>'creating_unknown','failure_code'=>'invoice_create_uncertain']);
            // This includes transport errors after the POST was attempted.
            // The remote invoice may exist: NEVER retry creation blindly.
        }
    }
}
