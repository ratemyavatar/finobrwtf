<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\Ban;
use App\Models\User;
use App\Models\Asset;
use App\Models\Server;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function index(Request $request, $id) {
        if(!Auth::check()) {
            return redirect('/');
        }

        if(!User::where('id', $id)->exists()) {
            abort(404);
        }

        $user = User::find($id)->toArray();

        \App\Services\AvatarRenderer::renderIfMissing((int) $user['id'], $user['pfp'] ?? null);

        $title = htmlspecialchars($user['username']);

        $user['places'] = [];
        $joined = strtotime($user['created'] ?? $user['created_at'] ?? '');
        $user['created'] = $joined ? date('m/d/Y h:i:s A', $joined) : 'Unknown';
        $user['blurb'] = nl2br(str_replace('${myDius}', '<span class="n-money-text text-nowrap"><img src="/s/img/diu_16.png" alt="Diu" title="Diu" class="img-responsive align-middle "> [' . number_format($user['Dius']) . ']</span>', preg_replace('/\b((?:https?|ftp):\/\/\S+)/i', '<a href="$1">$1</a>', strip_tags(htmlspecialchars(\App\Services\CurseFilter::forViewer($user['blurb']))))));
        $user['badges'] = $user['badges']['data']['custom_badges'] ?? [];
        $user['CurrentFriends'] = array_reverse(array_filter($user['friends'], fn($friend) => $friend['status'] == 'friends'));

        $servers = Server::get()->map(fn($item) => $item->toArray());

        foreach($servers as $server) {
            $placeid = 0;
            $jobId = '';
            $found = false;

            foreach($server['players'] as $player) {
                if($player == $user['id']) {
                    $found = true;
                    $placeid = $server['placeid'];
                    $jobId = $server['jobId'];
                    break;
                }
            }

            if($found) {
                $user['InGame'] = true;
                $user['game'] = [
                    'id' => $placeid,
                    'title' => strip_tags(htmlspecialchars(Asset::where('id', $placeid)->value('title'))),
                    'jobId' => $jobId
                ];
            }
        }

        $user['friends'] = array_reverse($user['friends']);

        foreach($user['friends'] as $key => $friend) {
            $user['friends'][$key]['username'] = Cache::remember('username_' . $friend['userid'], 60 * 60, fn() => User::where('id', $friend['userid'])->value('username'));
            $user['friends'][$key]['pfp'] = Cache::remember('pfp_' . $friend['userid'], 60 * 60, fn() => User::where('id', $friend['userid'])->value('pfp'));
        }

        foreach($user['CurrentFriends'] as $key => $friend) {
            $user['CurrentFriends'][$key]['username'] = Cache::remember('username_' . $friend['userid'], 60 * 60, fn() => User::where('id', $friend['userid'])->value('username'));
            $user['CurrentFriends'][$key]['pfp'] = Cache::remember('pfp_' . $friend['userid'], 60 * 60, fn() => User::where('id', $friend['userid'])->value('pfp'));
        }

        $places = Asset::where('author', $user['id'])
            ->where('asset_type', 9)
            ->get()
            ->map(fn($item) => $item->toArray());

        foreach($places as $index => $place) {
            $place['count'] = $index + 1;
            $place['thumbnail'] = \App\Services\PlaceThumbnail::url($place['additional']);
            \App\Services\PlaceThumbnail::renderIfMissing((int) $place['id'], $place['additional']);
            $user['places'][] = $place;
        }

        if(Ban::where('username', $user['username'])->where('perm', true)->exists()) {
            $user['ban'] = [
                'IsBanned' => true,
                'data' => [
                    'reason' => Ban::select('reason')->where('username', $user['username'])->where('perm', true)->value('reason')
                ]
            ];
        }

        $pageData['profile'] = $user;
        $pageData['admin_flair'] = \Illuminate\Support\Facades\Schema::connection('finobe')->hasTable('admin_flair')
            ? (array) \Illuminate\Support\Facades\DB::connection('finobe')->table('admin_flair')->where('user_id', $user['id'])->first() : [];
        return $this->page('User', $pageData ?? [], $title ?? null);
    }
}