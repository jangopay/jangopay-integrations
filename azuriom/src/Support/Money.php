<?php

namespace Azuriom\Plugin\JangoPay\Support;

use InvalidArgumentException;

final class Money
{
    /** Decimal-string comparison only; never compare floating-point payment amounts. */
    public static function usdCents(string $value): int
    {
        // Fixed-scale API decimals may pad cents with zeros; never round sub-cent values.
        $value = preg_replace('/(\.[0-9]{2})0+$/D', '$1', $value);
        if (! preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/D', $value, $m)) {
            throw new InvalidArgumentException('USD amount must have at most two decimal places.');
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($cents <= 0) {
            throw new InvalidArgumentException('USD amount must be positive.');
        }
        return $cents;
    }

    public static function fromShop(float $value): string
    {
        if (! is_finite($value) || $value <= 0) {
            throw new InvalidArgumentException('Invalid Shop amount.');
        }
        // Shop currently uses float at its input boundary: round exactly once.
        $decimal = number_format($value, 2, '.', '');
        self::usdCents($decimal);
        return $decimal;
    }

    /** Exact 8-decimal USD ledger units, including zero-valued fees. */
    public static function usdLedgerUnits(string $value): int
    {
        $value = preg_replace('/(\.[0-9]{8})0+$/D', '$1', $value);
        if (! preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,8}))?$/D', $value, $m)
            || (int) $m[1] > intdiv(PHP_INT_MAX - 99999999, 100000000)) {
            throw new InvalidArgumentException('Invalid USD ledger amount or integer overflow.');
        }
        return (int) $m[1] * 100000000 + (int) str_pad($m[2] ?? '', 8, '0');
    }

    public static function exactUsd(string $a, string $b): bool
    {
        return self::usdCents($a) === self::usdCents($b);
    }
}
