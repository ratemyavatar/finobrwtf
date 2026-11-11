<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
    Host2012
     - 2012 game servers: there's no 2012 RCC, so tools/gameserver2012/host2012.ps1 (on the game server
       machine) starts the 2012 client with -script <GameServer2012.ashx URL> and ends it again
     - the script URL carries a one-time token instead of the access key (Client\GameServer2012Controller)
     - the machine to talk to comes from the server's node (App\Services\GameNodes); $controlUrl is that
       node's control_url, or null to fall back to the single FINOBE_HOST2012_URL
*/
class Host2012
{
    public static function configured(?string $controlUrl = null): bool
    {
        return self::base($controlUrl) !== '';
    }

    /** Asks host2012 to start a server for this job. Throws on failure. */
    public static function start(string $jobId, ?string $controlUrl = null): void
    {
        $token = bin2hex(random_bytes(24));
        Cache::put("gameserver2012_{$token}", $jobId, 600);

        $domain = config('finobe.domain');
        // http: the 2012 client's old libcurl/OpenSSL can't be relied on for modern TLS
        $scriptUrl = "http://assetgame.{$domain}/game/gameserver2012.ashx?token={$token}";

        $r = self::client()->post(self::base($controlUrl) . '/start', ['jobId' => $jobId, 'scriptUrl' => $scriptUrl]);
        if (!$r->successful()) {
            throw new \RuntimeException("host2012 /start answered {$r->status()}: " . substr($r->body(), 0, 200));
        }
    }

    /** Ends the server process for this job (no error if it's already gone). */
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
