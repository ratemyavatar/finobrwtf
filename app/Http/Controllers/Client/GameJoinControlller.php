<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FormatVersion;
use App\Http\Controllers\RobloxUtilities;
use App\Http\Controllers\SecurityNotary;
use App\Models\User;
use App\Services\Matchmaking\Enums\PlaceLauncherStatusCodes as Status;
use App\Services\Matchmaking\PlaceLauncher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GameJoinControlller extends Controller
{
    /*
        Game/PlaceLauncher.ashx
         - the client polls this every few seconds: 0/1 = keep waiting, 2 = join (joinScriptUrl), anything else = error
    */
    public function placeLauncher(Request $request)
    {
        $user = Auth::user();

        // the Play buttons put the player's ticket in the URL, so this works even when the client's
        // session cookie from Negotiate.ashx didn't stick (same as bubbablox-v2 does it)
        $ticket = $request->query('ticket');
        if (!$user && is_string($ticket) && $ticket !== '') {
            $user = User::where('token', $ticket)->first();
        }

        // the 2016 client rebuilds this URL on its http:// BaseUrl (dropping ?ticket=), and the site's
        // session cookie is https-only, so Negotiate.ashx also sets a short-lived game cookie for that
        $clientSession = $request->cookie(AuthenticationController::CLIENT_COOKIE);
        if (!$user && is_string($clientSession) && $clientSession !== '' && ($id = Cache::get("client_session_{$clientSession}"))) {
            $user = User::find($id);
        }

        if (!$user) {
            return $this->launcherResponse(['status' => Status::Error, 'jobId' => '', 'message' => 'You need to be logged in to play.'], $request);
        }

        if (($user->banned ?? false) || in_array($user->status ?? '', ['banned', 'terminated'], true)) {
            return $this->launcherResponse(['status' => Status::Restricted, 'jobId' => '', 'message' => "You can't play right now."], $request, $user);
        }

        if ($restricted = \App\Services\Restrictions::message((int) $user->id, 'play')) {
            return $this->launcherResponse(['status' => Status::Restricted, 'jobId' => '', 'message' => $restricted], $request, $user);
        }

        $placeId = (int) ($request->input('placeId') ?? $request->input('placeid') ?? 0);
        $jobId = null;

        switch (strtolower((string) $request->input('request', 'RequestGame'))) {
            case 'requestgamejob':
                $jobId = (string) ($request->input('gameId') ?? $request->input('gameid') ?? '');
                break;
            case 'requestgame':
            case 'requestfollowuser':
            default:
                break;
        }

        // a 2012 place opened by a launcher from before 2012 support (build 1) starts the 2016 client,
        // which polls here with a Roblox user agent: tell it to update instead of failing on the 2012 join script
        if (stripos((string) $request->userAgent(), 'roblox') !== false) {
            $place = \Illuminate\Support\Facades\DB::connection('finobe')->table('assets')->where('id', $placeId)->where('asset_type', 9)->first();
            if ($place && \App\Services\ClientVersions::joinFormat(\App\Services\ClientVersions::of($place)) === 'lua2012') {
                return $this->launcherResponse(['status' => Status::Error, 'jobId' => '',
                    'message' => 'This game runs on the 2012 client. Download the launcher again (finobe.wtf/download), then press Play.'], $request, $user);
            }
        }

        return $this->launcherResponse(PlaceLauncher::request($placeId, (object) ($user->toArray() + ['token' => $user->token]), $jobId), $request, $user);
    }

    /*
        Game/Join.ashx
         - the signed join script for the server PlaceLauncher handed out
    */
    public function joinScript(Request $request)
    {
        $jobId = (string) $request->query('jobId', $request->query('jobid', ''));
        $user = Auth::user();

        // the one-time ticket from PlaceLauncher works even if the session cookie didn't make it here
        $ticket = (string) $request->query('ticket', '');
        // usable again for a minute after the first fetch: the 2012 client's httpGet retries
        if ($ticket !== '' && ($data = Cache::get("join_ticket_{$ticket}")) && $data['jobId'] === $jobId) {
            if (Cache::add("join_ticket_{$ticket}_used", true, 60)) {
                Cache::put("join_ticket_{$ticket}", $data, 60);
            }
            $user = User::find($data['user']) ?? $user;
        }

        if (!$user) {
            return response()->json(['code' => 403, 'message' => 'You need to be logged in to play.'], 403);
        }
        if ($restricted = \App\Services\Restrictions::message((int) $user->id, 'play')) {
            return response()->json(['code' => 403, 'message' => $restricted], 403);
        }

        $db = DB::connection('finobe');
        $server = $db->table('servers')->where('jobId', $jobId)->where('status', '2')->first();
        if (!$server) {
            return response()->json(['code' => 404, 'message' => 'That server has shut down.'], 404);
        }

        $place = $db->table('assets')->where('id', $server->placeid)->first();
        if (!$place) {
            return response()->json(['code' => 404, 'message' => "This game doesn't exist."], 404);
        }

        $additional = json_decode($place->additional ?? '{}') ?: (object) [];
        $additional->version = \App\Services\ClientVersions::of($additional);

        if ($reason = \App\Services\PlaceAccess::denyReason($place, (object) $user->toArray())) {
            return response()->json(['code' => 403, 'message' => $reason], 403);
        }

        // lets the game server verify this player when they connect (/api/gameserver/verify)
        Cache::put("gameserver_join_{$server->jobId}_{$user->id}", true, 600);

        $joinScript = RobloxUtilities::ConstructJoinScript((object) $user->toArray(), $place, $server, $additional);

        $signed = SecurityNotary::SignScript($joinScript, \App\Services\ClientVersions::joinFormat($additional->version) === 'lua2012' ? FormatVersion::V1 : FormatVersion::V2);

        // explicit length: without it nginx sends the script chunked, which the 2012 client's httpGet rejects
        return response($signed, 200)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Length', (string) strlen($signed));
    }

    private function launcherResponse(array $r, ?Request $request = null, $user = null)
    {
        // the client only says "Failed to request game" for these, so the real reason goes to the log
        if (!in_array($r['status'], [Status::Waiting, Status::Loading, Status::Joining], true)) {
            \Illuminate\Support\Facades\Log::info('PlaceLauncher refused: ' . $r['message'], [
                'status' => $r['status']->value,
                'user' => $user?->id,
                'placeId' => $request?->input('placeId'),
                'request' => $request?->input('request'),
                'hasCookieSession' => Auth::check(),
            ]);
        }

        return response()->json([
            'jobId' => $r['jobId'],
            'status' => $r['status']->value,
            'joinScriptUrl' => $r['joinScriptUrl'] ?? '',
            'authenticationUrl' => $r['authenticationUrl'] ?? '',
            'authenticationTicket' => $r['authenticationTicket'] ?? '',
            'message' => $r['message'] ?? '',
            // where the launcher relays the client's localhost UDP (2012 joins)
            'serverAddress' => $r['serverAddress'] ?? '',
            'serverPort' => $r['serverPort'] ?? 0,
        ], 200, [], JSON_UNESCAPED_SLASHES);
    }
}
