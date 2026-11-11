<?php

namespace App\Providers;

use App\Model\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            /* stop
            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
            */
            if ($entry->type == 'request' && isset($entry->content['uri']) && (str_starts_with($entry->content['uri'], '/s') || str_starts_with($entry->content['uri'], '/web') || str_starts_with($entry->content['uri'], '/fonts'))) {
                return false;
            }

            return true;
        });

        Telescope::tag(function (IncomingEntry $entry) {
            if ($entry->isRequest()) {
                $request = $entry->content['request'] ?? null;
                $ip = $request['headers']['HTTP_CF_CONNECTING_IP'] ?? request()->ip();
                return ['ip' => $ip, 'path' => Str::limit($request['uri'] ?? request()->getRequestUri(), 255, '')];
            }
            return [];
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token', 'password', 'password_confirmation', 'current_password', 'new_password', 'old_password', 'code', 'otp', 'backup_code', 'invite_key', 'token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'authorization',
            'accesskey',
            'roblox-security',
            'cf-connecting-ip',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function () {
            // site admins only (it used to be two hardcoded usernames, so anyone who registered one got in)
            $user = Auth::user();
            return $user && $user->status === 'admin';
        });
    }
}
