<?php
if (!defined('ABSPATH')) {exit;}
final class JPWC_Proof {
    public static function assets($raw) {
        $out=[];
        foreach (preg_split('/[,;\s]+/',trim((string)$raw)) as $part) {
            if ($part==='')continue;
            if (!preg_match('/^(USDT|USDC):([a-z0-9_-]{2,36})$/i',$part,$m))throw new DomainException('bad_asset_configuration');
            $out[]=strtoupper($m[1]).':'.strtolower($m[2]);
        }
        return array_values(array_unique($out));
    }
    public static function remoteAssets($rows,$wanted) {
        $found=[];
        foreach($rows as $r) {
            $asset=strtoupper((string)JPWC_API::field($r,'code','code'));
            $chain=strtolower((string)JPWC_API::field($r,'chain','chain'));
            $ok=JPWC_API::field($r,'invoice_payment_enabled','invoicePaymentEnabled');
            $id=$asset.':'.$chain;
            if ($ok===true && in_array($id,$wanted,true)) {$found[]=$id;}
        }
        return array_values(array_unique($found));
    }
    /**
     * Preserve the exact code:chain pairs reserved for this order. The JangoPay
     * invoice API treats an empty allowed_currency array as unrestricted, so a
     * missing, malformed or non-canonical snapshot must fail closed.
     *
     * @return list<string>
     */
    public static function invoiceAllowedAssets($snapshot) {
        $pairs=json_decode((string)$snapshot,true);
        if (!is_array($pairs) || array_values($pairs)!==$pairs || $pairs===[]) {
            throw new DomainException('invalid_invoice_asset_snapshot');
        }
        foreach ($pairs as $pair) {
            if (!is_string($pair)) throw new DomainException('invalid_invoice_asset_snapshot');
        }
        try { $normalized=self::assets(implode(',', $pairs)); }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception code/cause only; never rendered. User-facing errors are fixed, escaped messages.
        catch(Throwable $e) { throw new DomainException('invalid_invoice_asset_snapshot',0,$e); }
        if ($normalized!==$pairs) throw new DomainException('invalid_invoice_asset_snapshot');
        return $pairs;
    }
    /** Only positive, authenticated merchant proof may advance an order; no implicit status aliases. */
    public static function check($invoice,$txn,$row,$order) {
        $f=['invoice_id','order_id','amount','currency_code','status','payed_amount'];
        $pairs=['invoice_id'=>'invoiceId','order_id'=>'orderId','amount'=>'amount','currency_code'=>'currencyCode','status'=>'status','payed_amount'=>'payedAmount'];
        foreach($f as $k) {if(!is_scalar(JPWC_API::field($invoice,$k,$pairs[$k])))return 'invoice_fields_missing';}
        if((string)JPWC_API::field($invoice,'invoice_id','invoiceId')!==$row->invoice_id ||
           (string)JPWC_API::field($invoice,'order_id','orderId')!==$row->merchant_order_id ||
           JPWC_API::field($invoice,'currency_code','currencyCode')!=='USD')return 'invoice_identity_mismatch';
        try {$rawAmount=JPWC_API::field($invoice,'amount','amount');if(!is_string($rawAmount)&&!is_int($rawAmount))return 'invoice_amount_invalid';
            if(JPWC_Money::usd($rawAmount)!==(int)$row->amount_cents) return 'invoice_amount_mismatch';}
        catch(Throwable $e){return 'invoice_amount_invalid';}
        if(strtolower(trim((string)JPWC_API::field($invoice,'status','status')))!=='paid')return 'invoice_not_paid';
        if ((string)JPWC_API::field($txn,'txn_id','txnId')==='' ||
            (string)JPWC_API::field($txn,'invoice_id','invoiceId')!==$row->invoice_id ||
            strtolower((string)JPWC_API::field($txn,'status','status'))!=='settled')return 'transaction_not_settled_or_unbound';
        $coin=strtoupper((string)JPWC_API::field($txn,'pay_currency','payCurrency'));
        $chain=strtolower((string)JPWC_API::field($txn,'chain','chain'));
        $allowed=json_decode((string)$row->allowed_assets,true);
        if(!is_array($allowed)||!in_array($coin.':'.$chain,$allowed,true)||
           ($row->asset!==null && $row->asset!==$coin)||($row->chain!==null&&$row->chain!==$chain)||
           ($row->txn_id!==null&&$row->txn_id!==(string)JPWC_API::field($txn,'txn_id','txnId')))return 'transaction_asset_or_identity_mismatch';
        if(!$order||$order->get_payment_method()!=='jangopay_woocommerce'||$order->is_paid()||
           !in_array($order->get_status(),['pending','on-hold'],true)||
           strtoupper($order->get_currency())!=='USD')return 'order_ineligible';
        try {if(JPWC_Money::usd((string)$order->get_total())!==(int)$row->amount_cents)return 'order_amount_changed';}
        catch(Throwable $e){return 'order_amount_invalid';}
        // Exact USD settlement arithmetic uses 8-decimal integer units, independently
        // from WooCommerce's 2-decimal customer-facing order total. This field mapping
        // follows the verified Azuriom 1.0.4 live-compat reference (no guessed aliases).
        try {
            $rawPaid=JPWC_API::field($invoice,'payed_amount','payedAmount');
            if(!is_string($rawPaid)&&!is_int($rawPaid))return 'paid_amount_invalid';
            $paid=JPWC_Money::usdLedger($rawPaid);
            $expected=JPWC_Money::centsToLedger((int)$row->amount_cents);
            if($paid===$expected)return 'verified';
            if(JPWC_API::field($invoice,'fee_pay_by_user','feePayByUser')!==false ||
               JPWC_API::field($txn,'fee_pay_by_user','feePayByUser')!==false ||
               JPWC_API::field($txn,'currency_code','currencyCode')!=='USD')return 'fee_contract_unverified';
            $gross=JPWC_API::field($txn,'amount','amount');
            $fee=JPWC_API::field($txn,'fee_amount','feeAmount');
            $net=JPWC_API::field($txn,'net_amount','netAmount');
            if(!is_string($gross)||!is_string($fee)||!is_string($net))return 'fee_contract_unverified';
            $g=JPWC_Money::usdLedger($gross);$f=JPWC_Money::usdLedger($fee);$n=JPWC_Money::usdLedger($net);
            if($n<=0 || $g!==$expected || $n!==$paid || $f>$g || $g-$f!==$n)return 'fee_math_mismatch';
            return 'verified';
        }catch(Throwable $e){return 'paid_amount_invalid';}
    }
}
