<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Host2012
{
    public static function configured(?string $controlUrl = null): bool
    {
        return self::base($controlUrl) !== '';
    }

    public static function start(string $jobId, ?string $controlUrl = null): void
    {
        $token = bin2hex(random_bytes(24));
        Cache::put("gameserver2012_{$token}", $jobId, 600);

        $domain = config('finobe.domain');

        $scriptUrl = "http://assetgame.{$domain}/game/gameserver2012.ashx?token={$token}";

        $r = self::client()->post(self::base($controlUrl) . '/start', ['jobId' => $jobId, 'scriptUrl' => $scriptUrl]);
        if (!$r->successful()) {
            throw new \RuntimeException("host2012 /start answered {$r->status()}: " . substr($r->body(), 0, 200));
        }
    }

    public static function stop(string $jobId, ?string $controlUrl = null): void
    {
        if (self::configured($controlUrl)) {
            self::client()->post(self::base($controlUrl) . '/stop', ['jobId' => $jobId]);
        }
    }

    private static function base(?string $controlUrl): string
    {
        return rtrim((string) ($controlUrl ?: config('finobe.host2012.url')), '/');
    }

    private static function client()
    {
        return Http::timeout(15)->withHeaders(['AccessKey' => (string) config('finobe.cloud_key')])->asJson();
    }
}
