<?php

namespace App\Http\Controllers\Web;

use App\Models\Ban;
use App\Models\User;
use App\Models\Asset;
use App\Models\Server;
use App\Models\Warning;
use App\Models\Election;
use App\Models\Purchases;
use App\Models\Announcement;
use App\Models\Configuration;
use App\Models\Forum\Reply;
use App\Models\Forum\Thread;
use App\Http\Controllers\Asset as AssetHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ItemImport\AssetCopier;
use App\Services\ItemImport\ImportException;
use App\Services\ItemImport\ItemImporter;
use App\Services\ItemImport\MeshConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{

    /* Which scope each tool needs. Admins hold every scope, so they keep full access. */
    private const TOOL_SCOPES = [
        'assets' => 'Catalog', 'accept' => 'Catalog', 'deny' => 'Catalog',
        'createxml' => 'Items', 'rbxcreatexml' => 'Items',
        'bans' => 'Moderation', 'warn' => 'Moderation', 'decider' => 'Moderation', 'elections' => 'Moderation',
        'prune_posts' => 'Forum',
        'lock' => 'Forum', 'unlock' => 'Forum', 'pin' => 'Forum', 'unpin' => 'Forum', 'stick' => 'Forum', 'unstick' => 'Forum',
        'give_dius' => 'Economy', 'give_badges' => 'Economy',
        'announcements' => 'Announcements',
        'servers' => 'Operations', 'changeversions' => 'Operations', 'deployClient' => 'Operations',
    ];

    public function __construct(Request $request) {
        \App\Admin\Access::authorize($request->user());
        if(!Auth::check()) {
            abort(404);
        }

        $method = $request->route() ? $request->route()->getActionMethod() : null;
        $scope = self::TOOL_SCOPES[$method] ?? null;
        if($scope) {
            \App\Admin\Access::authorize($request->user(), ['group' => $scope, 'kind' => 'inspect']);
        } elseif($method !== 'index') {
            abort(404);
        }
    }

    /* Admin Panel > Asset Approval Log */
    private function logApproval($assetId, string $action): void {
        if(!$assetId) {
            return;
        }
        try {
            \Illuminate\Support\Facades\DB::connection('finobe')->table('asset_approval_log')->insert([
                'asset_id' => $assetId, 'moderator_id' => Auth::user()->id, 'action' => $action, 'created_at' => now(),
            ]);
        } catch(\Throwable $e) {
            report($e); // not migrated yet: approving still works
        }
    }

    public function index(Request $request) {
        $title = 'Admin Panel';

        $scopes = \App\Admin\Access::scopes($request->user());
        $tools = [
            ['url' => '/admin/ipbans', 'label' => 'IP Bans', 'icon' => 'gavel', 'scope' => 'Access restrictions'],
            ['url' => '/admin/users', 'label' => 'Users', 'icon' => 'user', 'scope' => 'Accounts'],
            ['url' => '/admin/assets', 'label' => 'Assets', 'icon' => 'tshirt', 'scope' => 'Catalog'],
            ['url' => '/admin/bans', 'label' => 'Bans', 'icon' => 'ban', 'scope' => 'Moderation'],
            ['url' => '/admin/decider', 'label' => 'Oui/Non Decider', 'icon' => 'question', 'scope' => 'Moderation'],
            ['url' => '/admin/force-regen', 'label' => 'Force Regen Character', 'icon' => 'sync', 'scope' => 'Avatar'],
            ['url' => '/admin/prune-posts', 'label' => 'Prune Forum Posts', 'icon' => 'trash', 'scope' => 'Forum'],
            ['url' => '/admin/purge-games', 'label' => 'Purge Inactive Game Servers', 'icon' => 'minus-octagon', 'scope' => 'Operations'],
            ['url' => '/admin/createxml', 'label' => 'New XML Asset', 'icon' => 'layer-plus', 'scope' => 'Items'],
            ['url' => '/admin/rbxcreatexml', 'label' => 'Create XML Asset (Roblox)', 'image' => '/s/img/roblox-r.png', 'scope' => 'Items'],
            ['url' => '/admin/purchase-log', 'label' => 'Item Purchase Log', 'icon' => 'shopping-cart', 'scope' => 'Economy'],
            ['url' => '/admin/give_dius', 'label' => 'Give Dius', 'icon' => 'donate', 'scope' => 'Economy'],
            ['url' => '/admin/give-asset/non-dependent', 'label' => 'Give Asset (Non-dependent)', 'icon' => 'plus-octagon', 'scope' => 'Economy'],
            ['url' => '/admin/give-asset/dependent', 'label' => 'Give Asset (Dependent)', 'icon' => 'cart-plus', 'scope' => 'Economy'],
            ['url' => '/admin/approval-log', 'label' => 'Asset Approval Log', 'icon' => 'book', 'scope' => 'Catalog'],
            ['url' => '/admin/player-thumbnails', 'label' => 'Player List Thumbnails', 'icon' => 'image', 'scope' => 'Avatar'],
        ];
        $links = [
            ['url' => '/admin/rank', 'label' => 'Set User Rank', 'scope' => 'Staff'],
            ['url' => '/admin/users', 'label' => 'Modify User Permissions', 'scope' => 'Accounts'],
            ['url' => '/admin/clear-cache', 'label' => 'Clear Timed Cache', 'scope' => 'Operations'],
            ['url' => '/admin/force-username', 'label' => 'Force Username Change', 'scope' => 'Accounts'],
            ['url' => '/admin/invite-tree', 'label' => 'Invite Tree', 'scope' => 'Invites'],
            ['url' => '/admin/give_badges', 'label' => 'Give Badges', 'scope' => 'Economy'],
            ['url' => '/admin/warn', 'label' => 'Give Warning', 'scope' => 'Moderation'],
            ['url' => '/admin/announcements', 'label' => 'Site Announcements', 'scope' => 'Announcements'],
            ['url' => '/admin/servers', 'label' => 'Game Servers', 'scope' => 'Operations'],
            ['url' => '/admin/changeversions', 'label' => 'Client Deployer', 'scope' => 'Operations'],
            ['url' => '/admin/elections', 'label' => 'Elections', 'scope' => 'Moderation'],
            ['url' => '/admin/blog', 'label' => 'Blog Management', 'scope' => 'Blog'],
            ['url' => '/admin/invite-keys', 'label' => 'Create Invite Keys (temp)', 'scope' => 'Invites'],
        ];
        $allowed = fn(array $i) => in_array($i['scope'], $scopes, true);
        $pageData['tools'] = array_values(array_filter($tools, $allowed));
        $pageData['links'] = array_values(array_filter($links, $allowed));

        return $this->page('Admin/Index', $pageData ?? [], $title ?? null);
    }

    public function assets(Request $request) {
        $title = 'Asset Moderation';

        $assets = [];
        $results = Asset::where('visibility', 'r')
            ->where(fn($q) => $q->where('asset_type', '!=', 1)->orWhere('additional', 'like', '%placeThumbnailFor%'))
            ->orderBy('id', 'DESC')
            ->get()
            ->map(fn($item) => $item->toArray());
        
        foreach($results as $result) {
            $result['title'] = strip_tags(htmlspecialchars($result['title']));
            $result['author'] = htmlspecialchars(User::where('id', $result['author'])->value('username'));
            $result['publish'] = date('m/d/Y', strtotime($result['created']));
            $result['kind'] = $result['asset_type'] == 21 ? 'badge' : (!empty($result['additional']['placeThumbnailFor']) ? 'game_thumbnail' : null);
            $assets[] = $result;
        }

        $pageData['assets'] = $assets;

        return $this->page('Admin/Assets', $pageData ?? [], $title ?? null);
    }

    public function accept(Request $request) {
        foreach ([public_path('dynamic/reviewing'), public_path('dynamic/denied'), public_path('dynamic/temp'), config('finobe.cdn_path') . '/assets', config('finobe.cdn_path') . '/audios'] as $dir) {
            AssetHelper::ensureDirectory($dir);
        }

        $data = $request->all();

        if(!isset($data['id']) || !Asset::find($data['id'])) {
            Session::put('error', 'error');
            return redirect('/admin/assets');
        }

        $asset = Asset::find($data['id']);

        if($asset->visibility == 'r' && ($asset->asset_type == 21 || ($asset->asset_type == 1 && !empty($asset->additional['placeThumbnailFor'])))) {
            return $this->moderatePlaceImage($asset, true);
        }

        if($asset->visibility == 'r') {
            if($asset['asset_type'] == 3) {
                if(!rename(public_path('dynamic/reviewing/' . $asset->file), config('finobe.cdn_path') . '/audios/' . $asset->file)) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }
            } elseif(in_array($asset->asset_type, [2, 11, 12, 18])) {
                $texture = Asset::find($asset->additional['media']['textureAssetId']);
                $texture->visibility = 'n';
                $texture->save();
            }
        } elseif($asset->visibility == 'd') {
            if($asset->asset_type == 3) {
                if(!rename(public_path('dynamic/denied/' . $asset->file), config('finobe.cdn_path') . '/audios/' . $asset->file)) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }
            } elseif($asset->asset_type == 8 && Asset::find(intval($data['id']) - 2) && Asset::find(intval($data['id']) - 1)) {
                $texture = Asset::find(intval($data['id']) - 2);
                $xml = Asset::find(intval($data['id']) - 1);

                if(!rename(public_path('dynamic/denied/' . $asset->file), config('finobe.cdn_path') . '/assets/' . $asset->file) || !rename(public_path('dynamic/denied/' . $texture->file), config('finobe.cdn_path') . '/assets/' . $texture->file) || !rename(public_path('dynamic/denied/' . $xml->file), config('finobe.cdn_path') . '/assets/' . $xml->file)) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }

                $texture->visibility = 'n';
                $xml->visibility = 'n';

                $texture->save();
                $xml->save();
            } elseif(in_array($asset->asset_type, [2, 11, 12, 18])) {
                $texture = Asset::find($asset->additional['media']['textureAssetId']);
                $texture->visibility = 'n';
                $texture->save();
            }
        }

        $asset->visibility = 'n';
        $asset->save();
        
        // the creator owns their item (type 1 = owned, which is what the inventory and avatar editor look for)
        AssetHelper::grantToCreator((int) $data['id'], (int) $asset->author);
        
        $this->logApproval($asset->id ?? null, 'approved');
        Session::put('success', 'Item accepted.');
        return redirect('/admin/assets');
    }

    public function deny(Request $request) {
        foreach ([public_path('dynamic/reviewing'), public_path('dynamic/denied'), public_path('dynamic/temp'), config('finobe.cdn_path') . '/assets', config('finobe.cdn_path') . '/audios'] as $dir) {
            AssetHelper::ensureDirectory($dir);
        }

        $data = $request->all();

        if(!isset($data['id']) || !Asset::find($data['id'])) {
            Session::put('error', 'error');
            return redirect('/admin/assets');
        }

        $asset = Asset::find($data['id']);

        if($asset->visibility == 'r' && ($asset->asset_type == 21 || ($asset->asset_type == 1 && !empty($asset->additional['placeThumbnailFor'])))) {
            return $this->moderatePlaceImage($asset, false);
        }

        if($asset->visibility == 'r') {
            if($asset->asset_type == 3) {
                if(!rename(public_path('dynamic/reviewing/' . $asset->file), public_path('dynamic/denied/' . $asset->file))) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }
            } elseif(in_array($asset->asset_type, [2, 11, 12, 18])) {
                if(!rename(config('finobe.cdn_path') . '/assets/' . $asset->file, public_path('dynamic/denied/' . $asset->file))) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }
            }
        } elseif($asset->visibility == 'n') {
            if(!rename(config('finobe.cdn_path') . '/' . ($asset->asset_type == 3 ? 'audios' : 'assets') . '/' . $asset->file, public_path('dynamic/denied/' . $asset->file))) {
                Session::put('error', error_get_last()['message']);
                return redirect('/admin/assets');
            }

            if($asset->asset_type == 8) {
                $texture = Asset::find(intval($data['id']) - 2);
                $xml = Asset::find(intval($data['id']) - 1);

                if(!rename(config('finobe.cdn_path') . '/assets/' . $texture->file, public_path('dynamic/denied/' . $texture->file)) || !rename(config('finobe.cdn_path') . '/assets/' . $xml->file, public_path('dynamic/denied/' . $xml->file))) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }

                $texture->visibility = 'd';
                $xml->visibility = 'd';

                $texture->save();
                $xml->save();
            } elseif(in_array($asset->asset_type, [12, 11, 2])) {
                $texture = Asset::find(intval($data['id']) - 1);

                if(!rename(config('finobe.cdn_path') . '/assets/' . $texture->file, public_path('dynamic/denied/' . $texture->file))) {
                    Session::put('error', error_get_last()['message']);
                    return redirect('/admin/assets');
                }

                $texture->visibility = 'd';
                $texture->save();
            }
        }

        $asset->visibility = 'd';
        $asset->save();
        
        $this->logApproval($asset->id ?? null, 'denied');
        Session::put('success', 'Item denied.');
        return redirect('/admin/assets');
    }

    /*
        moderatePlaceImage
         - approve/deny an uploaded game thumbnail (asset_type 1 + placeThumbnailFor) or a game badge icon (asset_type 21)
    */
    private function moderatePlaceImage(Asset $asset, bool $approve)
    {
        $reviewing = public_path('dynamic/reviewing/' . basename($asset->file));

        if(!$approve) {
            if(is_file($reviewing)) {
                @rename($reviewing, public_path('dynamic/denied/' . basename($asset->file)));
            }

            $asset->visibility = 'd';
            $asset->save();

            if($asset->asset_type == 1) {
                $this->clearPendingThumbnail($asset);
            }

            $this->logApproval($asset->id ?? null, 'denied');
            Session::put('success', 'Item denied.');
            return redirect('/admin/assets');
        }

        $thumbnails = AssetHelper::ensureDirectory(config('finobe.cdn_path') . '/thumbnails');

        if(!is_file($reviewing) || !@rename($reviewing, $thumbnails . '/' . basename($asset->file))) {
            Session::put('error', 'Could not move the image into the CDN thumbnails folder.');
            return redirect('/admin/assets');
        }

        $asset->visibility = 'n';

        if($asset->asset_type == 21) {
            $additional = $asset->additional;
            $additional['media']['thumbnail'] = 'https://cdn.' . env('FINOBE_DOMAIN', 'finobe.wtf') . '/thumbnails/' . basename($asset->file);
            $asset->additional = $additional;
            $asset->save();

            $this->logApproval($asset->id ?? null, 'approved');
            Session::put('success', 'Badge accepted.');
            return redirect('/admin/assets');
        }

        $asset->save();

        // make it the game's thumbnail
        $place = Asset::where('id', (int) $asset->additional['placeThumbnailFor'])->where('asset_type', 9)->first();

        if($place) {
            $additional = $place->additional;
            $old = $additional['media']['thumbnail'] ?? null;
            $additional['media']['thumbnail'] = 'thumbnails/' . basename($asset->file);
            $additional['media']['customThumbnail'] = true;
            unset($additional['media']['pendingThumbnail']);
            $place->additional = $additional;
            $place->save();

            if($old && str_starts_with($old, 'thumbnails/') && basename($old) !== basename($asset->file)) {
                @unlink($thumbnails . '/' . basename($old));
            }
        }

        $this->logApproval($asset->id ?? null, 'approved');
        Session::put('success', 'Thumbnail accepted.');
        return redirect('/admin/assets');
    }

    private function clearPendingThumbnail(Asset $asset): void
    {
        $place = Asset::where('id', (int) ($asset->additional['placeThumbnailFor'] ?? 0))->where('asset_type', 9)->first();

        if($place && (int) ($place->additional['media']['pendingThumbnail'] ?? 0) === (int) $asset->id) {
            $additional = $place->additional;
            unset($additional['media']['pendingThumbnail']);
            $place->additional = $additional;
            $place->save();
        }
    }

    public function bans(Request $request) {
        $title = 'User Moderation';
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'name' => [
                    'required',
                    function ($attribute, $value, $fail) {
                        $isIp = filter_var($value, FILTER_VALIDATE_IP);
                        $isUsername = is_string($value) && strlen($value) >= 3 && strlen($value) <= 255;

                        if (!$isIp && !$isUsername) {
                            $fail('The ' . $attribute . ' must be a valid IP address or a username between 3 and 255 characters.');
                        }
                    }
                ],
                'reason' => 'required|string|min:3|max:255',
                'type' => 'required|string|in:n,y|size:1',
                'date' => 'nullable|date',
                'time' => 'nullable|date_format:H:i'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/bans');
            }

            if(!filter_var($data['name'], FILTER_VALIDATE_IP) && !User::where('username', $data['name'])->exists()) {
                Session::put('error', 'User doesn\' exist.');
                return redirect('/admin/bans');
            }

            if(filter_var($data['name'], FILTER_VALIDATE_IP)) {
                $data['name'] = hash_hmac('sha256', $data['name'], 'ip');

                if(Ban::where('username', $data['name'])->exists()) {
                    Session::put('error', 'This user already has an active ban');
                    return redirect('/admin/bans');
                }
            }

            if(Ban::where('username', $data['name'])
                ->where(function ($query) {
                    $query->where('expire', '>', now())
                        ->orWhere('perm', 'y');
                })->exists()) {
                Session::put('error', 'This user already has an active ban');
                return redirect('/admin/bans');
            }

            if($data['type'] == 'n') {
                $expire = $data['date'] . ' ' . $data['time'];
                $timezone = new \DateTimeZone('America/Los_Angeles');
                $dateTime = new \DateTime($expire, $timezone);
                $expire = $dateTime->format('Y-m-d H:i:s');

                Ban::create([
                    'username' => $data['name'],
                    'reason' => $data['reason'],
                    'expire' => $expire,
                    'moderator' => Auth::user()->username,
                    'perm' => 'n'
                ]);
            } else {
                Ban::create([
                    'username' => $data['name'],
                    'reason' => $data['reason'],
                    'moderator' => Auth::user()->username,
                    'perm' => 'y'
                ]);
            }

            Session::put('success', 'Successfully created.');
            return redirect('/admin/bans');
        }

        return $this->page('Admin/Ban', $pageData ?? [], $title ?? null);
    }

    public function decider(Request $request) {
        $title = 'Decider';

        return $this->page('Admin/Decider', $pageData ?? [], $title ?? null);
    }

    public function prune_posts(Request $request) {
        $title = 'Prune Forum Posts';
        $data = $request->all();

        if($request->isMethod('post')) {
            if(!empty($data['id']) || !empty($data['replyid'])) {
                $id = !empty($data['id']) && empty($data['replyid']) ? $data['id'] : $data['replyid'];
                $table = !empty($data['id']) && empty($data['replyid']) ? 'forum_threads' : 'forum_replies';
                
                if (!empty($data['id']) && empty($data['replyid'])) {
                    $exists = Thread::where('id', $id)->exists();
                } else {
                    $exists = Reply::where('id', $id)->exists();
                }

                if(!$exists) {
                    Session::put('error', 'Forum post or reply doesn\'t exist.');
                    return redirect('/admin/prune-posts');
                }

                if($table == 'forum_threads') {
                    $results = Reply::select('id')
                        ->where('toid', $id)
                        ->get()
                        ->map(fn($item) => $item->toArray());
                    
                    foreach($results as $result) {
                        Reply::where('id', $result['id'])->delete();
                    }

                    Thread::where('id', $id)->delete();
                } else {
                    Reply::where('id', $id)->delete();
                }

                Session::put('success', 'Successfully deleted.');
                return redirect('/admin/prune-posts');
            } elseif(!empty($data['username'])) {
                if(!User::where('username', $data['username'])->exists()) {
                    Session::put('error', 'User does not exist.');
                    return redirect('/admin/prune-posts');
                }

                $threads = Thread::where('author', $data['username'])->get()->map(fn($item) => $item->toArray());
                
                foreach($threads as $thread) {
                    Thread::where('id', $thread['id'])->delete();
                }

                $replies = Reply::where('author', $data['username'])->get()->map(fn($item) => $item->toArray());
                
                foreach($replies as $reply) {
                    Reply::where('id', $reply['id'])->delete();
                }

                Session::put('success!', 'Deleted successfully');
                return redirect('/admin/prune-posts');
            }

            Session::put('error', 'Unknown error.');
            return redirect('/admin/prune-posts');
        }

        return $this->page('Admin/Prune_posts', $pageData ?? [], $title ?? null);
    }

    public function announcements(Request $request) {
        $title = 'Announcements';
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'message' => 'required|string|min:3|max:255',
                'date' => 'required|date',
                'time' => 'required|date_format:H:i',
                'color' => 'required|string|in:success,primary,danger,info,warning'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/announcements');
            }

            $expire = $data['date'] . ' ' . $data['time'];
            $timezone = new \DateTimeZone('America/Los_Angeles');
            $dateTime = new \DateTime($expire, $timezone);
            $expire = $dateTime->format('Y-m-d H:i:s');

            Announcement::create([
                'author' => Auth::user()->username,
                'message' => $data['message'],
                'expire' => $expire,
                'color' => $data['color']
            ]);

            Session::put('success', 'Successfully created.');
            return redirect('/admin/announcements');
        }

        $html = [
            'time' => 'Time: ' . date('Y-m-d H:i:s'),
            'data' => ''
        ];

        $page = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $perPage = 10;

        $paginator = Announcement::orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);

        $results = $paginator->toArray();

        if (count($results['data'] ?? [])) {
            $html['data'] = "<table border='1' style=\"width:100%;\"><tr>";

            foreach (array_keys((array) $results['data'][0]) as $key) {
                $html['data'] .= "<th>" . htmlspecialchars($key) . "</th>";
            }

            $html['data'] .= "</tr>";

            foreach ($results['data'] as $row) {
                $html['data'] .= "<tr>";
                foreach ((array) $row as $key => $value) {
                    if ($key === 'username') {
                        $html['data'] .= "<td><a href=\"/user/" . htmlspecialchars($value) . "\" target=\"_blank\">" . htmlspecialchars($value) . "</a></td>";
                    } else {
                        $html['data'] .= "<td>" . htmlspecialchars($value) . "</td>";
                    }
                }
                $html['data'] .= "</tr>";
            }

            $html['data'] .= "</table><div>";

            for ($pageNum = 1; $pageNum <= $paginator->lastPage(); $pageNum++) {
                $html['data'] .= "<a href='?page={$pageNum}'" . ($pageNum == $page ? " style='font-weight: bold'" : "") . ">{$pageNum}</a> ";
            }

            $html['data'] .= "</div>";
        } else {
            $html['data'] = "0 results";
        }

        $pageData['announcements'] = $html;

        return $this->page('Admin/Announcements', $pageData ?? [], $title ?? null);
    }

    public function lock(Request $request) {
        $data = $request->all();

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!Thread::find($data['id'])) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $thread = Thread::find($data['id']);
        $thread->locked = 'y';
        $thread->save();
        
        Session::put('success', 'Successfully locked');
        return redirect('/forum/post?id=' . $data['id']);
    }

    public function unlock(Request $request) {
        $data = $request->all();

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!Thread::find($data['id'])) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $thread = Thread::find($data['id']);
        $thread->locked = 'n';
        $thread->save();
        
        Session::put('success', 'Successfully unlocked');
        return redirect('/forum/post?id=' . $data['id']);
    }

    public function pin(Request $request) {
        $data = $request->all();

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!Thread::find($data['id'])) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $thread = Thread::find($data['id']);
        $thread->pinned = 'y';
        $thread->save();
        
        Session::put('success', 'Successfully pinned');
        return redirect('/forum/post?id=' . $data['id']);
    }

    public function unpin(Request $request) {
        $data = $request->all();

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!Thread::find($data['id'])) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $thread = Thread::find($data['id']);
        $thread->pinned = 'n';
        $thread->save();
        
        Session::put('success', 'Successfully unpinned');
        return redirect('/forum/post?id=' . $data['id']);
    }

    public function stick(Request $request) {
        $data = $request->all();

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!Reply::find($data['id'])) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $reply = Reply::find($data['id']);

        if($reply->sticked = 'y') {
            Session::put('error', 'A sticked reply already exists');
            return redirect('/forum/home');
        }

        $reply->sticked = 'y';
        $reply->save();
        
        Session::put('success', 'Successfully sticked');
        return redirect('/forum/post?id=' . $reply->toid);
    }

    public function unstick(Request $request) {
        $data = $request->all();

        if(!isset($data['id'])) {
            return redirect('/forum/home');
        }

        if(!Reply::find($data['id'])) {
            Session::put('error', 'This thread doesn\'t exist');
            return redirect('/forum/home');
        }

        $reply = Reply::find($data['id']);
        $reply->sticked = 'n';
        $reply->save();
        
        Session::put('success', 'Successfully sticked');
        return redirect('/forum/post?id=' . $reply->toid);
    }

    public function createxml(Request $request) {
        AssetHelper::ensureDirectory(config('finobe.cdn_path') . '/assets');

        $title = 'Create item';
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title' => 'required|string|min:3|max:255',
                'description' => 'nullable|string|max:8192',
                'price' => 'required|integer|min:0',
                'onsale' => 'nullable|in:on,1,true,0,false,off',
                'type' => 'required|string|in:hat,gear,face,shirt,pants,tshirt,mesh,texture',
                'rbxm' => 'nullable|file|max:20480',
                'image' => 'nullable|file|max:10240',
                'mesh' => 'nullable|file|max:20480',
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/createxml');
            }

            $type = $data['type'];
            $author = (int) Auth::user()->id;
            $title = $data['title'];
            $description = $data['description'] ?? '';

            $field = match ($type) {
                'hat', 'gear' => 'rbxm',
                'mesh' => 'mesh',
                default => 'image',
            };
            if(!$request->hasFile($field)) {
                Session::put('error', match ($field) {
                    'rbxm' => 'Pick the .rbxm (or .rbxmx) file of the item.',
                    'mesh' => 'Pick the mesh file.',
                    default => 'Pick the image (.png).',
                });
                return redirect('/admin/createxml');
            }
            $file = file_get_contents($request->file($field)->getPathname());

            try {
                if(in_array($type, ['hat', 'gear'])) {
                    $id = ItemImporter::import($file, $title, $author, $description, intval($data['price']), isset($data['onsale']),
                        ['hat' => ItemImporter::HAT, 'gear' => ItemImporter::GEAR][$type]);
                } elseif(in_array($type, ['face', 'shirt', 'pants', 'tshirt'])) {
                    $id = AssetCopier::fromImage($file, $type, $title, $author, $description, intval($data['price']), isset($data['onsale']));
                } elseif($type === 'mesh') {
                    $id = AssetHelper::createAsset($title, 4, $author, MeshConverter::toLegacy($file), '', 'n', []);
                    Session::put('success', 'Mesh uploaded (http://www.' . env('FINOBE_DOMAIN', 'finobe.wtf') . '/asset/?id=' . $id . ')');
                    return redirect('/admin/createxml');
                } else {
                    $id = AssetHelper::createAsset($title, 1, $author, $file, '', 'n', [], true);
                    Session::put('success', 'Texture uploaded (http://www.' . env('FINOBE_DOMAIN', 'finobe.wtf') . '/asset/?id=' . $id . ')');
                    return redirect('/admin/createxml');
                }
            } catch(ImportException $e) {
                Session::put('error', $e->getMessage());
                return redirect('/admin/createxml');
            } catch(\Throwable $e) {
                report($e);
                Session::put('error', 'Something went wrong while creating the item.');
                return redirect('/admin/createxml');
            }

            Session::put('success', 'Success');
            return redirect('/item/' . $id);
        }

        return $this->page('Admin/CreateXML', $pageData ?? [], $title ?? null);
    }

    public function give_dius(Request $request) {
        $title = 'Reward Dius';
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'name' => 'required|string|max:255',
                'amount' => isset($data['toggler']) ? 'nullable|numeric' : 'required|numeric',
                'toggler' => 'nullable'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/give_dius');
            }

            if(isset($data['toggler'])) {
                if(!User::where('username', $data['name'])->exists()) {
                    Session::put('error', 'User does not exist');
                    return redirect('/admin/give_dius');
                }

                User::where('username', $data['name'])
                    ->update([
                        'diubanned' => DB::raw("CASE WHEN diubanned = 'n' THEN 'y' ELSE 'n' END")
                    ]);
                
                $user = User::where('username', $data['name'])->select('diubanned')->first();

                if($user->diubanned == 'y') {
                    Session::put('success', 'Successfully diu banned.');
                } else {
                    Session::put('success', 'Successfully diu unbanned.');
                }

                return redirect('/admin/give_dius');
            }

            if($data['name'] == '*') {
                $amount = intval($data['amount']);
                User::query()->increment('Dius', $amount);

                $insertData = User::all()->map(function ($user) use ($amount) {
                    return [
                        'username' => $user->username,
                        'assetid' => 0,
                        'serial' => 0,
                        'author' => 0,
                        'amount' => $amount,
                        'type' => 4,
                    ];
                })->toArray();

                Purchases::insert($insertData);
            } else {
                if(!User::where('username', $data['name'])->exists()) {
                    Session::put('error', 'User does not exist');
                    return redirect('/admin/give_dius');
                }

                $user = User::where('username', $data['name'])->first();
                $user->Dius += intval($data['amount']);
                $user->save();

                Purchases::create([
                    'username' => $user->username,
                    'assetid' => 0,
                    'serial' => 0,
                    'author' => 0,
                    'amount' => intval($data['amount']),
                    'type' => 4
                ]);
            }

            Session::put('success', 'Successfully given dius.');
            return redirect('/admin/give_dius');
        }

        return $this->page('Admin/Dius', $pageData ?? [], $title ?? null);
    }

    public function warn(Request $request) {
        $title = 'Warn Users';
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'name' => 'required|string|min:3|max:255',
                'reason' => 'required|string|min:3|max:255'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/warn');
            }

            if(!User::where('username', $data['name'])->exists()) {
                Session::put('error', 'User does not exist');
                return redirect('/admin/warn');
            }

            Warning::create([
                'username' => $data['name'],
                'reason' => $data['reason'],
                'moderator' => Auth::user()->username
            ]);

            Session::put('success', 'Successfully created.');
            return redirect('/admin/warn');
        }

        return $this->page('Admin/Warn', $pageData ?? [], $title ?? null);
    }

    public function give_badges(Request $request) {
        $title = 'Give Badges';
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'username' => 'required|string|min:3|max:255',
                'message' => 'required|string|min:3|max:255',
                'color' => 'required|string|in:success,primary,danger,info,warning'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/give_badges');
            }

            if(!User::where('username', $data['username'])->exists()) {
                Session::put('error', 'User does not exist');
                return redirect('/admin/give_badges');
            }

            $user = User::where('username', $data['username'])->first();
            $badges = $user->badges;
            $badges['data']['custom_badges'][] = [
                'message' => $data['message'],
                'color' => $data['color']
            ];

            $user->badges = $badges;
            $user->save();

            Session::put('success', 'Successfully created.');
            return redirect('/admin/give_badges');
        }

        return $this->page('Admin/Badges', $pageData ?? [], $title ?? null);
    }

    public function elections(Request $request) {
        $title = 'Elections';
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title' => 'required|string|min:3|max:255',
                'css' => 'nullable|string|max:8192',
                'expire' => 'required|date',
                'time' => 'required|date_format:H:i',
                'options' => 'required|array'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/elections');
            }

            if(Election::where('expire', '>', now())->exists()) {
                Session::put('error', 'There is an election already active');
                return redirect('/admin/elections');
            }

            $id = 1;

            if(!is_array($data['options'])) {
                $options = json_decode($data['options'], true);
            } else {
                $options = $data['options'];
            }

            foreach($options as $key => $option) {
                $options[$key]['id'] = $id;
                $id++;
            }

            $timezone = new \DateTimeZone('America/Los_Angeles');
            $dateTime = new \DateTime($data['expire'] . ' ' . $data['time'], $timezone);
            $expire = $dateTime->format('Y-m-d H:i:s');

            Election::create([
                'title' => $data['title'],
                'author' => Auth::user()->username,
                'css' => $data['css'] ?? '',
                'options' => $options,
                'expire' => $expire,
                'votes' => '[]'
            ]);

            Session::put('success', 'Successfully created.');
            return redirect('/admin/elections');
        }

        return $this->page('Admin/Elections', $pageData ?? [], $title ?? null);
    }

    public function servers(Request $request) {
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'type' => 'required|integer|min:1|max:3'
            ]);

            if($validator->fails()) {
                return redirect('/admin/servers');
            }

            if($data['type'] == 1) {
                $validator = Validator::make($data, [
                    'ip' => 'required|string',
                    'port' => 'required|integer',
                    'placeid' => 'required|integer',
                    'jobId' => 'required|string',
                    'status' => 'required|integer'
                ]);

                if($validator->fails()) {
                    return redirect('/admin/servers');
                }

                Server::create([
                    'ip' => $data['id'],
                    'port' => $data['port'],
                    'placeid' => $data['placeid'],
                    'jobId' => $data['jobId'],
                    'status' => $data['status']
                ]);

                return redirect('/admin/servers');
            } elseif($data['type'] == 2) {
                Server::truncate();

                return redirect('/admin/servers');
            } elseif($data['type'] == 3) {
                $validator = Validator::make($data, [
                    'jobId' => 'required|string'
                ]);

                if($validator->fails()) {
                    return redirect('/admin/servers');
                }

                Server::where('jobId', $data['jobId'])->delete();

                return redirect('/admin/servers');
            }

            return redirect('/admin/servers');
        }

        $pageData['servers'] = Server::all()->toArray();

        return $this->page('Admin/Servers', $pageData ?? [], $title ?? null);
    }

    public function rbxcreatexml(Request $request) {
        AssetHelper::ensureDirectory(config('finobe.cdn_path') . '/assets');

        $title = 'Copy a Roblox item';
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'link' => 'required|string|max:512',
                'title' => 'nullable|string|min:3|max:255',
                'description' => 'nullable|string|max:8192',
                'price' => 'required|integer|min:0',
                'onsale' => 'nullable|in:on,1,true,0,false,off'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/rbxcreatexml');
            }

            try {
                $copied = AssetCopier::copy($data['link'], (int) Auth::user()->id, intval($data['price']), isset($data['onsale']),
                    ($data['title'] ?? '') !== '' ? $data['title'] : null,
                    ($data['description'] ?? '') !== '' ? $data['description'] : null);
            } catch(ImportException $e) {
                Session::put('error', $e->getMessage());
                return redirect('/admin/rbxcreatexml');
            } catch(\Throwable $e) {
                report($e);
                Session::put('error', 'Something went wrong while copying the item.');
                return redirect('/admin/rbxcreatexml');
            }

            Session::put('success', 'Copied "' . $copied['name'] . '"');
            return redirect('/item/' . $copied['id']);
        }

        return $this->page('Admin/RBXCreateXML', $pageData ?? [], $title ?? null);
    }

    public function deployClient(Request $request)
    {
        $title = 'Change client version';
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'application' => 'required|regex:/^\d+\.\d+\.\d+pcplayer$/',
                'md5' => 'required|regex:/^[a-f0-9]{32}$/i',
                'version' => 'required|regex:/^version-[a-f0-9]{16}$/',
                'libraries' => 'required|file|mimetypes:application/zip,application/x-zip-compressed,multipart/x-zip',
                'librariesqt4' => 'required|file|mimetypes:application/zip,application/x-zip-compressed,multipart/x-zip',
                'executable' => 'required|file|mimetypes:application/zip,application/x-zip-compressed,multipart/x-zip',
                'redist' => 'required|file|mimetypes:application/zip,application/x-zip-compressed,multipart/x-zip',
                'content' => 'required|file|mimetypes:application/zip,application/x-zip-compressed,multipart/x-zip',
                'contenttextures3' => 'required|file|mimetypes:application/zip,application/x-zip-compressed,multipart/x-zip',
                'contentterrain' => 'required|file|mimetypes:application/zip,application/x-zip-compressed,multipart/x-zip',
                'shaders' => 'required|file|mimetypes:application/zip,application/x-zip-compressed,multipart/x-zip',
                'builtinplugins' => 'required|file|mimetypes:application/zip,application/x-zip-compressed,multipart/x-zip',
                'publiclauncher' => 'required|file|mimetypes:application/x-msdownload,application/x-msdos-program,application/x-exe'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/changeversions');
            }

            $libraries = $request->file('libraries');
            $librariesqt4 = $request->file('librariesqt4');
            $executable = $request->file('executable');
            $redist = $request->file('redist');
            $content = $request->file('content');
            $contenttextures3 = $request->file('contenttextures3');
            $contentterrain = $request->file('contentterrain');
            $shaders = $request->file('shaders');
            $builtinplugins = $request->file('builtinplugins');
            $publiclauncher = $request->file('publiclauncher');

            $libraries->storeAs('private/setup', $data['version'] . '-Libraries.zip');
            $librariesqt4->storeAs('private/setup', $data['version'] . '-LibrariesQT4.zip');
            $executable->storeAs('private/setup', $data['version'] . '-Executable.zip');
            $redist->storeAs('private/setup', $data['version'] . '-redist.zip');
            $content->storeAs('private/setup', $data['version'] . '-content.zip');
            $contenttextures3->storeAs('private/setup', $data['version'] . '-content-textures3.zip');
            $contentterrain->storeAs('private/setup', $data['version'] . '-content-terrain.zip');
            $shaders->storeAs('private/setup', $data['version'] . '-shaders.zip');
            $builtinplugins->storeAs('private/setup', $data['version'] . '-BuiltInPlugins.zip');
            $publiclauncher->storeAs('private/setup', $data['version'] . '-PublicLauncher.exe');

            if(!Configuration::where('type', 'client-version')->exists()) {
                Configuration::create([
                    'type' => 'client-version',
                    'data' => $data['version']
                ]);
            } else {
                Configuration::where('type', 'client-version')->update([
                    'data' => $data['version']
                ]);
            }

            Cache::forget('client_data');

            Session::put('success', 'Successfully changed');
            return redirect('/admin/changeversions');
        }

        $json = Cache::remember('client_data', now()->addHours(12), function() {
            return [
                'application' => Configuration::where('type', 'client-application')->value('data'),
                'md5' => Configuration::where('type', 'client-md5')->value('data'),
                'version' => Configuration::where('type', 'client-version')->value('data')
            ];
        });

        $pageData['application'] = $json['application'];
        $pageData['md5'] = $json['md5'];
        $pageData['version'] = $json['version'];

        return $this->page('Admin/Changeversions', $pageData ?? [], $title ?? null);
    }
    public function changeversions(Request $request) {
        $title = 'Change client version';
        $data = $request->all();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'application' => 'required|regex:/^\d+\.\d+\.\d+pcplayer$/',
                'md5' => 'required|regex:/^[a-f0-9]{32}$/i',
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/admin/changeversions');
            }

            if(!Configuration::where('type', 'client-application')->exists()) {
                Configuration::create([
                    'type' => 'client-application',
                    'data' => $data['application']
                ]);
            } else {
                Configuration::where('type', 'client-application')->update([
                    'data' => $data['application']
                ]);
            }

            if(!Configuration::where('type', 'client-md5')->exists()) {
                Configuration::create([
                    'type' => 'client-md5',
                    'data' => $data['md5']
                ]);
            } else {
                Configuration::where('type', 'client-md5')->update([
                    'data' => $data['md5']
                ]);
            }
            Cache::forget('client_data');

            Session::put('success', 'Successfully changed');
            return redirect('/admin/changeversions');
        }

        $json = Cache::remember('client_data', now()->addHours(12), function() {
            return [
                'application' => Configuration::where('type', 'client-application')->value('data'),
                'md5' => Configuration::where('type', 'client-md5')->value('data'),
                'version' => Configuration::where('type', 'client-version')->value('data')
            ];
        });

        $pageData['application'] = $json['application'];
        $pageData['md5'] = $json['md5'];
        $pageData['version'] = $json['version'];

        return $this->page('Admin/Changeversions', $pageData ?? [], $title ?? null);
    }
}
