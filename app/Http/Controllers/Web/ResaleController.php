<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Purchases;
use App\Services\Reselling;
use App\Services\Trading;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ResaleController extends Controller
{
    public function sell(Request $request, $purchaseId)
    {
        if (!Auth::check()) {
            return redirect('/');
        }
        $user = Auth::user();
        $row = Purchases::find((int) $purchaseId);
        $asset = Reselling::sellable($row, $user);
        if (!$asset) {
            abort(404);
        }

        if ($request->isMethod('post')) {
            try {
                Reselling::sell($user, (int) $purchaseId, $request->input('price'));
            } catch (\RuntimeException $e) {
                Session::put('error', $e->getMessage());
                return redirect('/item/sell/' . (int) $purchaseId);
            }
            Session::put('resale_selling', ('You are now selling <b>' . htmlspecialchars($asset->title) . '</b> for ' . Reselling::dius((int) $request->input('price')) . '.'));
            return redirect('/item/' . (int) $asset->id);
        }

        $listing = Reselling::listingFor((int) $purchaseId);
        $pageData['sell'] = [
            'purchase_id' => (int) $purchaseId,
            'title' => htmlspecialchars($asset->title),
            'value' => Trading::value($asset),
            'max' => Reselling::MAX_PRICE,
            'price' => $listing ? (int) $listing->price : '',
            'assetid' => (int) $asset->id,
        ];
        return $this->page('Catalog/Sell', $pageData, 'Sell Asset');
    }

    public function buy(Request $request, $listingId)
    {
        if (!Auth::check()) {
            return redirect('/');
        }
        $back = (int) $request->input('assetid');
        try {
            $result = Reselling::buy(Auth::user(), (int) $listingId);
        } catch (\RuntimeException $e) {
            Session::put('error', $e->getMessage());
            return redirect($back > 0 ? '/item/' . $back : '/catalog');
        }
        $asset = \App\Models\Asset::find($result['assetid']);
        Session::put('resale_bought', ('You have successfully purchased <b>' . htmlspecialchars($asset->title ?? '') . '</b> for ' . Reselling::dius($result['price']) . '.'));
        return redirect('/item/' . $result['assetid']);
    }

    public function remove(Request $request, $listingId)
    {
        if (!Auth::check()) {
            return redirect('/');
        }
        try {
            $assetId = Reselling::remove(Auth::user(), (int) $listingId);
        } catch (\RuntimeException $e) {
            Session::put('error', $e->getMessage());
            return redirect('/catalog');
        }
        return redirect('/item/' . $assetId);
    }
}
