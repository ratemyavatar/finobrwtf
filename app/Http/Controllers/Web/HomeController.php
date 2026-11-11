<?php

namespace App\Http\Controllers\Web;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use App\Models\User;
use App\Models\Asset;
use App\Models\Server;
use App\Admin\Workbench;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function index(Request $request) {
        $title = 'Home';

        if(Auth::check()) {
            $pageData['games'] = [];

            $games = Cache::remember('latest_places', 60 * 10, function() {
                $playerCounts = [];

                foreach(Server::select('placeid', 'players')->get() as $server) {
                    $playerCounts[$server->placeid] = ($playerCounts[$server->placeid] ?? 0) + count($server->players ?? []);
                }

                return Asset::where('asset_type', 9)
                    ->get()
                    ->map(function($item) use ($playerCounts) {
                        $item = $item->toArray();
                        $item['total_players'] = $playerCounts[$item['id']] ?? 0;
                        return $item;
                    })
                    ->sortByDesc('total_players')
                    ->take(6)
                    ->values()
                    ->all();
            });

            foreach($games as $game) {
                $players = 0;

                $servers = Server::select('players')
                    ->where('placeid', $game['id'])
                    ->get()
                    ->map(fn($item) => $item->toArray());

                foreach($servers as $server) {
                    $players += count($server['players']);
                }

                $thumbnail = \App\Services\PlaceThumbnail::url($game['additional']);
                \App\Services\PlaceThumbnail::renderIfMissing((int) $game['id'], $game['additional']);

                $pageData['games'][] = [
                    'id' => $game['id'],
                    'title' => $game['title'],
                    'author' => User::find($game['author'])->username,
                    'thumbnail' => $thumbnail,
                    'visits' => number_format($game['additional']['visits']),
                    'version' => $game['additional']['version'],
                    'players' => $players
                ];
            }
        }

        return $this->page('Landing', $pageData ?? [], $title ?? null);
    }

    public function reactivate(Request $request) {
        $user = $request->user();

        if (!$user) {
            return redirect('/');
        }

        $db = DB::connection('finobe');
        $notReactivated = Workbench::flag('bans', 'reactivated', false);
        $notUndone = Workbench::flag('bans', 'undone', false);
        $bans = $db->table('bans')->where('username', $user->username)->where('reactivated', $notReactivated)->where('undone', $notUndone)->get();

        foreach ($bans as $ban) {
            $permanent = in_array(strtolower((string) $ban->perm), ['1', 'y'], true);

            if ($permanent || ($ban->expire && Carbon::parse($ban->expire)->isFuture())) {
                Session::put('error', 'This activity has been logged and your ban may be extended');

                return redirect('/');
            }
        }

        $db->table('bans')->where('username', $user->username)->where('reactivated', $notReactivated)
            ->update(['reactivated' => Workbench::flag('bans', 'reactivated', true)]);
        $db->table('warning')->where('username', $user->username)->where('reactivated', Workbench::flag('warning', 'reactivated', false))
            ->update(['reactivated' => Workbench::flag('warning', 'reactivated', true)]);

        Session::put('successv2', 'Your moderation action has been lifted.');

        return redirect('/');
    }

    public function create() {
        $title = 'Create asset';

        if(!Auth::check()) {
            return redirect('/');
        }

        return $this->page('Create', $pageData ?? [], $title ?? null);
    }
}