<?php

namespace App\Http\Controllers\Web;

use App\Models\Purchases;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Asset;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class AvatarController extends Controller
{

    public function index() {
        $title = 'Character';

        if(!Auth::check()) {
            return redirect('/');
        }

        $purchases = [];
        $results = Purchases::where('username', Auth::user()->username)
            ->where('type', 1)
            ->orderBy('id', 'DESC')
            ->get()
            ->map(fn($item) => $item->toArray());
        
        foreach($results as $result) {
            if(!Asset::find($result['assetid'])) {
                continue;
            }
            
            $item = Asset::find($result['assetid'])->toArray();

            if(!in_array($item['asset_type'], [2, 8, 11, 12, 17, 18, 19, 32])) {
                continue;
            }

            $result['asset_type'] = $item['asset_type'];
            $result['visibility'] = $item['visibility'];

            if($item['visibility'] == 'd') {
                $item['title'] = '[Not Approved]';
            }

            $result['title'] = htmlspecialchars($item['title']);
            $result['uuid'] = $item['author'];
            $result['author'] = User::where('id', $item['author'])->value('username');
            $result['equipped'] = in_array($result['assetid'], Auth::user()->avatar[0]['equippedGearVersionIds']);

            if(in_array($item['asset_type'], [2, 8, 11, 12, 17, 18, 19, 32])) {
                $result['thumbnail'] = $item['additional']['media']['thumbnail'];
            }

            $purchases[] = $result;
        }

        $pageData['purchases'] = $purchases;
        $pageData['outfits'] = \Illuminate\Support\Facades\DB::connection('finobe')->table('outfits')
            ->where('user_id', Auth::user()->id)->orderByDesc('id')->get(['id', 'name']);
        
        return $this->page('Character', $pageData ?? [], $title ?? null);
    }

    public function model3d(Request $request)
    {
        $viewer = Auth::user();
        if (!$viewer || !(int) ($viewer->view3d ?? 0)) {
            return response()->json(['code' => 403, 'message' => 'The 3D view is turned off.'], 403);
        }

        $user = User::find((int) $request->query('userId', $viewer->id));
        if (!$user) {
            return response()->json(['code' => 404, 'message' => 'That user does not exist.'], 404);
        }

        try {
            $manifest = \App\Services\AvatarRenderer::render3d($user);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['code' => 500, 'message' => 'There was an error while rendering in 3D.'], 500);
        }

        return response()->json(['code' => 200] + $manifest);
    }

    public function model3dFile(string $name, string $file)
    {
        if (!Auth::check() || !preg_match('/^\d+-[a-f0-9]{24}$/', $name)
            || !preg_match('/^[A-Za-z0-9_.-]{1,64}\.(obj|mtl|png)$/', $file)) {
            abort(404);
        }

        $path = \App\Services\AvatarRenderer::model3dDirectory() . "/{$name}/{$file}";
        if (!is_file($path)) {
            abort(404);
        }

        $type = ['obj' => 'text/plain', 'mtl' => 'text/plain', 'png' => 'image/png'][pathinfo($file, PATHINFO_EXTENSION)];

        return response()->file($path, ['Content-Type' => $type, 'Cache-Control' => 'private, max-age=86400']);
    }
}
