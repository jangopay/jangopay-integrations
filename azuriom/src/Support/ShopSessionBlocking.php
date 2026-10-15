<?php

namespace Azuriom\Plugin\JangoPay\Support;

use Illuminate\Routing\Route;

/** Applies Laravel's existing session-write lock to upstream Shop entrypoints. */
final class ShopSessionBlocking
{
    public static function apply(Route $route): void
    {
        // Cookie/array sessions and process-local caches cannot provide a shared lock.
        // Skip modification for those hosts so unrelated payment gateways keep working;
        // JangoPayMethod refuses new invoices until the host is configured safely.
        $store = config('session.block_store') ?: config('cache.default');
        if (in_array(config('session.driver'), ['cookie', 'array'], true)
            || in_array($store, ['array', 'null'], true)) {
            return;
        }
        // Shop owns these routes, so placing ->block() on our own payment page
        // would be too late to protect session writes in startPayment().
        if (in_array($route->getName(), [
            'shop.payments.payment', // GET: auto-selects the only enabled gateway
            'shop.payments.pay',     // POST: explicit gateway checkout
            'shop.offers.pay',       // POST: Shop's fixed-price offer checkout
            'shop.offers.buy',       // GET: calls startPayment for fixed-amount methods
        ], true)) {
            $route->block(120, 45);
        }
    }
}
