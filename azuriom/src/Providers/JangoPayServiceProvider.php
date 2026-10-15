<?php

namespace Azuriom\Plugin\JangoPay\Providers;

use Azuriom\Extensions\Plugin\BasePluginServiceProvider;
use Azuriom\Plugin\JangoPay\JangoPayMethod;
use Azuriom\Plugin\JangoPay\Commands\ReconcilePayments;
use Azuriom\Plugin\JangoPay\Support\ShopSessionBlocking;
use Illuminate\Routing\Events\RouteMatched;

class JangoPayServiceProvider extends BasePluginServiceProvider
{
    public function register(): void
    {
        // No shell-level dependency installations or host Composer changes required.
    }

    public function boot(): void
    {
        // Laravel dispatches RouteMatched before the route/web middleware pipeline.
        // Route::block() is therefore observed by StartSession and holds its lock
        // UNTIL the session has been saved, not merely until startPayment returns.
        $this->app['router']->matched(static function (RouteMatched $event): void {
            ShopSessionBlocking::apply($event->route);
        });

        $this->loadViews();
        $this->loadTranslations();
        $this->loadMigrations();
        payment_manager()->registerPaymentMethod('jangopay', JangoPayMethod::class);
        $this->commands([ReconcilePayments::class]);
    }
}
