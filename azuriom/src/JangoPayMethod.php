<?php

namespace Azuriom\Plugin\JangoPay;

use Azuriom\Plugin\JangoPay\Models\Attempt;
use Azuriom\Plugin\JangoPay\Services\JangoPayApi;
use Azuriom\Plugin\JangoPay\Services\Reconciler;
use Azuriom\Plugin\JangoPay\Services\WebhookEnvelope;
use Azuriom\Plugin\JangoPay\Support\Assets;
use Azuriom\Plugin\JangoPay\Support\CheckoutFingerprint;
use Azuriom\Plugin\JangoPay\Support\CheckoutAccess;
use Azuriom\Plugin\JangoPay\Support\Money;
use Azuriom\Plugin\Shop\Cart\Cart;
use Azuriom\Plugin\Shop\Models\Payment;
use Azuriom\Plugin\Shop\Payment\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class JangoPayMethod extends PaymentMethod
{
    protected $id = 'jangopay';
    protected $name = 'JangoPay';

    public function supportsSubscriptions(): bool { return false; }
    public function view(): string { return 'jangopay::admin.config'; }
    public function rules(): array
    {
        return ['allowed_assets' => ['required', 'string', 'max:500', 'regex:/^(USDT|USDC):[a-z0-9_-]{2,32}(,(USDT|USDC):[a-z0-9_-]{2,32})*$/D']];
    }
    public function image(): string { return asset('assets/plugins/jangopay/img/name.svg'); }

    public function startPayment(Cart $cart, float $amount, string $currency)
    {
        if ($currency !== 'USD' || currency() !== 'USD') {
            return $this->reject(trans('jangopay::messages.usd_only'));
        }
        // Cookie/array sessions cannot safely coordinate concurrent checkout requests.
        // Locking is attached to the Shop checkout routes before StartSession runs.
        $lockStore = config('session.block_store') ?: config('cache.default');
        if (in_array(config('session.driver'), ['cookie', 'array'], true)
            || in_array($lockStore, ['array', 'null'], true)) {
            return $this->reject(trans('jangopay::messages.not_ready'));
        }
        $api = app(JangoPayApi::class);
        if (! $api->isConfigured() || ! $api->isCollectionReady() || $cart->isEmpty() || $this->gateway === null || ! $this->gateway->is_enabled) {
            return $this->reject(trans('jangopay::messages.not_ready'));
        }
        try {
            $assets = Assets::parse((string) ($this->gateway->data['allowed_assets'] ?? ''));
            $amountUsd = Money::fromShop($amount);
        } catch (Throwable $e) {
            return $this->reject(trans('jangopay::messages.not_ready'));
        }
        // Concurrent requests reading the same initial session MUST derive the same checkout key.
        // Do not generate a fresh random nonce here: both requests could read an empty session.
        // Rotation happens only once a checkout has been accepted/its original cart cleared.
        $session = request()->session();
        $fingerprint = CheckoutFingerprint::make($cart->toArray(), $amountUsd, $currency);
        $savedGeneration = $session->get('jangopay_checkout_nonce');
        $generation = is_string($savedGeneration) && preg_match('/^[a-f0-9]{32}$/D', $savedGeneration)
            ? $savedGeneration : str_repeat('0', 32);
        $checkoutKey = hash('sha256', $session->getId().'|'.$generation.'|'.$fingerprint.'|'.$this->gateway->id.'|'.(string) (shop_user()?->id ?? ''));
        // Durable, server-secret-derived capability: no authorization token is kept solely
        // in the session snapshot. A late response from another route cannot destroy it.
        // An old 1.0.2 attempt may still use the original random session token.
        $token = CheckoutAccess::derive($checkoutKey);
        $attempt = Attempt::where('checkout_key', $checkoutKey)->first();
        if ($attempt !== null && ! hash_equals((string) $attempt->access_hash, hash('sha256', $token))) {
            $legacy = $session->get('jangopay_access_'.$checkoutKey);
            if (! is_string($legacy) || strlen($legacy) !== 64 || ! ctype_xdigit($legacy)
                || ! hash_equals((string) $attempt->access_hash, hash('sha256', $legacy))) {
                // No guessing or replacing access hashes for historic attempts.
                return $this->reject(trans('jangopay::messages.processing'));
            }
            $token = $legacy;
        }
        if ($attempt === null) {
            try {
                // Roll back ALL Shop payment/item/giftcard mutations if a concurrent
                // request wins the unique checkout_key reservation first.
                $attempt = DB::transaction(function () use ($cart, $amount, $currency, $checkoutKey, $token, $amountUsd, $assets) {
                    $payment = $this->createPayment($cart, $amount, $currency);
                    $orderId = 'AZ-'.substr(hash('sha256', config('app.url')), 0, 10).'-'.$payment->id.'-'.Str::lower(Str::random(10));
                    return Attempt::create([
                        'checkout_key' => $checkoutKey, 'payment_id' => $payment->id,
                        'gateway_id' => $this->gateway->id, 'order_id' => $orderId,
                        'access_hash' => hash('sha256', $token), 'amount_usd' => $amountUsd,
                        'allowed_assets' => implode(',', $assets),
                        'state' => 'reserved', 'expires_at' => now()->addDay(),
                    ]);
                });
                // The capability is reproducible from the server APP_KEY; no session write.
            } catch (Throwable $e) {
                // Another worker claimed the same key, or a local reservation failed.
                // No second remote invoice is created, and Shop DB writes roll back.
                Log::warning('[JangoPay] Local checkout reservation conflict (redacted)');
                // If another request committed the same checkout first, reuse it.
                // Never issue another invoice, and never mint a new access hash.
                $attempt = Attempt::where('checkout_key', $checkoutKey)->first();
                if ($attempt === null || ! hash_equals((string) $attempt->access_hash, hash('sha256', $token))) {
                    return $this->reject(trans('jangopay::messages.processing'));
                }
            }
        }
        if ($attempt->state === 'reserved') {
            // Atomic claim; a failed remote request must NOT be retried blindly.
            if (Attempt::whereKey($attempt->id)->where('state', 'reserved')->update(['state' => 'creating']) === 1) {
                try {
                    $invoice = $api->createInvoice([
                        'currencyCode' => 'USD', 'amount' => $attempt->amount_usd,
                        'orderId' => $attempt->order_id, 'description' => 'Azuriom Shop order #'.$attempt->payment_id,
                        'allowedCurrency' => $assets, 'coverPct' => 100,
                        'mixPay' => false, 'autoToU' => false, 'autoWithdraw' => false,
                        'expiresAt' => $attempt->expires_at->toIso8601String(),
                        'extraInfo' => ['payment_id' => (string) $attempt->payment_id],
                    ]);
                    $invoiceId = JangoPayApi::field($invoice, 'invoice_id', 'invoiceId');
                    if (! is_string($invoiceId) || $invoiceId === '') {
                        throw new \RuntimeException('Invoice ID not returned');
                    }
                    Attempt::whereKey($attempt->id)->where('state', 'creating')
                        ->whereNull('invoice_id')->update(['invoice_id' => $invoiceId, 'state' => 'awaiting_payment']);
                } catch (Throwable $e) {
                    // Including definitive HTTP rejection for now; must reconcile before another attempt.
                    Attempt::whereKey($attempt->id)->where('state', 'creating')
                        ->update(['state' => 'unknown_creation', 'failure_code' => 'create_unconfirmed']);
                    Log::warning('[JangoPay] Invoice creation not confirmed', ['attempt' => $attempt->id]);
                }
            }
        }
        if (in_array($attempt->fresh()->state, ['awaiting_payment', 'quoted', 'verified', 'delivery_started', 'delivered'], true)) {
            // Shop's generic success route is not used with a locally hosted instruction page.
            // Cart contents have been snapshotted into the pending Shop payment already.
            // Clear the original cart so a new, identical future purchase creates a new checkout.
            $cart->destroy();
            // New identical purchases need a new generation. In-flight requests that
            // already read the old generation still collide on its checkout_key.
            $session->put('jangopay_checkout_nonce', bin2hex(random_bytes(16)));
        }
        return redirect()->route('jangopay.payment.show', ['attempt' => $attempt->id, 'token' => $token]);
    }

    public function notification(Request $request, ?string $paymentId)
    {
        try {
            $event = app(WebhookEnvelope::class)->decrypt($request);
            if (($event['event_type'] ?? null) !== 'payment.completed') {
                return response()->json(['status' => 'ignored']);
            }
            $invoiceId = (string) ($event['invoice_id'] ?? '');
            $txnId = (string) ($event['txn_id'] ?? '');
            $orderId = (string) ($event['order_id'] ?? '');
            if ($invoiceId === '' || $txnId === '' || $orderId === '') {
                return response()->json(['status' => 'invalid_event'], 422);
            }
            $attempt = Attempt::where('invoice_id', $invoiceId)->where('order_id', $orderId)
                ->where('gateway_id', $this->gateway?->id)->first();
            if ($attempt === null) return response()->json(['status' => 'unknown_invoice'], 422);
            // Every delivery path performs authenticated merchant-side reconciliation.
            $state = $this->reconcile($attempt, $txnId);
            return response()->json(['status' => $state], in_array($state, ['pending', 'unknown_creation', 'quote_unknown'], true) ? 503 : 200);
        } catch (Throwable $e) {
            Log::warning('[JangoPay] Webhook rejected (content redacted)', ['reason' => get_class($e)]);
            return response()->json(['status' => 'verification_failed'], 503);
        }
    }

    /** Attempt to recover an indeterminate invoice creation; never create a second invoice. */
    public function recoverUnknownCreation(Attempt $attempt): string
    {
        if ($attempt->state !== 'unknown_creation' || $attempt->invoice_id !== null) {
            return $attempt->state;
        }
        $candidate = app(JangoPayApi::class)->findInvoiceByOrder($attempt->order_id);
        if ($candidate === null) return 'unknown_creation';
        $id = JangoPayApi::field($candidate, 'invoice_id', 'invoiceId');
        $amount = JangoPayApi::field($candidate, 'amount', 'amount');
        $currency = JangoPayApi::field($candidate, 'currency_code', 'currencyCode');
        $orderId = JangoPayApi::field($candidate, 'order_id', 'orderId');
        if (! is_string($id) || $id === '' || ! is_string($amount) || ! is_string($orderId)
            || ! hash_equals((string) $attempt->order_id, $orderId)
            || ! Money::exactUsd($attempt->amount_usd, $amount) || $currency !== 'USD') {
            $won = Attempt::whereKey($attempt->id)->where('state', 'unknown_creation')
                ->whereNull('invoice_id')->update(['state' => 'manual_review', 'failure_code' => 'recovered_invoice_mismatch']);
            return $won === 1 ? 'manual_review' : $attempt->fresh()->state;
        }
        $won = Attempt::whereKey($attempt->id)->where('state', 'unknown_creation')
            ->whereNull('invoice_id')->update(['invoice_id' => $id, 'state' => 'awaiting_payment', 'failure_code' => null]);
        return $won === 1 ? 'awaiting_payment' : $attempt->fresh()->state;
    }

    public function reconcile(Attempt $attempt, ?string $txnId = null): string
    {
        $state = app(Reconciler::class)->verify($attempt->fresh(), $txnId);
        if ($state !== 'verified') return $state;
        // Commit a durable exactly-once DELIVERY CLAIM *before* the Shop side effects.
        if (Attempt::whereKey($attempt->id)->where('state', 'verified')
            ->update(['state' => 'delivery_started', 'delivery_started_at' => now()]) !== 1) {
            return $attempt->fresh()->state;
        }
        $attempt = $attempt->fresh();
        $payment = Payment::find($attempt->payment_id);
        if ($payment === null || ! $payment->isPending()) {
            $attempt->update(['state' => 'delivery_attention', 'failure_code' => 'shop_record_not_pending']);
            return 'delivery_attention';
        }
        try {
            $response = $this->processPayment($payment, $attempt->txn_id);
            if (! $payment->fresh()->isCompleted() || $response->getStatusCode() >= 400) {
                throw new \RuntimeException('Shop did not complete the payment.');
            }
            $attempt->update(['state' => 'delivered', 'delivered_at' => now()]);
            return 'delivered';
        } catch (Throwable $e) {
            // Shop marks completed before external commands. NEVER invoke delivery a second time blindly.
            $attempt->update(['state' => 'delivery_attention', 'failure_code' => 'shop_delivery_uncertain']);
            Log::error('[JangoPay] Shop delivery requires manual audit', ['attempt' => $attempt->id]);
            return 'delivery_attention';
        }
    }

    private function reject(string $message)
    {
        return to_route('shop.cart.index')->with('error', $message);
    }
}
