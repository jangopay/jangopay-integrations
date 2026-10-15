<?php

namespace Azuriom\Plugin\JangoPay\Support;

final class CheckoutFingerprint
{
    /** Include every cart item ID, option/variable, coupon and giftcard to avoid order aliasing. */
    public static function make(array $cartSnapshot, string $amountUsd, string $currency): string
    {
        return hash('sha256', json_encode([$cartSnapshot, $amountUsd, $currency], JSON_THROW_ON_ERROR));
    }
}
