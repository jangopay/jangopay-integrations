<?php

namespace Azuriom\Plugin\JangoPay\Controllers;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Plugin\JangoPay\Models\Attempt;
use Azuriom\Plugin\JangoPay\Services\JangoPayApi;
use Azuriom\Plugin\JangoPay\Support\Assets;
use Azuriom\Plugin\Shop\Models\Gateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckoutController extends Controller
{
    private function authorized(Request $request, Attempt $attempt, string $token): void
    {
        abort_unless(strlen($token) === 64 && ctype_xdigit($token)
            && hash_equals((string) $attempt->access_hash, hash('sha256', $token)), 403);
        $payment = \Azuriom\Plugin\Shop\Models\Payment::find($attempt->payment_id);
        abort_if($payment === null, 404);
        if ($payment->user_id !== null) {
            // Shop supports authenticated site users and guest checkout identities
            // via the current shop session. A random access token alone is not enough.
            if (auth()->check()) {
                abort_unless(auth()->id() === (int) $payment->user_id, 403);
            } else {
                abort_unless(! use_site_money() && setting('shop.guest_purchases', false)
                    && (int) $request->session()->get('shop.user') === (int) $payment->user_id
                    && $request->session()->get('shop.user') !== null, 403);
            }
        }
    }

    public function show(Request $request, Attempt $attempt, string $token)
    {
        $this->authorized($request, $attempt, $token);
        $gateway = Gateway::findOrFail($attempt->gateway_id);
        $assets = Assets::parse((string) (($attempt->allowed_assets ?? null) ?: ($gateway->data['allowed_assets'] ?? '')));
        return view('jangopay::checkout', compact('attempt', 'token', 'assets'));
    }

    public function choose(Request $request, Attempt $attempt, string $token, JangoPayApi $api)
    {
        $this->authorized($request, $attempt, $token);
        $gateway = Gateway::findOrFail($attempt->gateway_id);
        // Collection can be disabled while an old invoice is still outstanding.
        // Do not issue fresh on-chain instructions without the same launch interlock.
        abort_unless($api->isCollectionReady() && $gateway->is_enabled && $attempt->invoice_id !== null, 409);
        $chosen = $request->validate(['asset' => ['required', 'string', 'max:80']])['asset'];
        $assets = Assets::parse((string) (($attempt->allowed_assets ?? null) ?: ($gateway->data['allowed_assets'] ?? '')));
        abort_unless(in_array($chosen, $assets, true), 422);
        abort_unless($attempt->state === 'awaiting_payment', 409);
        abort_if($attempt->expires_at !== null && now()->greaterThan($attempt->expires_at), 409);

        // A durable atomic claim prevents two conflicting concurrent coin confirmations.
        $won = Attempt::whereKey($attempt->id)->where('state', 'awaiting_payment')
            ->update(['state' => 'quoting', 'asset' => explode(':', $chosen)[0], 'chain' => explode(':', $chosen)[1]]);
        abort_unless($won === 1, 409);
        [$currency, $chain] = explode(':', $chosen);
        try {
            $quote = $api->confirmCoin($attempt->invoice_id, $currency, $chain);
            $txnId = (string) JangoPayApi::field($quote, 'txn_id', 'txnId');
            $amount = (string) JangoPayApi::field($quote, 'pay_amount', 'payAmount');
            $address = (string) JangoPayApi::field($quote, 'receive_address', 'receiveAddress');
            $returnedCurrency = strtoupper((string) JangoPayApi::field($quote, 'pay_currency', 'payCurrency'));
            $returnedChain = strtolower((string) ($quote['chain'] ?? ''));
            if ($txnId === '' || $address === '' || ! preg_match('/^\d+(\.\d+)?$/D', $amount)
                || (float) $amount <= 0 || $returnedCurrency !== $currency || $returnedChain !== $chain) {
                throw new \RuntimeException('Incomplete or mismatched quote.');
            }
            // An old quote response must not downgrade a verified/delivered order.
            Attempt::whereKey($attempt->id)->where('state', 'quoting')->update([
                'state' => 'quoted', 'quote_amount' => $amount,
                'receive_address' => $address, 'txn_id' => $txnId, 'quoted_at' => now(),
            ]);
        } catch (Throwable $e) {
            // A network timeout may have created instructions; do not auto-retry.
            Attempt::whereKey($attempt->id)->where('state', 'quoting')
                ->update(['state' => 'quote_unknown', 'failure_code' => 'coin_quote_unconfirmed']);
            Log::warning('[JangoPay] Coin instruction result is unknown', ['attempt' => $attempt->id]);
        }
        return to_route('jangopay.payment.show', ['attempt' => $attempt->id, 'token' => $token]);
    }

    public function status(Request $request, Attempt $attempt, string $token)
    {
        $this->authorized($request, $attempt, $token);
        // Never call the upstream API from rapid browser polling.
        return response()->json(['state' => $attempt->fresh()->state, 'paid' => $attempt->fresh()->state === 'delivered']);
    }
}
