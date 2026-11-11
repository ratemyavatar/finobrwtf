<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Asset;
use App\Models\Server;
use App\Http\Controllers\Asset as AssetHelper;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class GamesController extends Controller
{

    public function index() {
        $title = 'Places';

        if(!Auth::check()) {
            return redirect('/');
        }

        return $this->page('Places/Index', $pageData ?? [], $title ?? null);
    }

    public function place(Request $request, $id) {
        if(!Auth::check()) {
            return redirect('/');
        }

        if(!Asset::where('id', $id)->where('asset_type', 9)->exists()) {
            abort(404);
        }

        $place = Asset::find($id)->toArray();

        // no thumbnail yet: have the arbiter render one
        \App\Services\PlaceThumbnail::renderIfMissing((int) $place['id'], $place['additional']);

        $title = strip_tags(htmlspecialchars($place['title']));

        $place = [
            'id' => $place['id'],
            'additional' => $place['additional'],
            'title' => strip_tags(htmlspecialchars($place['title'])),
            'username' => User::where('id', $place['author'])->value('username'),
            'author' => $place['author'],
            'description' => nl2br(preg_replace('/\b((?:https?|ftp):\/\/\S+)/i', '<a href="$1">$1</a>', strip_tags(htmlspecialchars($place['description'])))),
            'created' => date('m/d/Y', strtotime($place['created'])),
            'updated' => date('m/d/Y', strtotime($place['updated'])),
            'visits' => number_format($place['additional']['visits']),
            'thumbnail' => \App\Services\PlaceThumbnail::url($place['additional']),
            'servers' => []
        ];

        $results = Server::where('placeid', $place)->get()->map(fn($item) => $item->toArray());

        foreach($results as $result) {
            $players = $result['players'];
            $result['players'] = [];

            foreach($players as $playerId) {
                if(User::find($playerId)) {
                    $result['players'][] = [
                        'userid' => $playerId,
                        'username' => User::where('id', $playerId)->value('username'),
                        'avatar' => Cache::remember('pfp_' . $playerId, 60 * 60, fn() => User::where('id', $playerId)->value('pfp'))
                    ];
                }
            }

            $place['servers'][] = $result;
        }

        $pageData['place'] = $place;

        return $this->page('Places/Place', $pageData ?? [], $title ?? null);
    }

    public function place_settings(Request $request, $id) {
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }

        if(!Asset::where('id', $id)->where('asset_type', 9)->exists()) {
            abort(404);
        }

        $place = Asset::find($id)->toArray();
        $place['username'] = User::where('id', $place['author'])->value('username');

        if($place['username'] != Auth::user()->username) {
            // the real site answered with its 403 "Error" page here
            return $this->forbidden();
        }

        // place file, custom thumbnail, badges and user restrictions
        if($request->isMethod('post') && isset($data['action'])) {
            return (new PlaceSettingsActions(Asset::find($id), Auth::user()->toArray()))->handle($request);
        }

        if($request->isMethod('post') && isset($data['regen_thumbnail'])) {
            try {
                \App\Services\PlaceThumbnail::render((int) $id);
                Session::put('successv2', 'Thumbnail regenerated.');
            } catch (\Throwable $e) {
                report($e);
                Session::put('error', 'Could not render the thumbnail right now. Please try again later.');
            }

            return redirect(\App\Support\Paths::placeEdit((int) $id, 'thumbnail'));
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title'            => 'required|string|min:3|max:255',
                'description'      => 'nullable|string|max:8192',
                'allowplaying'     => 'nullable|in:on,1,true,0,false,off',
                'downloadable'     => 'nullable|in:on,1,true,0,false,off',
                'hideRecent'       => 'nullable|in:on,1,true,0,false,off',
                'combat'           => 'nullable|in:on,1,true,0,false,off',
                'social'           => 'nullable|in:on,1,true,0,false,off',
                'building'         => 'nullable|in:on,1,true,0,false,off',
                'musical'          => 'nullable|in:on,1,true,0,false,off',
                'game-version'     => 'required|in:' . implode(',', \App\Services\ClientVersions::names()),
                'category'         => 'required|in:original,copy',
                'chat-type'        => 'required|in:classic,bubble_chat,both',
                'max-players'      => 'required|integer|between:5,100',
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect(\App\Support\Paths::placeEdit((int) $id));
            }

            $place['additional']['allowplaying'] = isset($data['allowplaying']);
            $place['additional']['uncopylocked'] = isset($data['downloadable']);
            $place['additional']['hidden'] = isset($data['hideRecent']);
            $place['additional']['gears']['combat'] = isset($data['combat']);
            $place['additional']['gears']['social'] = isset($data['social']);
            $place['additional']['gears']['building'] = isset($data['building']);
            $place['additional']['gears']['musical'] = isset($data['musical']);
            $place['additional']['version'] = $data['game-version'];
            $place['additional']['category'] = $data['category'];
            $place['additional']['chat_type'] = $data['chat-type'];
            $place['additional']['maxplayers'] = (int) $data['max-players'];

            $asset = Asset::find($id);
            $asset->title = $data['title'];
            $asset->description = $data['description'] ?? '';
            $asset->additional = $place['additional'];
            $asset->save();
            
            Session::put('successv2', 'Place settings saved.');
            return redirect(\App\Support\Paths::placeEdit((int) $id));
        }

        // normalise the on/off flags to real booleans: they can be true/false (JSON),
        // or legacy 'y'/'n', and the settings form's checkboxes must reflect that exactly
        foreach (['allowplaying', 'uncopylocked', 'hidden'] as $flag) {
            $place['additional'][$flag] = filter_var($place['additional'][$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $place['title'] = strip_tags(htmlspecialchars($place['title']));
        $place['thumbnail'] = \App\Services\PlaceThumbnail::url($place['additional']);
        $place['custom_thumbnail'] = !empty($place['additional']['media']['customThumbnail']);
        $place['pending_thumbnail'] = !empty($place['additional']['media']['pendingThumbnail'])
            && Asset::where('id', $place['additional']['media']['pendingThumbnail'])->where('visibility', 'r')->exists();

        $placeFile = config('finobe.cdn_path') . '/assets/' . basename((string) $place['file']);
        $place['file_size'] = is_file($placeFile) ? round(filesize($placeFile) / 1024) . ' KB' : null;

        $place['badges'] = PlaceSettingsActions::badgesFor((int) $place['id'])->map(fn($b) => [
            'id' => $b->id,
            'name' => $b->title,
            'description' => $b->description,
            'status' => ['n' => 'approved', 'r' => 'waiting for review', 'd' => 'denied'][$b->visibility] ?? $b->visibility,
            'icon' => $b->visibility === 'n' ? ($b->additional['media']['thumbnail'] ?? '') : '/dynamic/reviewing/' . $b->file,
            'awarded' => \App\Models\PlaceBadgeAward::where('badge_id', $b->id)->count(),
        ])->all();

        $restrictions = \App\Services\PlaceAccess::restrictions($place['additional']);
        $restrictions['blocked_names'] = implode(', ', User::whereIn('id', $restrictions['blocked'])->pluck('username')->all());
        $place['restrictions'] = $restrictions;
        //$place['description'] = nl2br(preg_replace('/\b((?:https?|ftp):\/\/\S+)/i', '<a href="$1" target="_blank">$1</a>', strip_tags(htmlspecialchars($place['description']))));

        $pageData['place'] = $place;
        $title = $place['title'];

        return $this->page('Places/Place_settings', $pageData ?? [], $title ?? null);
    }

    public function place_new(Request $request) {
        $title = 'New Place';
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title' => 'required|string|min:3|max:255',
                'description' => 'nullable|string|max:8192',
                'game-version' => 'nullable|in:' . implode(',', \App\Services\ClientVersions::names())
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/games/new');
            }

            $version = $data['game-version'] ?? \App\Services\ClientVersions::DEFAULT;

            $games = Asset::where('author', Auth::user()->id)
                ->where('asset_type', 9)
                ->count();
            
            if(!config('finobe.features.create_places')) {
                Session::put('error', 'Creating assets is currently disabled');
                return redirect('/games/new');
            }

            if($games >= Auth::user()->slots) {
                Session::put('error', 'You have used all of your place slots');
                return redirect('/games/new');
            }

            /*
            $id = $this->db->table('assets')->insertGetId([
                'asset_type' => 9,
                'title' => trim($data['title']),
                'description' => trim($data['description'] ?? ''),
                'additional' => json_encode([
                    'visits' => 0,
                    'version' => '2012',
                    'maxplayers' => 15,
                    'category' => 'original',
                    'featured' => false,
                    'gears' => [
                        'combat' => true,
                        'social' => true,
                        'building' => true,
                        'musical' => true
                    ],
                    'uncopylocked' => false,
                    'allowplaying' => true,
                    'chat_type' => 'classic',
                    'media' => [
                        'imageAssetId' => 1
                    ],
                    'hidden' => false
                ])
            ]);
            */
            // each client version starts from its own baseplate: storage/rbx/files/places/<version>.rbxl
            // (2012 uses the 2016 baseplate unless a dedicated 2012.rbxl is added)
            $template = storage_path("rbx/files/places/{$version}.rbxl");
            if (!is_readable($template)) {
                $template = storage_path('rbx/files/places/2016.rbxl');
            }
            $defaultPlace = is_readable($template) ? @file_get_contents($template) : false;

            if ($defaultPlace === false) {
                Session::put('error', 'Place creation is not set up on this server (baseplate file missing)');
                return redirect('/games/new');
            }

            $id = AssetHelper::createAsset(trim($data['title']), 9, Auth::user()->id, $defaultPlace, trim($data['description'] ?? ''), "n", [
                'visits' => 0,
                'version' => $version,
                'maxplayers' => 15,
                'category' => 'original',
                'featured' => false,
                'gears' => [
                    'combat' => true,
                    'social' => true,
                    'building' => true,
                    'musical' => true
                ],
                'uncopylocked' => false,
                'allowplaying' => true,
                'chat_type' => 'classic',
                'media' => [
                    'imageAssetId' => 0
                ],
                'hidden' => false
            ]);

            // render the new place's thumbnail through the arbiter once the response is sent
            \App\Jobs\RenderPlaceThumbnail::dispatchAfterResponse((int) $id);

            return redirect('/place/' . $id);
        }

        return $this->page('Places/New', $pageData ?? [], $title ?? null);
    }
}