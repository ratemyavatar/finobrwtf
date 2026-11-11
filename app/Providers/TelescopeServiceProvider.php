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
    public function register(): void
    {
        Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
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

    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token', 'password', 'password_confirmation', 'current_password', 'new_password', 'old_password', 'code', 'otp', 'backup_code', 'invite_key', 'token', 'accesskey', 'AccessKey', 'ticket', 'suggest', 'key', 'jk', 'rendertoken']);

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

    protected function gate(): void
    {
        Gate::define('viewTelescope', function () {
            $user = Auth::user();
            return $user && $user->status === 'admin';
        });
    }
}
