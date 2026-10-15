<?php

namespace Azuriom\Plugin\JangoPay\Services;

use Azuriom\Plugin\JangoPay\Models\Attempt;
use Azuriom\Plugin\JangoPay\Support\Assets;
use Azuriom\Plugin\JangoPay\Support\Money;
use Azuriom\Plugin\Shop\Models\Payment;
use Illuminate\Support\Facades\Log;

class Reconciler
{
    public function __construct(private JangoPayApi $api) {}

    /**
     * Fail-closed reconciliation. 'verified' alone does not deliver; caller must atomically claim it.
     * Never decide success using a browser return, a webhook status, or public txn endpoint.
     */
    public function verify(Attempt $attempt, ?string $hintTxnId = null): string
    {
        // Terminal/claimed payments must never be re-verified back into a deliverable state.
        // A 'verified' attempt may still need its ONE delivery claim; do not re-write it.
        if ($attempt->state === 'verified') return 'verified';
        if (! in_array($attempt->state, ['awaiting_payment', 'quoted', 'quote_unknown'], true)) {
            return $attempt->state;
        }
        if ($attempt->invoice_id === null) return 'unknown_creation';

        $invoice = $this->api->invoice($attempt->invoice_id);
        $invoiceId = JangoPayApi::field($invoice, 'invoice_id', 'invoiceId');
        $orderId = JangoPayApi::field($invoice, 'order_id', 'orderId');
        $amount = JangoPayApi::field($invoice, 'amount', 'amount');
        $currency = JangoPayApi::field($invoice, 'currency_code', 'currencyCode');
        $remoteStatus = strtolower(trim((string) JangoPayApi::field($invoice, 'status', 'status')));

        if (! is_string($invoiceId) || ! hash_equals((string) $attempt->invoice_id, $invoiceId)
            || ! is_string($orderId) || ! hash_equals((string) $attempt->order_id, $orderId)
            || $currency !== 'USD' || ! is_string($amount)
            || ! Money::exactUsd((string) $attempt->amount_usd, $amount)) {
            return $this->manualReview($attempt, 'invoice_identity_or_amount_mismatch');
        }
        Attempt::whereKey($attempt->id)->whereIn('state', ['awaiting_payment', 'quoted', 'quote_unknown'])
            ->update(['remote_status' => substr($remoteStatus, 0, 64)]);
        // Late-arriving funds after our local expiry always require manual audit;
        // old pending attempts must not occupy reconciliation slots forever.
        if ($attempt->expires_at !== null && now()->greaterThan($attempt->expires_at)) {
            return $this->manualReview($attempt, 'local_payment_expired_needs_audit');
        }

        // The public doc identifies the event, but does not establish the authoritative FINAL invoice status.
        // Merchant must confirm it with a redacted production response before configuring this allowlist.
        $allowed = array_filter(array_map('trim', explode(',', strtolower((string) getenv('JANGOPAY_FINAL_INVOICE_STATUSES')))));
        if ($allowed === [] || $remoteStatus === '' || ! in_array($remoteStatus, $allowed, true)) {
            // Lost confirm-coin responses may have a merchant-visible pending transaction.
            // Restore instructions only from a verified merchant read, never repeat POST.
            return $attempt->state === 'quote_unknown'
                ? $this->recoverPendingQuote($attempt, $hintTxnId) : 'pending';
        }
        // The final merchant transaction determines whether fees explain the credited net amount.
        $paid = JangoPayApi::field($invoice, 'payed_amount', 'payedAmount');
        if (! is_string($paid)) {
            return $this->manualReview($attempt, 'paid_amount_unconfirmed');
        }
        $payment = Payment::find($attempt->payment_id);
        if ($payment === null || $payment->gateway_type !== 'jangopay' || $payment->currency !== 'USD'
            || ! Money::exactUsd((string) $attempt->amount_usd, Money::fromShop($payment->price))) {
            return $this->manualReview($attempt, 'shop_payment_missing_or_changed');
        }
        $txnId = $hintTxnId ?: $attempt->txn_id;
        if (! is_string($txnId) || $txnId === '') {
            // Only a merchant-side, read-only lookup can recover a missing quote response.
            // The orderId is not an idempotency key; never repeat confirm-coin here.
            $candidate = $this->api->findTransactionForInvoice((string) $attempt->invoice_id);
            $txnId = $candidate === null ? null : JangoPayApi::field($candidate, 'txn_id', 'txnId');
        }
        if (! is_string($txnId) || $txnId === '') {
            return $attempt->state === 'quote_unknown' ? 'quote_unknown' : 'pending';
        }
        $txn = $this->api->invoiceTransaction($txnId);
        $remoteTxnId = JangoPayApi::field($txn, 'txn_id', 'txnId');
        $remoteInvoiceId = JangoPayApi::field($txn, 'invoice_id', 'invoiceId');
        $txnStatus = strtolower((string) JangoPayApi::field($txn, 'status', 'status'));
        $coin = strtoupper((string) JangoPayApi::field($txn, 'pay_currency', 'payCurrency'));
        $chain = strtolower((string) JangoPayApi::field($txn, 'chain', 'chain'));
        $configured = Assets::parse((string) (($attempt->allowed_assets ?? null) ?: $this->getGatewayAssets($attempt)));
        if (! is_string($remoteTxnId) || ! hash_equals($txnId, $remoteTxnId)
            || ! is_string($remoteInvoiceId) || ! hash_equals($attempt->invoice_id, $remoteInvoiceId)
            || $txnStatus !== 'settled' || ! in_array($coin.':'.$chain, $configured, true)
            || ($attempt->txn_id !== null && ! hash_equals((string) $attempt->txn_id, $txnId))
            || ($attempt->asset !== null && $attempt->asset !== $coin)
            || ($attempt->chain !== null && $attempt->chain !== $chain)) {
            return $this->manualReview($attempt, 'merchant_transaction_not_reconciled');
        }
        if (! $this->paidAmountConfirmed($attempt->amount_usd, $paid, $invoice, $txn)) {
            return $this->manualReview($attempt, 'paid_amount_unconfirmed');
        }
        // Compare-and-swap protects an in-flight delivery claim from being overwritten by
        // another webhook, an administrator click, or a background reconciliation.
        try {
            $updated = Attempt::whereKey($attempt->id)
                ->whereIn('state', ['awaiting_payment', 'quoted', 'quote_unknown'])
                ->update(['txn_id' => $txnId, 'state' => 'verified', 'verified_at' => now(), 'failure_code' => null]);
        } catch (\Throwable $e) {
            Log::warning('[JangoPay] Transaction duplicate or DB conflict', ['attempt' => $attempt->id]);
            return 'manual_review';
        }
        return $updated === 1 ? 'verified' : $attempt->fresh()->state;
    }

    /** Rebuild an incomplete, unpaid quote only from a merchant-authenticated lookup. */
    private function recoverPendingQuote(Attempt $attempt, ?string $hintTxnId): string
    {
        $id = $hintTxnId ?: $attempt->txn_id;
        if (! is_string($id) || $id === '') {
            $candidate = $this->api->findTransactionForInvoice((string) $attempt->invoice_id);
            $id = $candidate === null ? null : JangoPayApi::field($candidate, 'txn_id', 'txnId');
        }
        if (! is_string($id) || $id === '') return 'quote_unknown';
        $txn = $this->api->invoiceTransaction($id);
        $remoteId = JangoPayApi::field($txn, 'txn_id', 'txnId');
        $invoiceId = JangoPayApi::field($txn, 'invoice_id', 'invoiceId');
        $coin = strtoupper((string) JangoPayApi::field($txn, 'pay_currency', 'payCurrency'));
        $chain = strtolower((string) JangoPayApi::field($txn, 'chain', 'chain'));
        $amount = JangoPayApi::field($txn, 'pay_amount', 'payAmount');
        $address = JangoPayApi::field($txn, 'receive_address', 'receiveAddress');
        $status = strtolower((string) JangoPayApi::field($txn, 'status', 'status'));
        $assets = Assets::parse((string) (($attempt->allowed_assets ?? null) ?: $this->getGatewayAssets($attempt)));
        if ($status !== 'pending' || $remoteId !== $id || $invoiceId !== $attempt->invoice_id
            || $attempt->asset !== $coin || $attempt->chain !== $chain
            || ! in_array($coin.':'.$chain, $assets, true)
            || ! is_string($amount) || strlen($amount) > 80
            || ! preg_match('/^(?:0|[1-9][0-9]{0,20})(?:\.[0-9]{1,18})?$/D', $amount)
            || ! preg_match('/[1-9]/', $amount)
            || ! is_string($address) || $address === '' || strlen($address) > 512) {
            // Unknown/mismatched records must not be presented as reliable payer instructions.
            return 'quote_unknown';
        }
        $updated = Attempt::whereKey($attempt->id)->where('state', 'quote_unknown')
            ->whereNull('txn_id')->update([
                'state' => 'quoted', 'txn_id' => $id, 'quote_amount' => $amount,
                'receive_address' => $address, 'quoted_at' => now(), 'failure_code' => null,
            ]);
        return $updated === 1 ? 'quoted' : $attempt->fresh()->state;
    }

    private function paidAmountConfirmed(string $expected, string $paid, array $invoice, array $txn): bool
    {
        try {
            $gross = Money::usdLedgerUnits($expected);
            $credited = Money::usdLedgerUnits($paid);
            if ($credited === $gross) return true;
            if (JangoPayApi::field($invoice, 'fee_pay_by_user', 'feePayByUser') !== false
                || JangoPayApi::field($txn, 'fee_pay_by_user', 'feePayByUser') !== false
                || JangoPayApi::field($txn, 'currency_code', 'currencyCode') !== 'USD') return false;
            $amount = JangoPayApi::field($txn, 'amount', 'amount');
            $fee = JangoPayApi::field($txn, 'fee_amount', 'feeAmount');
            $net = JangoPayApi::field($txn, 'net_amount', 'netAmount');
            if (! is_string($amount) || ! is_string($fee) || ! is_string($net)) return false;
            $netUnits = Money::usdLedgerUnits($net);
            return $netUnits > 0 && Money::usdLedgerUnits($amount) === $gross
                && $credited === $netUnits && $netUnits + Money::usdLedgerUnits($fee) === $gross;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    private function manualReview(Attempt $attempt, string $code): string
    {
        $updated = Attempt::whereKey($attempt->id)
            ->whereIn('state', ['awaiting_payment', 'quoted', 'quote_unknown'])
            ->update(['state' => 'manual_review', 'failure_code' => $code]);
        return $updated === 1 ? 'manual_review' : $attempt->fresh()->state;
    }

    private function getGatewayAssets(Attempt $attempt): string
    {
        $gateway = \Azuriom\Plugin\Shop\Models\Gateway::find($attempt->gateway_id);
        return (string) ($gateway?->data['allowed_assets'] ?? '');
    }
}
