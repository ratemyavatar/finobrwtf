<?php

namespace App\Http\Controllers\Web;

use Carbon\Carbon;
use FFMpeg\Format\Audio\Mp3;
use FFMpeg\FFMpeg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Models\User;
use App\Models\Asset;
use App\Models\Video;
use App\Models\Purchases;
use App\Jobs\ProcessVideo;
use App\Http\Controllers\Asset as AssetHelper;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Services\WordFilter;
use App\Support\Format;
use App\Services\Discord;

class CatalogController extends Controller
{

    public function index(Request $request, $section) {
        $title = 'Catalog';
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }

        $sections = [
            "hats" => 8,
            "t-shirts" => 2,
            "shirts" => 11,
            "pants" => 12,
            "gears" => 19,
            "faces" => 18,
            "heads" => 17,
            "packages" => 32,
            "audio" => 3,
            "models" => 10
        ];

        if(!isset($sections[$section])) {
            return redirect('/catalog/hats');
        }

        $items = [];
        $pages_to_show = 10;
        $results_per_page = 12;

        // the Filters box, like the real catalog: sort, price range and tradables
        $filters = [
            'sort' => in_array($data['sort'] ?? '', ['price_asc', 'price_desc'], true) ? $data['sort'] : 'unsorted',
            'min' => isset($data['min']) && $data['min'] !== '' ? max(0, (int) $data['min']) : null,
            'max' => isset($data['max']) && $data['max'] !== '' ? max(0, (int) $data['max']) : null,
            'tradables' => in_array($data['tradables'] ?? '', ['hide', 'only'], true) ? $data['tradables'] : 'all',
        ];
        // "Price sorting sorts using the original price for limiteds and uniques."
        $price = DB::connection('finobe')->getDriverName() === 'sqlite'
            ? "CAST(json_extract(additional, '$.price') AS INTEGER)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(additional, '$.price')) AS SIGNED)";

        // approved, and on sale or tradable (items without an onSale flag, like old audio, count as on sale)
        $listed = function () use ($data, $sections, $section, $filters, $price) {
            $query = Asset::where('asset_type', $sections[$section])
                ->where('visibility', 'n')
                ->where(fn($q) => $q->whereNull('additional->onSale')->orWhere('additional->onSale', true)->orWhere('additional->isLimited', true));
            if (isset($data['q'])) {
                $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%' . htmlspecialchars($data['q']) . '%']);
            }
            if ($filters['tradables'] === 'only') {
                $query->where('additional->isLimited', true);
            } elseif ($filters['tradables'] === 'hide') {
                $query->where(fn($q) => $q->whereNull('additional->isLimited')->orWhere('additional->isLimited', false));
            }
            if ($filters['min'] !== null) {
                $query->whereRaw("COALESCE($price, 0) >= ?", [$filters['min']]);
            }
            if ($filters['max'] !== null) {
                $query->whereRaw("COALESCE($price, 0) <= ?", [$filters['max']]);
            }
            if ($filters['sort'] !== 'unsorted') {
                $query->orderByRaw("COALESCE($price, 0) " . ($filters['sort'] === 'price_asc' ? 'ASC' : 'DESC'));
            }
            return $query->orderBy('id', 'DESC');
        };

        $results = $listed()->count();

        $number_of_pages = ceil($results / $results_per_page);
        $currentPage = isset($data['page']) ? max(1, intval($data['page'])) : 1;
        $offset = ($currentPage - 1) * $results_per_page;
        $start_page = max(1, min($currentPage - floor($pages_to_show / 2), $number_of_pages - $pages_to_show + 1));
        $end_page = min($number_of_pages, $start_page + $pages_to_show - 1);

        $results = $listed()
            ->offset($offset)
            ->limit($results_per_page)
            ->get()
            ->map(fn($item) => $item->toArray());

        foreach($results as $result) {
            $result['title'] = htmlspecialchars(WordFilter::apply($result['title']));

            if($result['asset_type'] == 3) {
                $result['duration'] = Format::duration($result['additional']['duration']);
            }

            $user = User::find($result['author']);
            
            $result['uuid'] = $user ? $user->toArray()['id'] : false;
            $result['author'] = htmlspecialchars($user['username'] ?? $result['additional']['oldUser']);
            $items[] = $result;
        }

        if($sections[$section] != 3 && count($results) && count($items) < 12) {
            $missing = $results_per_page - count($items);
            for ($i = 0; $i < $missing; $i++) {
                $items[] = [];
            }
        }

        $pageData['items'] = [
            'data' => [],
            'pages' => [
                'info' => [
                    'current_page' => $currentPage,
                    'previous_page' => max(1, $currentPage - 1),
                    'next_page' => min($number_of_pages, $currentPage + 1),
                    'start_page' => $start_page,
                    'end_page' => $end_page,
                    'number_of_pages' => $number_of_pages
                ],
                'data' =>[]
            ]
        ];

        for ($page = $start_page; $page <= $end_page; $page++) {
            $pageData['items']['pages']['data'][] = ['page' => $page];
        }

        if(!count($items)) {
            $pageData['items']['pages']['data'][] = [
                'page' => 1
            ];
        }

        $pageData['items']['data'] = $items;
        $pageData['section'] = $section;
        $pageData['search'] = isset($data['q']) ? $data['q'] : false;
        $pageData['filters'] = $filters;
        // search + filters carried over by the page links
        $keep = array_filter([
            'q' => $data['q'] ?? null,
            'sort' => $filters['sort'] !== 'unsorted' ? $filters['sort'] : null,
            'min' => $filters['min'], 'max' => $filters['max'],
            'tradables' => $filters['tradables'] !== 'all' ? $filters['tradables'] : null,
        ], fn($v) => $v !== null && $v !== '');
        $pageData['qs'] = $keep ? '&' . http_build_query($keep) : '';

        return $this->page('Catalog/Index', $pageData ?? [], $title ?? null);
    }

    public function item(Request $request, $id) {
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }

        if(!Asset::find($id)) {
            abort(404);
        }

        $item = Asset::find($id)->toArray();
        
        if($item['asset_type'] == 9) {
            return redirect('/place/' . $id);
        }

        if($item['asset_type'] == 1) {
            abort(404);
        }

        $item['title'] = htmlspecialchars(WordFilter::apply($item['title']));
        
        if(User::find($item['author'])) {
            $item['uuid'] = $item['author'];
        }

        $item['author'] = htmlspecialchars(User::where('id', $item['author'])->value('username') ?? $item['additional']['oldUser']);
        $item['description'] = nl2br(preg_replace('/\b((?:https?|ftp):\/\/\S+)/i', '<a href="$1">$1</a>', strip_tags(htmlspecialchars($item['description']))));
        $item['publish'] = date('m/d/Y', strtotime($item['created']));
	    $item['updated'] = date('m/d/Y', strtotime($item['updated']));

        if($item['asset_type'] == 3) {
            $item['thumbnail'] = Cache::remember('thumbnail_' . $item['additional']['media']['imageAssetId'], 60 * 60, fn() => Asset::where('id', $item['additional']['media']['imageAssetId'])->value('file'));
        }

        $item['isOwned'] = Purchases::where('username', Auth::user()->username)->where('assetid', $id)->where('type', 1)->exists();
        $item['sales'] = number_format(Purchases::where('assetid', $id)->count());

        // a package has no thumbnail of its own; show what it contains on the item page
        $item['contents'] = [];
        if((int) $item['asset_type'] === 32) {
            $item['contents'] = Asset::whereIn('id', AssetHelper::getPackageAssets((int) $id))
                ->get(['id', 'title', 'asset_type'])
                ->map(fn($a) => [
                    'id' => (int) $a->id,
                    'title' => htmlspecialchars($a->title),
                    'asset_type' => (int) $a->asset_type,
                ])
                ->all();
        }

        if($item['visibility'] == 'd') {
            $item['title'] = "[Not Approved]";
            $item['description'] = "[Not Approved]";
        }

        $title = $item['title'];
        $pageData['item'] = $item;

        return $this->page('Catalog/Item', $pageData ?? [], $title ?? null);
    }

    /*
        POST /item/{id}/delete
         - admins can delete any catalog item, everyone else only items they uploaded
         - removes the item, the mesh/texture assets made for it, everyone's copy of it and it from outfits
    */
    public function delete(Request $request, $id) {
        if(!Auth::check()) {
            return redirect('/');
        }

        $asset = Asset::find($id);
        if(!$asset || !in_array($asset->asset_type, [2, 3, 8, 11, 12, 17, 18, 19, 32])) {
            abort(404);
        }

        $user = Auth::user()->toArray();
        $isAdmin = ($user['status'] ?? '') === 'admin';
        if(!$isAdmin && (int) $asset->author !== (int) $user['id']) {
            Session::put('error', 'You can only delete items you uploaded.');
            return redirect('/item/' . $id);
        }

        $ids = [(int) $asset->id];
        $additional = is_array($asset->additional) ? $asset->additional : [];

        // texture made for a face / shirt / pants / t-shirt
        if(!empty($additional['media']['textureAssetId'])) {
            $ids[] = (int) $additional['media']['textureAssetId'];
        }

        // meshes / textures a hat, gear or head points at, if they were uploaded with it (same author, mesh/image)
        if(in_array($asset->asset_type, [8, 17, 19])) {
            $file = config('finobe.cdn_path') . '/assets/' . $asset->file;
            $domain = preg_quote(env('FINOBE_DOMAIN', 'finobe.wtf'), '#');
            if(is_file($file) && preg_match_all('#https?://(?:www\.)?' . $domain . '/asset/\?id=(\d+)#i', (string) file_get_contents($file), $m)) {
                $ids = array_merge($ids, Asset::whereIn('id', array_map('intval', $m[1]))
                    ->where('author', $asset->author)->whereIn('asset_type', [1, 4])->pluck('id')->all());
            }
        }

        $ids = array_values(array_unique($ids));
        foreach(Asset::whereIn('id', $ids)->get() as $a) {
            foreach([config('finobe.cdn_path') . '/assets/', config('finobe.cdn_path') . '/audios/', public_path('dynamic/reviewing/'), public_path('dynamic/denied/')] as $dir) {
                if($a->file && is_file($dir . basename($a->file))) {
                    @unlink($dir . basename($a->file));
                }
            }
        }

        // take it off anyone wearing it
        $wearing = User::where('avatar', 'like', '%' . (int) $asset->id . '%')->get();
        foreach($wearing as $wearer) {
            $avatar = $wearer->avatar;
            $changed = false;
            foreach(['equippedGearVersionIds', 'backpackGearVersionIds'] as $key) {
                if(isset($avatar[0][$key]) && in_array((int) $asset->id, array_map('intval', $avatar[0][$key]), true)) {
                    $avatar[0][$key] = array_values(array_filter($avatar[0][$key], fn($x) => (int) $x !== (int) $asset->id));
                    $changed = true;
                }
            }
            if($changed) {
                $wearer->avatar = $avatar;
                $wearer->save();
                \App\Jobs\RenderAvatar::dispatchAfterResponse($wearer->id);
            }
        }

        \App\Models\Purchases::whereIn('assetid', $ids)->delete();
        DB::connection('finobe')->table('asset_package')->whereIn('package_asset_id', $ids)->delete();
        Asset::whereIn('id', $ids)->delete();

        Session::put('success', 'Item deleted.');
        $sections = [8 => 'hats', 2 => 't-shirts', 11 => 'shirts', 12 => 'pants', 19 => 'gears', 18 => 'faces', 17 => 'heads', 32 => 'packages', 3 => 'audio'];
        return redirect('/catalog/' . ($sections[$asset->asset_type] ?? 'hats'));
    }

    public function settings(Request $request, $id) {
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }

        if(!Asset::find($id)) {
            abort(404);
        }

        $item = Asset::find($id)->toArray();
        
        $isAdmin = (Auth::user()->status ?? '') === 'admin';
        if($item['author'] != Auth::user()->id && !$isAdmin) {
            Session::put('error', 'You do not own this item');
            return redirect('/item/' . $id);
        }

        if(!in_array($item['asset_type'], [2, 3, 8, 11, 12, 17, 18, 19, 32])) {
            abort(404);
        }

        if($item['visibility'] != 'n') {
            Session::put('error', 'This item is currently unavailable to changes');
            return redirect('/item/' . $id);
        }

        $item['description'] = strip_tags(htmlspecialchars($item['description']));

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'title'            => 'required|string|min:3|max:255',
                'description'      => 'nullable|string|max:8192',
                'onsale'           => 'nullable|in:on,1,true,0,false,off',
                'price'            => 'required|integer|min:0'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/item/' . $id . '/settings');
            }

            $item['additional']['onSale'] = isset($data['onsale']);
            $item['additional']['price'] = intval($data['price']);

            // only admins decide what's Limited / Unique (tradable) and what it's worth
            if($isAdmin && isset($data['limited'])) {
                $kind = in_array($data['limited'], ['none', 'limited', 'unique'], true) ? $data['limited'] : 'none';
                $item['additional']['isLimited'] = $kind !== 'none';
                $item['additional']['isUnique'] = $kind === 'unique';
                $item['additional']['stock'] = max(0, (int) ($data['stock'] ?? 0));
                $item['additional']['value'] = ($data['value'] ?? '') === '' ? null : max(0, (int) $data['value']);
            }

            $item2 = Asset::find($id);
            $item2->title = $data['title'];
            $item2->description = $data['description'] ?? '';
            $item2->additional = $item['additional'];
            $item2->save();

            Session::put('successv2', 'Item settings saved.');
            return redirect('/item/' . $id . '/settings');
        }

        $title = $item['title'];
        $pageData['item'] = $item;
        $pageData['isAdmin'] = $isAdmin;

        return $this->page('Catalog/Item_settings', $pageData ?? [], $title ?? null);
    }

    public function new(Request $request) {
        $title = 'New Asset';
        $data = $request->all();

        if(!Auth::check()) {
            return redirect('/');
        }
        
        if($request->isMethod('post')) {
            if(!(bool)env('FINOBE_CREATE_ASSETS')) {
                Session::put('Creating assets is currently disabled');
                return redirect('/catalog/new');
            }

            $assetTypes = [
                'hats' => 8,
                't-shirts' => 2,
                'shirt' => 11,
                'pants' => 12,
                'gears' => 19,
                'faces' => 18,
                'heads' => 17,
                'packages' => 32,
                'audio' => 3,
                'model' => 10
            ];

            $validator = Validator::make($data, [
                'title' => 'required|string|min:3|max:255',
                'description' => 'nullable|string|max:8192',
                'media-type' => 'required|string',
                'price' => 'required|integer|min:0'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/catalog/new');
            }

            if(Auth::user()->Dius - 5 < 0) {
                Session::put('error', 'Not enough dius');
                return redirect('/catalog/new');
            }

            if($data['media-type'] == 'video') {
                if(Auth::user()->status != 'admin') {
                    return redirect('/catalog/new');
                }

                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:video/mp4,video/x-msvideo,video/x-ms-wmv,video/x-ms-asf,video/quicktime,video/webm|max:102400'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }

                $filename = uniqid();
                $thumbnail = $filename . '.jpg';
                $filename .= '.webm';

                $file = $request->file('file')->store('videos');

                /*
                $ffmpeg = FFmpeg::create();
                $video = $ffmpeg->open(storage_path('app/private/' . $file));
                $format = new X264('aac', 'libx264');
                //$format->setAdditionalParameters(['-movflags', '+faststart']);
                $video->save($format, config('finobe.cdn_path') . '/videos/data/' . $filename);
                $video->frame(TimeCode::fromSeconds(1))
                    ->save(config('finobe.cdn_path') . '/videos/thumbs/' . $thumbnail);
                */

                Redis::set("video_processing:{$filename}", true);
                ProcessVideo::dispatch($file, $filename, $thumbnail);

                /*
                if(empty(trim(shell_exec("ps aux | grep 'php artisan queue:work' | grep -v grep")))) {
                    exec('cd /var/www/Finobe && php artisan queue:work --timeout=21600 --sleep=3 --tries=3 > /dev/null 2>&1 &');
                }
                */
                
                Video::create([
                    'title' => $data['title'],
                    'author' => Auth::user()->username,
                    'filename' => $filename,
                    'thumbnail' => $thumbnail,
                    'description' => $data['description'] ?? ''
                ]);

                $user = User::find(Auth::user()->id);
                $user->Dius -= 5;
                $user->save();

                Purchases::create([
                    'username' => $user->username,
                    'assetid' => 0,
                    'serial' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                Session::put('success', 'Video is processing, the video will automatically publish when finished processing.');
                return redirect('/videos');
            } elseif($data['media-type'] == 'audio') {
                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:audio/mpeg,audio/ogg,audio/midi,audio/x-midi,audio/wav,audio/x-wav|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }

                if(Auth::user()->Dius - 5 < 0) {
                    Session::put('error', 'Not enough dius');
                    return redirect('/catalog/new');
                }

                $filename = uniqid();
                $ffmpeg = FFmpeg::create();
                AssetHelper::ensureDirectory(public_path('dynamic/temp'));
                AssetHelper::ensureDirectory(public_path('dynamic/reviewing'));

                try {
                    $file = $request->file('file');
                    if(in_array($file->getMimeType(), ['audio/midi', 'audio/x-midi'])) {
                        $file->move(public_path('dynamic/temp/'), $filename);
                        $input = public_path('dynamic/temp/' . $filename);
                        $output = public_path('dynamic/temp/' . $filename . '.mp3');
                        exec("timidity $input -Ow -o - | ffmpeg -i - -codec:a libmp3lame -b:a 96k $output");
                        $audio = $ffmpeg->open($output);
                        $duration = $audio->getFormat()->get('duration');
                    } else {
                        $file->move(public_path('dynamic/temp/'), $filename);
                        $audio = $ffmpeg->open(public_path('dynamic/temp/' . $filename));
                        $duration = $audio->getFormat()->get('duration');
                        $format = new Mp3();
                        $format->setAudioKiloBitrate(96);
                        $audio->save($format, public_path('dynamic/temp/' . $filename . '.mp3'));
                    }
                    
                    rename(public_path('dynamic/temp/' . $filename . '.mp3'), public_path('dynamic/reviewing/' . $filename)); //ffmpeg is fucking me in the ass without the .mp3 extention
                } catch(ProcessFailedException $e) {
                    Session::put('error', $e->getProcess()->getErrorOutput());
                    return redirect('/catalog/new');
                }

                $audio = Asset::create([
                    'asset_type' => $assetTypes[$data['media-type']],
                    'title' => $data['title'],
                    'author' => Auth::user()->id,
                    'file' => $filename,
                    'description' => $data['description'] ?? '',
                    'visibility' => 'r',
                    'additional' => [
                        'duration' => $duration,
                        'price' => intval($data['price']),
                        'media' => [
                            'imageAssetId' => 2
                        ],
                        'oldUser' => '',
                        'onSale' => true
                    ]
                ]);

                $user = User::find(Auth::user()->id);
                $user->Dius -= 5;
                $user->save();

                Purchases::create([
                    'username' => $user->username,
                    'assetid' => 0,
                    'serial' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                Discord::send('<@541523977475194880>, ' . Auth::user()->username . ' uploaded an item, moderate it! [ https://finobe.wtf/admin/assets ]');

                return redirect('/item/' . $audio->id);
            } elseif($data['media-type'] == 'shirt') {
                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:image/png,image/jpeg|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }


                if(Auth::user()->Dius - 5 < 0) {
                    Session::put('error', 'Not enough dius');
                    return redirect('/catalog/new');
                }

                $image = getimagesize($request->file('file')->getPathname());

                if(abs(($image[0] / $image[1]) - (585 / 559)) > 0.01) {
                    Session::put('error', 'Image is not correct ratio (585x559)');
                    return redirect('/catalog/new');
                }

                $user = User::find(Auth::user()->id);

                try {
                    $id = AssetHelper::createAccessory(
                        $data['title'],
                        ['tmp_name' => $request->file('file')->getPathname()],
                        $user->id,
                        $data['description'] ?? '',
                        intval($data['price']),
                        isset($data['onsale']),
                        false,
                        'shirt'
                    );
                } catch(\Exception $e) {
                    Session::put('error', $e->getMessage());
                    return redirect('/catalog/new');
                }

                $user->Dius -= 5;
                $user->save();

                Purchases::create([
                    'username' => $user->username,
                    'assetid' => 0,
                    'serial' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                Discord::send('<@541523977475194880>, ' . Auth::user()->username . ' uploaded an item, moderate it! [ https://finobe.wtf/admin/assets ]');

                Session::put('success', 'Success');
                return redirect('/item/' . $id);
            } elseif($data['media-type'] == 'pants') {
                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:image/png,image/jpeg|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }


                if(Auth::user()->Dius - 5 < 0) {
                    Session::put('error', 'Not enough dius');
                    return redirect('/catalog/new');
                }

                $image = getimagesize($request->file('file')->getPathname());

                if(abs(($image[0] / $image[1]) - (585 / 559)) > 0.01) {
                    Session::put('error', 'Image is not correct ratio (585x559)');
                    return redirect('/catalog/new');
                }

                $user = User::find(Auth::user()->id);

                try {
                    $id = AssetHelper::createAccessory(
                        $data['title'],
                        ['tmp_name' => $request->file('file')->getPathname()],
                        $user->id,
                        $data['description'] ?? '',
                        intval($data['price']),
                        isset($data['onsale']),
                        false,
                        'pants'
                    );
                } catch(\Exception $e) {
                    Session::put('error', $e->getMessage());
                    return redirect('/catalog/new');
                }

                $user->Dius -= 5;
                $user->save();

                Purchases::create([
                    'username' => $user->username,
                    'assetid' => 0,
                    'serial' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                Discord::send('<@541523977475194880>, ' . Auth::user()->username . ' uploaded an item, moderate it! [ https://finobe.wtf/admin/assets ]');

                Session::put('success', 'Success');
                return redirect('/item/' . $id);
            } elseif($data['media-type'] == 'faces') {
                if(Auth::user()->status != 'admin') {
                    Session::put('error', 'Admin required');
                    return redirect('/catalog/new');
                }

                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:image/png,image/jpeg|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }

                if(Auth::user()->Dius - 5 < 0) {
                    Session::put('error', 'Not enough dius');
                    return redirect('/catalog/new');
                }

                $image = getimagesize($request->file('file')->getPathname());

                if(abs(($image[0] / $image[1]) - (256 / 256)) > 0.01) {
                    Session::put('error', 'Image is not correct ratio (256x256)');
                    return redirect('/catalog/new');
                }

                $user = User::find(Auth::user()->id);

                try {
                    $id = AssetHelper::createAccessory(
                        $data['title'],
                        ['tmp_name' => $request->file('file')->getPathname()],
                        $user->id,
                        $data['description'] ?? '',
                        intval($data['price']),
                        isset($data['onsale']),
                        false,
                        'face'
                    );
                } catch(\Exception $e) {
                    Session::put('error', $e->getMessage());
                    return redirect('/catalog/new');
                }

                $user->Dius -= 5;
                $user->save();

                Purchases::create([
                    'username' => $user->username,
                    'assetid' => 0,
                    'serial' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                Discord::send('<@541523977475194880>, ' . Auth::user()->username . ' uploaded an item, moderate it! [ https://finobe.wtf/admin/assets ]');

                Session::put('success', 'Success');
                return redirect('/item/' . $id);
            } elseif($data['media-type'] == 't-shirts') {
                $validator = Validator::make($data, [
                    'file' => 'required|file|mimetypes:image/png,image/jpeg|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/catalog/new');
                }


                if(Auth::user()->Dius - 5 < 0) {
                    Session::put('error', 'Not enough dius');
                    return redirect('/catalog/new');
                }

                $user = User::find(Auth::user()->id);

                try {
                    $id = AssetHelper::createAccessory(
                        $data['title'],
                        ['tmp_name' => $request->file('file')->getPathname()],
                        $user->id,
                        $data['description'] ?? '',
                        intval($data['price']),
                        isset($data['onsale']),
                        false,
                        'tshirt'
                    );
                } catch(\Exception $e) {
                    Session::put('error', $e->getMessage());
                    return redirect('/catalog/new');
                }

                $user->Dius -= 5;
                $user->save();

                Purchases::create([
                    'username' => $user->username,
                    'assetid' => 0,
                    'serial' => 0,
                    'author' => 0,
                    'amount' => -5,
                    'type' => 5
                ]);

                Discord::send('<@541523977475194880>, ' . Auth::user()->username . ' uploaded an item, moderate it! [ https://finobe.wtf/admin/assets ]');

                Session::put('success', 'Success');
                return redirect('/item/' . $id);
            }

            return redirect('/catalog/new');
        }

        return $this->page('Catalog/New', $pageData ?? [], $title ?? null);
    }
}