<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FormatVersion;
use App\Http\Controllers\SecurityNotary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
    Game/GameServer2012.ashx?token=
     - the signed 2012 game server script (storage/rbx/files/scripts/gameserver2012.lua) for one job
     - the token is one-time and comes from Host2012::start, which hands this URL to host2012.ps1
*/
class GameServer2012Controller extends Controller
{
    public function script(Request $request)
    {
        $token = (string) $request->query('token', '');
        // the 2012 client's httpGet retries a script fetch it didn't like, so a used token keeps working
        // for a minute (it only ever opens this one job's script)
        $jobId = $token !== '' ? Cache::get("gameserver2012_{$token}") : null;
        if ($jobId && Cache::add("gameserver2012_{$token}_used", true, 60)) {
            Cache::put("gameserver2012_{$token}", $jobId, 60);
        }
        if (!$jobId) {
            return response('-- expired', 403)->header('Content-Type', 'text/plain');
        }

        $db = DB::connection('finobe');
        $server = $db->table('servers')->where('jobId', $jobId)->first();
        $place = $server ? $db->table('assets')->where('id', $server->placeid)->first() : null;
        if (!$place) {
            return response('-- no such server', 404)->header('Content-Type', 'text/plain');
        }

        $script = (string) file_get_contents(storage_path('rbx/files/scripts/gameserver2012.lua'));
        $script = strtr($script, [
            '{placeId}' => (string) (int) $place->id,
            '{port}' => (string) (int) $server->port,
            '{jobId}' => preg_replace('/[^A-Za-z0-9-]/', '', $server->jobId),
            '{creatorId}' => (string) (int) $place->author,
            '{baseUrl}' => 'http://assetgame.' . env('FINOBE_DOMAIN', 'finobe.wtf'),
            '{accessKey}' => addcslashes((string) env('FINOBE_CLOUD_AUTHORIZATION', ''), "\\\"\r\n"),  // inside a Lua "string"
        ]);

        $signed = SecurityNotary::SignScript($script, FormatVersion::V1);
        // explicit length: the 2012 client's httpGet doesn't like nginx's chunked replies
        return response($signed, 200)->header('Content-Type', 'text/plain')->header('Content-Length', (string) strlen($signed));
    }
}
