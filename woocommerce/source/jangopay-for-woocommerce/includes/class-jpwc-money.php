<?php
if (!defined('ABSPATH')) { exit; }
final class JPWC_Money {
    /** Strict fixed-point decimal parse. Trailing zeroes are permitted, hidden fractional cents are not. */
    public static function units($input, $places=2, $positive=false) {
        if (!is_string($input) && !is_int($input)) { throw new DomainException('invalid_decimal'); }
        $value=(string)$input;
        if (!preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,18}))?$/D', $value, $m)) { throw new DomainException('invalid_decimal'); }
        $fraction=$m[2]??'';
        if (strlen($fraction)>$places && trim(substr($fraction,$places),'0')!=='') { throw new DomainException('fractional_smallest_unit'); }
        $whole=(int)$m[1];
        $scale=10**$places;
        $digits=(int)str_pad(substr($fraction,0,$places),$places,'0');
        // Test the final integer, not just its whole-number part.
        if ($whole > intdiv(PHP_INT_MAX-$digits,$scale)) { throw new DomainException('overflow'); }
        $result=$whole*$scale+$digits;
        if ($positive && $result<=0) { throw new DomainException('nonpositive'); }
        return $result;
    }
    public static function usd($input, $positive=true) { return self::units($input,2,$positive); }
    /** Platform settlement amounts can have 8 decimal USD places; WooCommerce prices use cents. */
    public static function usdLedger($input, $positive=false) { return self::units($input,8,$positive); }
    public static function centsToLedger($cents) {
        if(!is_int($cents) || $cents < 0 || $cents > intdiv(PHP_INT_MAX,1000000))throw new DomainException('overflow');
        return $cents*1000000;
    }
    public static function formatUsd($cents) { return sprintf('%d.%02d',intdiv($cents,100),$cents%100); }
    public static function subtract($gross,$fee,$net) { $g=self::usdLedger($gross,true);$f=self::usdLedger($fee);$n=self::usdLedger($net);return $g>=$f && ($g-$f)===$n; }
}
