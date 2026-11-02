<?php
/**
 * 1.0.6 regression: payment ledger winner alone may trigger WC's initial
 * pending -> on-hold status change. Real WooCommerce hooks are not loaded;
 * this test models their stock/email side effects and re-enters process_payment
 * while the winning worker is performing that transition.
 */
require __DIR__.'/fixtures/review-bootstrap.php';

class InitialHoldOrder extends TestOrder {
    public $holds = 0;
    public $stocks = 0;
    public $emails = 0;
    public $invokeCompetitor = false;
    public $sawClaimBeforeHold = false;
    public $competitorResult = null;
    public $throwOnHold = false;
    public function update_status($s, $note='') {
        if ($s === 'on-hold') {
            $this->holds++;
            // A simultaneous second checkout re-enters at the worst time.
            if ($this->invokeCompetitor) {
                $this->invokeCompetitor = false;
                $row = JPWC_Ledger::get($this->id);
                $this->sawClaimBeforeHold = $row && $row->state === 'creating_inflight';
                $this->competitorResult = (new JPWC_Gateway())->process_payment($this->id);
            }
            if ($this->throwOnHold) throw new RuntimeException('simulated_woo_hook_failure');
            $this->stocks++;
            $this->emails += 2;  // representative new-order + on-hold emails.
        }
        $this->status = $s;
    }
}
$gateway = new JPWC_Gateway();
$order = new InitialHoldOrder();
$order->id=700;
$order->invokeCompetitor=true;
$GLOBALS['orders'][700]=$order;
$r=$gateway->process_payment(700);
verify('winner succeeds despite re-entrant competing checkout', ($r['result']??null)==='success');
verify('only winning claimant can enter WC initial hold', $order->sawClaimBeforeHold);
verify('competitor returns checkout without performing a hold', ($order->competitorResult['result']??null)==='success');
verify('Woo initial state transition occurs exactly once', $order->holds===1 && $order->status==='on-hold');
verify('representative stock and mail side effects occur once', $order->stocks===1 && $order->emails===2);
verify('remote create POST happens exactly once', ($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===1);
verify('the unique ledger claim advances to awaiting_payment', JPWC_Ledger::get(700)->state==='awaiting_payment');
$gateway->process_payment(700);
verify('sequential repeat checkout cannot trigger more stock/email', $order->holds===1 && $order->stocks===1 && $order->emails===2);
verify('sequential repeat cannot create a second invoice', ($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===1);

// No external create POST is attempted when Woo hooks throw while holding the order.
$fail=new InitialHoldOrder();$fail->id=701;$fail->throwOnHold=true;
$GLOBALS['orders'][701]=$fail;
$before=($GLOBALS['httpCalls']['/api/v1/invoice/create']??0);
$r=$gateway->process_payment(701);
verify('uncertain initial hold fails closed', JPWC_Ledger::get(701)->state==='manual_review');
verify('uncertain initial hold never attempts remote invoice create', ($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===$before);
$gateway->process_payment(701);
verify('manual_review never retries a failed initial hold', $fail->holds===1 && ($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===$before);

// A known pre-POST token failure can return to reserved, but it cannot
// repeat the Woo initial hold that succeeded earlier in this order.
$retry=new InitialHoldOrder();$retry->id=702;$GLOBALS['orders'][702]=$retry;
$GLOBALS['reviewTransport']=static function($path,$args) {
    if ($path==='/api/v1/auth/apikey/token' && ($GLOBALS['httpCalls'][$path]??0)===4)
        throw new RuntimeException('simulated_token_preparation_failure');
    return null;
};
// Note: earlier calls to asset and create account for two auth requests,
// therefore inject a deterministic failure for THIS invoice preflight by
// inspecting the number of token calls recorded at entry.
$seenAuth=($GLOBALS['httpCalls']['/api/v1/auth/apikey/token']??0);
$GLOBALS['reviewTransport']=static function($path,$args) use($seenAuth) {
    if($path==='/api/v1/auth/apikey/token' && ($GLOBALS['httpCalls'][$path]??0)===$seenAuth+2)
        throw new RuntimeException('simulated_token_preparation_failure');
    return null;
};
$r=$gateway->process_payment(702);
verify('pre-POST token failure remains safely reserved', JPWC_Ledger::get(702)->state==='reserved');
verify('first call holds order exactly once', $retry->holds===1);
$GLOBALS['reviewTransport']=null;
$r=$gateway->process_payment(702);
verify('auth recovery reaches checkout and remote invoice', ($r['result']??null)==='success' && JPWC_Ledger::get(702)->state==='awaiting_payment');
verify('auth recovery does not transition to on-hold again', $retry->holds===1 && $retry->stocks===1 && $retry->emails===2);
verify('all three scenarios together attempt remote create exactly twice', ($GLOBALS['httpCalls']['/api/v1/invoice/create']??0)===2);

echo "NOTE: Re-entrant fake Woo/SQL, not real WordPress/MariaDB multiprocessing.\n";
