<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class Turnstile
{
    public static function enabled(): bool
    {
        return (bool) config('finobe.turnstile.secret');
    }

    public static function passes(?string $token, string $ip): bool
    {
        if (!self::enabled()) {
            return true;
        }

        if (!$token) {
            return false;
        }

        try {
            return (bool) Http::asForm()->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('finobe.turnstile.secret'),
                'response' => $token,
                'remoteip' => $ip,
            ])->json('success');
        } catch (\Throwable) {
            return false;
        }
    }
}
