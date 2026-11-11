<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class Discord
{
    public static function send(string $message, string $username = 'Finobe Bot', ?string $avatar = null): void
    {
        $webhook = config('finobe.discord_webhook');

        if (!$webhook) {
            return;
        }

        try {
            Http::timeout(5)->post($webhook, [
                'content' => $message,
                'username' => str_replace('@', '', $username),
                'avatar_url' => $avatar ?: config('app.url') . '/s/img/finnobe3logo.png',
                'allowed_mentions' => ['parse' => []],
            ]);
        } catch (\Throwable) {
        }
    }
}
