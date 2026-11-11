<?php

namespace App\Jobs;

use App\Http\Controllers\RobloxArbiterUtilities;
use App\Services\Matchmaking\PlaceLauncher;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
    StartGameServer
     - opens the game server job on RCC after the PlaceLauncher response is sent (no queue worker needed)
     - the game server script loads the place, starts NetworkServer and calls /api/gameserver/register,
       which flips the server to "running" so the polling clients get a join script
*/
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

        // only once per server, and only while it's still waiting to start
        if (!$server || (string) $server->status !== '1' || !Cache::add("gameserver_opening_{$this->jobId}", true, 600)) {
            return;
        }

        $place = $db->table('assets')->where('id', $server->placeid)->first();

        $version = $place ? \App\Services\ClientVersions::of($place) : \App\Services\ClientVersions::DEFAULT;

        // 2012 places: no RCC, host2012.ps1 runs the 2012 client as the server (see App\Services\Host2012)
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

        // each RCC year has its own game server script and its own RCCService (config finobe.clients)
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
            // long lease: the game server closes itself through /api/gameserver/shutdown once it's empty
            $job = $arbiter->ConstructJob($this->jobId, $script, 21600, 0, 1, 'GameServer', [
                (int) $server->placeid,
                (int) $server->port,
                'https://www.' . config('finobe.domain'),
                (int) $place->author,
                (string) config('finobe.cloud_key'),
            ]);

            // call SOAP directly: OpenJobEx() would turn a fault into null
            $result = $arbiter->SoapCallService('OpenJobEx', $job);
        } catch (\Throwable $e) {
            Log::warning("Game server {$this->jobId} for place {$server->placeid} failed to start: " . $e->getMessage());
            PlaceLauncher::startFailed($this->jobId);
            return;
        }

        if (is_soap_fault($result)) {
            $message = $result->getMessage();
            Log::warning("Game server {$this->jobId} for place {$server->placeid}: {$message}");

            // RCC unreachable: tell the waiting players now. Anything else (e.g. a read timeout while a big
            // place loads) may still come up; PlaceLauncher drops it if it never calls /register.
            if (stripos($message, 'connect') !== false) {
                PlaceLauncher::startFailed($this->jobId, "Couldn't reach the game server machine, try again in a bit.");
            }
        }
        // otherwise the script returns right after it called /register (see gameserver2016.lua)
    }
}