<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\View::share('client_versions', \App\Services\ClientVersions::names());

        if (!app()->environment('local')) {
            $appUrl = parse_url(config('app.url'), PHP_URL_HOST);
            $requestHost = request()->getHost();

            if ($appUrl == $requestHost || request()->is('telescope*')) {
                URL::forceScheme('https');
            }
        }
    }
}
