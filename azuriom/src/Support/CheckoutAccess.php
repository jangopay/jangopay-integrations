<?php

namespace Azuriom\Plugin\JangoPay\Support;

use RuntimeException;

/** A per-checkout URL capability which survives stale session snapshot writes. */
final class CheckoutAccess
{
    public static function derive(string $checkoutKey): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $checkoutKey)) {
            throw new RuntimeException('Invalid checkout key.');
        }
        $raw = (string) config('app.key');
        $secret = str_starts_with($raw, 'base64:')
            ? base64_decode(substr($raw, 7), true) : $raw;
        if (! is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('A strong APP_KEY is required for checkout access.');
        }
        // Domain-separated HMAC; the DB holds only SHA-256(capability), never the key.
        return hash_hmac('sha256', 'jangopay:checkout-access:v1:'.$checkoutKey, $secret);
    }
}
