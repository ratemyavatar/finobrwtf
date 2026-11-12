<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Purchases;
use App\Models\User;
use App\Services\Trading;
use App\Services\UsernameChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class AdminToolsController extends Controller
{
    public function __construct(Request $request) {
        if(!Auth::check() || Auth::user()->status != 'admin') {
            abort(404);
        }
    }

    private function db() {
        return DB::connection('finobe');
    }

    private function toolPage(string $view, string $title, array $extra = []) {
        $pageData['tool'] = ['title' => $title, 'url' => '/' . request()->path()];
        foreach($extra as $k => $v) {
            $pageData[$k] = $v;
        }
        return $this->page('Admin/' . $view, $pageData, $title);
    }

    private function back(string $message, bool $ok = true) {
        Session::put($ok ? 'success' : 'error', $message);
        return redirect('/' . request()->path());
    }

    private function findUser($name): ?User {
        $name = trim((string) $name);
        if($name === '') {
            return null;
        }
        return ctype_digit($name) ? User::find((int) $name) : User::where('username', $name)->first();
    }

    public function ipBans(Request $request) {
        if($request->isMethod('post')) {
            if($request->input('action') === 'unban') {
                $this->db()->table('ip_bans')->where('id', (int) $request->input('id'))->delete();
                Cache::forget('ip_bans');
                return $this->back('IP unbanned.');
            }
            $ip = trim((string) $request->input('ip'));
            if(!filter_var($ip, FILTER_VALIDATE_IP)) {
                return $this->back("That isn't an IP address.", false);
            }
            if($ip === $request->ip()) {
                return $this->back("That's your own IP.", false);
            }
            $this->db()->table('ip_bans')->updateOrInsert(['ip' => $ip], [
                'reason' => mb_substr((string) $request->input('reason'), 0, 255),
                'moderator_id' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Cache::forget('ip_bans');
            return $this->back($ip . ' is IP banned.');
        }

        $lookup = null;
        if(($user = $this->findUser($request->query('user'))) && \Illuminate\Support\Facades\Schema::connection('finobe')->hasTable('sessions')) {
            $lookup = ['username' => $user->username, 'ips' => $this->db()->table('sessions')->where('user_id', $user->id)
                ->whereNotNull('ip_address')->orderByDesc('last_activity')->pluck('ip_address')->unique()->values()->all()];
        }
        $bans = $this->db()->table('ip_bans')->orderByDesc('id')->get();
        $mods = User::whereIn('id', $bans->pluck('moderator_id')->filter()->all())->pluck('username', 'id');
        return $this->toolPage('IpBans', 'IP Bans', ['bans' => $bans->map(fn($b) => (array) $b + ['moderator' => $mods[$b->moderator_id] ?? null])->all(), 'lookup' => $lookup]);
    }

    public function forceRegen(Request $request) {
        $results = [];
        if($request->isMethod('post')) {
            foreach(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $request->input('usernames')))) as $name) {
                if($user = $this->findUser($name)) {
                    \App\Jobs\RenderAvatar::dispatchAfterResponse($user->id);
                    $results[] = ['username' => $user->username, 'ok' => true];
                } else {
                    $results[] = ['username' => $name, 'ok' => false];
                }
            }
        }
        return $this->toolPage('ForceRegen', 'Force Regen Character', ['results' => $results]);
    }

    public function purgeGames(Request $request) {
        $cutoff = now()->subMinutes(10);
        $dead = function () use ($cutoff) {
            $q = $this->db()->table('servers');
            $cols = \Illuminate\Support\Facades\Schema::connection('finobe')->getColumnListing('servers');
            if(in_array('last_alive', $cols, true)) {
                $q->where(fn($w) => $w->where('last_alive', '<', $cutoff)
                    ->orWhere(fn($n) => $n->whereNull('last_alive')->where(fn($s) => $s->whereNull('started_at')->orWhere('started_at', '<', $cutoff))));
            }
            return $q;
        };
        if($request->isMethod('post')) {
            $n = $dead()->delete();
            return $this->back("Purged {$n} inactive game server" . ($n == 1 ? '' : 's') . '.');
        }
        $servers = $dead()->orderBy('id')->get();
        $places = Asset::whereIn('id', $servers->pluck('placeid')->all())->pluck('title', 'id');
        return $this->toolPage('PurgeGames', 'Purge Inactive Game Servers', ['servers' => $servers->map(fn($s) => (array) $s + ['place' => $places[$s->placeid] ?? '?'])->all()]);
    }

    public function purchaseLog(Request $request) {
        $q = Purchases::whereIn('type', [1, 2, Trading::TYPE_TRADED])->where('assetid', '>', 0);
        if($user = trim((string) $request->query('user'))) {
            $q->where('username', $user);
        }
        if($asset = (int) $request->query('asset')) {
            $q->where('assetid', $asset);
        }
        $page = max(1, (int) $request->query('page', 1));
        $pages = max(1, (int) ceil($q->count() / 50));
        $rows = $q->orderByDesc('id')->forPage(min($page, $pages), 50)->get();
        $titles = Asset::whereIn('id', $rows->pluck('assetid')->unique()->all())->pluck('title', 'id');
        $labels = [1 => 'Bought / owned', 2 => 'Sale', Trading::TYPE_TRADED => 'Traded away'];
        $log = $rows->map(fn($r) => [
            'id' => $r->id, 'date' => date('m/d/Y g:i A', strtotime($r->date)), 'username' => $r->username,
            'assetid' => $r->assetid, 'title' => $titles[$r->assetid] ?? '[deleted]', 'serial' => (int) $r->serial,
            'amount' => (float) $r->amount, 'type' => $labels[(int) $r->type] ?? $r->type,
        ])->all();
        return $this->toolPage('PurchaseLog', 'Item Purchase Log', ['log' => $log, 'filter' => ['user' => $user, 'asset' => $asset ?: ''],
            'pagination' => ['page' => min($page, $pages), 'pages' => $pages]]);
    }

    public function giveAsset(Request $request, string $mode) {
        $dependent = $mode === 'dependent';
        $title = $dependent ? 'Give Asset (Dependent)' : 'Give Asset (Non-dependent)';
        if($request->isMethod('post')) {
            $user = $this->findUser($request->input('user'));
            $asset = Asset::find((int) $request->input('asset'));
            if(!$user || !$asset) {
                return $this->back(!$user ? "That user doesn't exist." : "That asset doesn't exist.", false);
            }
            $kind = Trading::kind($asset);
            if(!$kind && Purchases::where('username', $user->username)->where('assetid', $asset->id)->where('type', 1)->exists()) {
                return $this->back($user->username . ' already owns ' . $asset->title . '.', false);
            }
            $error = null;
            $serial = 0;
            $this->db()->transaction(function() use ($user, $asset, $kind, $dependent, &$error, &$serial) {
                $serial = 0;
                if($dependent && $kind === 'unique') {
                    Asset::where('id', $asset->id)->lockForUpdate()->first();
                    $serial = (int) Purchases::where('assetid', $asset->id)->max('serial') + 1;
                    $stock = (int) ($asset->additional['stock'] ?? 0);
                    if($stock > 0 && $serial > $stock) {
                        $error = $asset->title . ' is out of stock.';
                        return;
                    }
                }
                Purchases::create(['username' => $user->username, 'assetid' => $asset->id, 'serial' => $serial,
                    'author' => $asset->author, 'amount' => 0, 'type' => 1]);
            });
            if($error) {
                return $this->back($error, false);
            }
            return $this->back('Gave ' . $asset->title . ($serial ? ' #' . $serial : '') . ' to ' . $user->username . '.');
        }
        return $this->toolPage('GiveAsset', $title, ['dependent' => $dependent]);
    }

    public function approvalLog(Request $request) {
        $rows = $this->db()->table('asset_approval_log')->orderByDesc('id')->limit(200)->get();
        $titles = Asset::whereIn('id', $rows->pluck('asset_id')->all())->pluck('title', 'id');
        $mods = User::whereIn('id', $rows->pluck('moderator_id')->filter()->all())->pluck('username', 'id');
        return $this->toolPage('ApprovalLog', 'Asset Approval Log', ['log' => $rows->map(fn($r) => [
            'date' => date('m/d/Y g:i A', strtotime($r->created_at)), 'asset_id' => $r->asset_id,
            'title' => $titles[$r->asset_id] ?? '[deleted]', 'action' => $r->action, 'moderator' => $mods[$r->moderator_id] ?? '?',
        ])->all()]);
    }

    public function playerListThumbs(Request $request) {
        if($request->isMethod('post') && ($user = $this->findUser($request->input('user')))) {
            \App\Jobs\RenderAvatar::dispatchAfterResponse($user->id);
            Cache::forget('pfp_' . $user->id);
            return $this->back('Re-rendering ' . $user->username . "'s thumbnail.");
        }
        $q = User::query();
        if($search = trim((string) $request->query('user'))) {
            $q->where('username', 'like', '%' . $search . '%');
        }
        $users = $q->orderByDesc('id')->limit(48)->get(['id', 'username', 'pfp'])->toArray();
        return $this->toolPage('PlayerListThumbs', 'Player List Thumbnails', ['users' => $users, 'search' => $search]);
    }

    public function poses(Request $request) {
        $db = $this->db();
        $previews = storage_path('app/pose-previews');

        if($request->isMethod('post')) {
            if((string) $request->input('action') === 'delete') {
                $id = (int) $request->input('id');
                $db->table('custom_poses')->where('id', $id)->delete();
                @unlink("{$previews}/{$id}.png");
                \App\Services\AvatarRenderer::forgetCustomPoses();
                return $this->back('Pose deleted. Anyone using it is back to Standing.');
            }

            $name = trim((string) $request->input('name'));
            $file = $request->file('model');
            if($name === '' || mb_strlen($name) > 50) {
                return $this->back('Give the pose a name (50 characters max).', false);
            }
            if($file && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return $this->back('That file is bigger than the server upload limit (' . ini_get('upload_max_filesize') . '). Raise upload_max_filesize and post_max_size in php.ini.', false);
            }
            if(!$file || !$file->isValid()) {
                return $this->back('Pick a .rbxm or .rbxmx file.', false);
            }
            if($file->getSize() > 20 * 1024 * 1024) {
                return $this->back('That file is too big (20 MB max).', false);
            }

            try {
                [$joints, $source] = \App\Services\PoseExtractor::fromFile(
                    (string) file_get_contents($file->getRealPath()),
                    trim((string) $request->input('animation')),
                    max(0, (int) $request->input('keyframe'))
                );
            } catch(\App\Services\ItemImport\ImportException $e) {
                return $this->back($e->getMessage(), false);
            } catch(\Throwable $e) {
                report($e);
                return $this->back("That file couldn't be read.", false);
            }

            $encoded = \App\Services\PoseExtractor::encode($joints);
            $id = $db->table('custom_poses')->insertGetId([
                'name' => $name,
                'joints' => $encoded,
                'source' => mb_substr($source, 0, 120),
                'created_by' => (int) Auth::id(),
                'created_at' => now(),
            ]);
            \App\Services\AvatarRenderer::forgetCustomPoses();

            $message = "Pose \"{$name}\" added from the {$source}. Everyone can pick it in character settings.";
            try {
                if(!is_dir($previews)) {
                    mkdir($previews, 0755, true);
                }
                file_put_contents("{$previews}/{$id}.png", \App\Services\AvatarRenderer::renderPosePreview(Auth::user(), $encoded));
            } catch(\Throwable $e) {
                report($e);
                $message .= " The preview render failed.";
            }

            return $this->back($message);
        }

        $poses = $db->table('custom_poses')->orderByDesc('id')->get()->map(function ($pose) use ($previews) {
            $preview = "{$previews}/{$pose->id}.png";
            return [
                'id' => $pose->id,
                'name' => $pose->name,
                'source' => $pose->source,
                'created_at' => $pose->created_at,
                'creator' => optional(User::find($pose->created_by))->username,
                'preview' => is_file($preview) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($preview)) : null,
            ];
        })->all();

        return $this->toolPage('Poses', 'Upload Pose', ['poses' => $poses]);
    }

    public function setRank(Request $request) {
        if($request->isMethod('post')) {
            $user = $this->findUser($request->input('user'));
            $rank = $request->input('rank');
            if(!$user) {
                return $this->back("That user doesn't exist.", false);
            }

            if(\App\Admin\Roles::scopes((string) $rank) !== null) {
                $scopes = \App\Admin\Roles::apply((int) $user->id, (string) $rank);
                return $this->back($user->username . ' is now ' . $rank . ' (' . implode(', ', $scopes) . ').');
            }

            if($rank !== 'admin' && $rank !== 'owner' && $rank !== 'member') {
                return $this->back('Unknown rank.', false);
            }
            if($rank === 'owner' && !\App\Admin\Access::owner(Auth::user())) {
                return $this->back('Only an owner can appoint an owner.', false);
            }
            if((int) $user->id === (int) Auth::id() && $rank !== 'admin') {
                return $this->back("You can't take away your own rank.", false);
            }

            $user->status = $rank === 'member' ? 'member' : 'admin';
            $user->save();
            return $this->back($user->username . ' is now ' . ucfirst($rank) . '.');
        }

        return $this->toolPage('SetRank', 'Set User Rank', [
            'admins' => User::where('status', 'admin')->orderBy('id')->pluck('username', 'id')->all(),
            'roles' => \App\Admin\Roles::names(),
            'can_owner' => \App\Admin\Access::owner(Auth::user()),
        ]);
    }

    public function clearCache(Request $request) {
        if($request->isMethod('post')) {
            $prefixes = ['user_count', 'latest_places', 'client_data', 'pfp_', 'username_', 'thumbnail_', 'ip_bans'];
            if(config('cache.default') === 'database') {
                $table = config('cache.stores.database.table', 'cache');
                $p = (string) config('cache.prefix');
                $q = DB::connection(config('cache.stores.database.connection') ?: null)->table($table);
                $q->where(function($w) use ($prefixes, $p) {
                    foreach($prefixes as $pre) {
                        $w->orWhere('key', 'like', $p . $pre . '%');
                    }
                });
                $n = $q->delete();
            } else {
                foreach(['user_count', 'latest_places', 'ip_bans'] as $k) {
                    Cache::forget($k);
                }
                $n = null;
            }
            return $this->back('Timed cache cleared' . ($n !== null ? " ({$n} entries)." : '.'));
        }
        return $this->toolPage('ClearCache', 'Clear Timed Cache');
    }

    public function forceUsername(Request $request) {
        if($request->isMethod('post')) {
            $user = $this->findUser($request->input('user'));
            $name = trim((string) $request->input('new_username'));
            if(!$user) {
                return $this->back("That user doesn't exist.", false);
            }
            if($problem = UsernameChange::forceProblem($user, $name)) {
                return $this->back($problem, false);
            }
            $old = $user->username;
            try {
                UsernameChange::force($user, $name);
            } catch(\RuntimeException $e) {
                return $this->back($e->getMessage(), false);
            }
            return $this->back($old . ' is now ' . $name . '.');
        }
        return $this->toolPage('ForceUsername', 'Force Username Change');
    }

    public function inviteKeys(Request $request) {
        $db = $this->db();

        if($request->isMethod('post')) {
            if((string) $request->input('action') === 'toggle_required') {
                $required = \App\Admin\Settings::get(\App\Admin\Settings::REQUIRE_INVITES, true);
                \App\Admin\Settings::put(\App\Admin\Settings::REQUIRE_INVITES, !$required);

                return $this->back(!$required
                    ? 'Invite keys are now optional. New accounts can register without one.'
                    : 'Invite keys are required again for registration.');
            }

            if((string) $request->input('action') === 'revoke') {
                $id = (int) $request->input('id');
                $key = $db->table('invitekeys')->where('id', $id)->first();
                if(!$key) {
                    return $this->back("That key doesn't exist.", false);
                }
                if(in_array((string) $key->used, ['1', 'y'], true)) {
                    return $this->back('That key has already been used.', false);
                }
                $db->table('invitekeys')->where('id', $id)->delete();
                return $this->back('Invite key revoked.');
            }

            $quantity = max(1, min(50, (int) $request->input('quantity', 1)));
            $keys = [];
            for($i = 0; $i < $quantity; $i++) {
                $key = strtoupper(\Illuminate\Support\Str::random(32));
                $db->table('invitekeys')->insert([
                    'author' => Auth::user()->username,
                    'IID' => $key,
                    'creation' => now(),
                    'used' => false,
                ]);
                $keys[] = $key;
            }
            return $this->back($quantity === 1 ? 'Invite key created: ' . $keys[0] : $quantity . ' invite keys created.');
        }

        $rows = $db->table('invitekeys')->orderByDesc('id')->limit(100)->get();
        $keys = $rows->map(fn($k) => [
            'id' => $k->id,
            'IID' => $k->IID,
            'author' => $k->author,
            'creation' => $k->creation ? date('m/d/Y g:i A', strtotime($k->creation)) : '',
            'used' => in_array((string) $k->used, ['1', 'y'], true),
            'usedBy' => $k->usedBy,
            'dateUsed' => $k->dateUsed ? date('m/d/Y', strtotime($k->dateUsed)) : null,
        ])->all();

        return $this->toolPage('InviteKeys', 'Create Invite Keys', [
            'keys' => $keys,
            'invitesEnabled' => (bool) config('admin.invites_enabled'),
            'requireInvites' => \App\Admin\Settings::get(\App\Admin\Settings::REQUIRE_INVITES, true),
            'invitesRequired' => \App\Admin\Settings::invitesRequired(),
        ]);
    }

    public function inviteTree(Request $request) {
        $keys = $this->db()->table('invitekeys')->where('used', true)->whereNotNull('usedBy')->get(['author', 'usedBy', 'dateUsed']);
        $names = User::pluck('username', 'id')->all();
        $ids = array_flip($names);
        $children = [];
        $invited = [];
        foreach($keys as $k) {
            $childId = ctype_digit((string) $k->usedBy) ? (int) $k->usedBy : ($ids[$k->usedBy] ?? null);
            if($childId === null) {
                continue;
            }
            $children[(int) $k->author][] = $childId;
            $invited[$childId] = true;
        }
        $flat = [];
        $seen = [];
        $walk = function($id, $depth) use (&$walk, &$flat, &$seen, $children, $names) {
            if(isset($seen[$id]) || $depth > 50) {
                return;
            }
            $seen[$id] = true;
            $flat[] = ['id' => $id, 'username' => $names[$id] ?? ('#' . $id), 'depth' => $depth, 'count' => count($children[$id] ?? [])];
            foreach($children[$id] ?? [] as $c) {
                $walk($c, $depth + 1);
            }
        };
        foreach(array_keys($children) as $root) {
            if(!isset($invited[$root])) {
                $walk($root, 0);
            }
        }
        return $this->toolPage('InviteTree', 'Invite Tree', ['tree' => $flat]);
    }
}
