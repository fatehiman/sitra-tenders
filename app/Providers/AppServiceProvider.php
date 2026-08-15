<?php

namespace App\Providers;

use App\Sms\Contracts\SmsGateway;
use App\Sms\SmsManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * "When anything asks for an SmsGateway, give it an SmsManager."
         *
         * This one line is what lets OtpService type-hint the interface and
         * receive a working sender without knowing msgway exists — and what
         * lets the tests replace it with a fake in a single call.
         *
         * singleton = build it once, then reuse that same instance for the
         * rest of the request.
         */
        $this->app->singleton(SmsGateway::class, fn ($app) => new SmsManager($app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
