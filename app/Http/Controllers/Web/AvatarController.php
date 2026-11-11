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
}