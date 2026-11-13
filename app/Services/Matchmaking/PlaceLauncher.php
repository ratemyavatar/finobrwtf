<?php

namespace App\Services\Matchmaking;

use App\Jobs\StartGameServer;
use App\Services\Matchmaking\Enums\PlaceLauncherStatusCodes as Status;
use App\Services\PlaceAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PlaceLauncher
{
    const START_TIMEOUT = 120;

    const ALIVE_TIMEOUT = 75;

    const FAIL_COOLDOWN = 45;

    public static function request(int $placeId, object $user, ?string $jobId = null): array
    {
        $db = DB::connection('finobe');
        $place = $db->table('assets')->where('id', $placeId)->where('asset_type', 9)->first();

        if (!$place) {
            return self::result(Status::Error, '', "This game doesn't exist.");
        }

        $additional = json_decode($place->additional ?? '{}', true) ?: [];

        if (($additional['allowplaying'] ?? true) === false && (int) $place->author !== (int) $user->id) {
            return self::result(Status::Restricted, '', "This game isn't open right now.");
        }

        if ($reason = PlaceAccess::denyReason($place, $user)) {
            return self::result(Status::Restricted, '', $reason);
        }

        if (!\App\Services\ClientVersions::isValid(\App\Services\ClientVersions::of($additional))) {
            return self::result(Status::Error, '', "This game's client isn't available right now.");
        }

        if (!self::gameServerHost(\App\Services\ClientVersions::of($additional))) {
            return self::result(Status::Error, '', 'Game servers are not set up yet, try again later.');
        }

        self::cleanup();

        $maxPlayers = max(1, (int) ($additional['maxplayers'] ?? 15));
        $servers = $db->table('servers')->where('placeid', $placeId)->whereIn('status', ['1', '2'])->get();

        if ($jobId !== null && $jobId !== '') {
            $server = $servers->firstWhere('jobId', $jobId);

            if (!$server) {
                return self::result(Status::GameEnded, $jobId, 'That server has shut down.');
            }
            if ((string) $server->status === '1') {
                return self::result(Status::Loading, $jobId, 'The server is starting...');
            }
            if (!self::isAlive($server)) {
                return self::result(Status::GameEnded, $jobId, 'That server has shut down.');
            }
            if (self::playerCount($server) >= $maxPlayers) {
                return self::result(Status::GameFull, $jobId, 'That server is full.');
            }

            return self::ready($server, $user, $additional);
        }

        $running = $servers->filter(fn($s) => self::isAlive($s) && self::playerCount($s) < $maxPlayers)
            ->sortByDesc(fn($s) => self::playerCount($s));

        if ($server = $running->first()) {
            return self::ready($server, $user, $additional);
        }

        if ($server = $servers->first(fn($s) => (string) $s->status === '1'
            && $s->started_at
            && strtotime((string) $s->started_at) > now()->subSeconds(self::START_TIMEOUT)->getTimestamp())) {
            return self::result(Status::Loading, $server->jobId, 'The server is starting...');
        }

        if ($error = Cache::get("gameserver_failed_{$placeId}")) {
            return self::result(Status::Error, '', $error);
        }

        $lock = Cache::lock("gameserver_start_{$placeId}", 10);
        if (!$lock->get()) {
            return self::result(Status::Waiting, '', 'Looking for a server...');
        }

        try {
            $newJobId = self::generateJobId();

            $node = \App\Services\GameNodes::pick(\App\Services\ClientVersions::of($additional));
            if ($node) {
                $ip = $node->public_ip;
                $port = \App\Services\GameNodes::freePort($node);
                if ($port === null) {
                    throw new \RuntimeException('node full');
                }
            } else {
                $ip = self::gameServerHost(\App\Services\ClientVersions::of($additional));
                $port = self::freePort();
            }

            $db->table('servers')->insert([
                'ip' => $ip,
                'port' => $port,
                'placeid' => $placeId,
                'node_id' => $node?->id,
                'players' => '[]',
                'jobId' => $newJobId,
                'status' => '1',
                'started_at' => now(),
                'last_alive' => null,
            ]);
        } catch (\RuntimeException $e) {
            return self::result(Status::GameFull, '', 'All game servers are busy, try again in a bit.');
        } finally {
            $lock->release();
        }

        StartGameServer::dispatchAfterResponse($newJobId);

        return self::result(Status::Waiting, $newJobId, 'Starting a server...');
    }

    public static function startFailed(string $jobId, string $message = "Couldn't start a server, try again in a bit."): void
    {
        $server = DB::connection('finobe')->table('servers')->where('jobId', $jobId)->first();
        if (!$server) {
            return;
        }

        DB::connection('finobe')->table('servers')->where('jobId', $jobId)->delete();
        Cache::put("gameserver_failed_{$server->placeid}", $message, self::FAIL_COOLDOWN);
    }

    public static function gameServerHost(?string $version = null): ?string
    {
        $host = trim($version !== null ? \App\Services\ClientVersions::gameserverHost($version) : (string) config('finobe.gameserver.host'));

        if ($host === '' || in_array($host, ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true)) {
            return config('app.debug') && $host !== '' ? $host : null;
        }

        return $host;
    }

    public static function cleanup(): int
    {
        $db = DB::connection('finobe');

        $stale = $db->table('servers')->where('status', '1')
            ->where(fn($q) => $q->whereNull('started_at')->orWhere('started_at', '<', now()->subSeconds(self::START_TIMEOUT)))
            ->get()
            ->merge(
                $db->table('servers')->where('status', '2')
                    ->where(fn($q) => $q->whereNull('last_alive')->orWhere('last_alive', '<', now()->subSeconds(self::ALIVE_TIMEOUT)))
                    ->where(fn($q) => $q->whereNull('started_at')->orWhere('started_at', '<', now()->subSeconds(self::ALIVE_TIMEOUT)))
                    ->get()
            );

        if ($stale->isEmpty()) {
            return 0;
        }

        $db->table('servers')->whereIn('jobId', $stale->pluck('jobId')->all())->delete();

        $servers = $stale->all();
        $stop = function () use ($servers) {
            foreach ($servers as $server) {
                try {
                    $place = DB::connection('finobe')->table('assets')->where('id', $server->placeid)->first();
                    $version = $place ? \App\Services\ClientVersions::of($place) : \App\Services\ClientVersions::DEFAULT;

                    if (\App\Services\ClientVersions::usesRcc($version)) {
                        [$rccHost, $rccPort] = \App\Services\ClientVersions::rcc($version);
                        (new \App\Http\Controllers\RobloxArbiterUtilities($rccHost, $rccPort))->CloseJob($server->jobId);
                    } else {
                        \App\Services\Host2012::stop($server->jobId, optional(\App\Services\GameNodes::forServer($server))->control_url);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        };

        if (app()->runningInConsole()) {
            $stop();
        } else {
            app()->terminating($stop);
        }

        return $stale->count();
    }

    private static function isAlive(object $server): bool
    {
        if ((string) $server->status !== '2' || empty($server->last_alive)) {
            return false;
        }

        return strtotime((string) $server->last_alive) > now()->subSeconds(self::ALIVE_TIMEOUT)->getTimestamp();
    }

    private static function ready(object $server, object $user, array $additional = []): array
    {
        $ticket = bin2hex(random_bytes(24));
        Cache::put("join_ticket_{$ticket}", ['user' => (int) $user->id, 'jobId' => $server->jobId], 300);

        $domain = config('finobe.domain');

        $scheme = \App\Services\ClientVersions::scheme(\App\Services\ClientVersions::of($additional));

        return self::result(Status::Joining, $server->jobId, '', [
            'joinScriptUrl' => "{$scheme}://assetgame.{$domain}/game/join.ashx?jobId=" . urlencode($server->jobId) . "&ticket={$ticket}",
            'authenticationUrl' => "https://www.{$domain}/Login/Negotiate.ashx",
            'authenticationTicket' => (string) ($user->ticket ?? ''),

            'serverAddress' => (string) ($server->ip ?? ''),
            'serverPort' => (int) ($server->port ?? 0),
        ]);
    }

    private static function result(Status $status, string $jobId, string $message, array $extra = []): array
    {
        return ['status' => $status, 'jobId' => $jobId, 'message' => $message] + $extra + [
            'joinScriptUrl' => '',
            'authenticationUrl' => '',
            'authenticationTicket' => '',
        ];
    }

    private static function playerCount(object $server): int
    {
        $players = json_decode((string) $server->players, true);
        return is_array($players) ? count($players) : 0;
    }

    private static function freePort(): int
    {
        $min = (int) config('finobe.gameserver.port_min');
        $max = (int) config('finobe.gameserver.port_max');

        $used = DB::connection('finobe')->table('servers')->pluck('port')->map(fn($p) => (int) $p)->all();
        $free = array_values(array_diff(range($min, $max), $used));

        if (empty($free)) {
            throw new \RuntimeException('No free game server ports in the configured range');
        }

        return $free[random_int(0, count($free) - 1)];
    }

    private static function generateJobId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}