<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // the client years a place can use (place creation/settings, the place page's Play button)
        \Illuminate\Support\Facades\View::share('client_versions', \App\Services\ClientVersions::names());

        if (env('APP_ENV') !== 'local') {
            $appUrl = parse_url(env('APP_URL'), PHP_URL_HOST);
            $requestHost = request()->getHost();

            if ($appUrl == $requestHost || request()->is('telescope*')) {
                URL::forceScheme('https');
            }
        }
    }
}
