<?php

namespace App\Jobs;

use App\Http\Controllers\RobloxArbiterUtilities;
use App\Services\Matchmaking\PlaceLauncher;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StartGameServer
{
    use Dispatchable, Queueable;

    public function __construct(public string $jobId)
    {
    }

    public function handle(): void
    {
        $db = DB::connection('finobe');
        $server = $db->table('servers')->where('jobId', $this->jobId)->first();

        if (!$server || (string) $server->status !== '1' || !Cache::add("gameserver_opening_{$this->jobId}", true, 600)) {
            return;
        }

        $place = $db->table('assets')->where('id', $server->placeid)->first();

        $version = $place ? \App\Services\ClientVersions::of($place) : \App\Services\ClientVersions::DEFAULT;

        if ($place && !\App\Services\ClientVersions::usesRcc($version)) {
            $node = \App\Services\GameNodes::forServer($server);
            $controlUrl = $node?->control_url;
            if (!\App\Services\Host2012::configured($controlUrl)) {
                PlaceLauncher::startFailed($this->jobId, '2012 game servers are not set up yet, try again later.');
                return;
            }
            try {
                \App\Services\Host2012::start($this->jobId, $controlUrl);
            } catch (\Throwable $e) {
                Log::warning("2012 game server {$this->jobId} for place {$server->placeid} failed to start: " . $e->getMessage());
                PlaceLauncher::startFailed($this->jobId, "Couldn't reach the 2012 game server machine, try again in a bit.");
            }
            return;
        }

        $scriptName = basename((string) (\App\Services\ClientVersions::get($version)['script'] ?? 'gameserver2016.lua'));
        $script = @file_get_contents(storage_path('rbx/files/scripts/' . $scriptName));
        [$rccHost, $rccPort] = \App\Services\ClientVersions::rcc($version);

        if (!$place || $script === false) {
            PlaceLauncher::startFailed($this->jobId);
            return;
        }

        @set_time_limit(180);
        @ini_set('default_socket_timeout', '120');

        try {
            $arbiter = new RobloxArbiterUtilities($rccHost, $rccPort);

            $job = $arbiter->ConstructJob($this->jobId, $script, 21600, 0, 1, 'GameServer', [
                (int) $server->placeid,
                (int) $server->port,
                'https://www.' . config('finobe.domain'),
                (int) $place->author,
                (string) config('finobe.cloud_key'),
                config('finobe.force_filtering', true) ? 1 : 0,
            ]);

            $result = $arbiter->SoapCallService('OpenJobEx', $job);
        } catch (\Throwable $e) {
            Log::warning("Game server {$this->jobId} for place {$server->placeid} failed to start: " . $e->getMessage());
            PlaceLauncher::startFailed($this->jobId);
            return;
        }

        if (is_soap_fault($result)) {
            $message = $result->getMessage();
            Log::warning("Game server {$this->jobId} for place {$server->placeid}: {$message}");

            if (stripos($message, 'connect') !== false) {
                PlaceLauncher::startFailed($this->jobId, "Couldn't reach the game server machine, try again in a bit.");
            }
        }
    }
}