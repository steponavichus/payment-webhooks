<?php

namespace App\Providers;

use App\Webhooks\SignatureVerifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            SignatureVerifier::class,
            fn () => new SignatureVerifier((int) config('webhooks.tolerance')),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
