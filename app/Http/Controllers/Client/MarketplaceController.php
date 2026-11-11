<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\Purchasing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MarketplaceController extends Controller
{
    public function hasPass(Request $request)
    {
        $userId = (int) $request->query('UserID', $request->query('userid', 0));
        $passId = (int) $request->query('PassID', $request->query('passid', 0));
        $pass = Asset::where('id', $passId)->where('asset_type', Purchasing::GAME_PASS)->first();
        $has = $pass && Purchasing::owns($userId, $passId);

        return response('<Value Type="boolean">' . ($has ? 'true' : 'false') . '</Value>', 200)
            ->header('Content-Type', 'text/plain');
    }

    public function ownsAsset(Request $request)
    {
        $owns = Purchasing::owns((int) $request->query('userId', 0), (int) $request->query('assetId', 0));

        return response($owns ? 'true' : 'false', 200)->header('Content-Type', 'text/plain');
    }

    public function economyStatus()
    {
        return response()->json([
            'isMarketplaceEnabled' => true,
            'isMarketplaceEnabledForAuthenticatedUsers' => true,
            'isMarketplaceEnabledForUser' => true,
            'isMarketplaceEnabledForGroups' => true,
        ]);
    }

    public function balance(Request $request)
    {
        $userId = Auth::id() ?? AuthenticationController::clientUserId($request);
        $user = $userId ? User::find($userId) : null;

        if (!$user) {
            return response()->json(['errors' => [['code' => 0, 'message' => 'Unauthorized']]], 401);
        }

        return response()->json(['robux' => (int) $user->Dius, 'tickets' => 0]);
    }

    public function purchase(Request $request)
    {
        $userId = AuthenticationController::clientUserId($request);
        $user = $userId ? User::find($userId) : null;

        if (!$user) {
            return response()->json(['success' => false, 'status' => 'Unauthorized'], 401);
        }

        if (in_array($user->status ?? '', ['banned', 'terminated'], true)) {
            return response()->json(['success' => false, 'status' => 'EconomyDisabled']);
        }

        $item = Asset::find((int) $request->input('productId', 0));
        if (!$item) {
            return response()->json(['success' => false, 'status' => 'InvalidProduct']);
        }

        if (Purchasing::owns((int) $user->id, (int) $item->id)) {
            return response()->json(['success' => false, 'status' => 'AlreadyOwned']);
        }

        $error = Purchasing::buy((int) $user->id, $item, (int) $request->input('purchasePrice', -1));

        if ($error !== null) {
            return response()->json(['success' => false, 'status' => match ($error) {
                'Not enough Dius' => 'InsufficientFunds',
                'The price changed' => 'PriceChanged',
                'This item has already been purchased' => 'AlreadyOwned',
                default => 'NotForSale',
            }]);
        }

        return response()->json(['success' => true, 'status' => 'Bought', 'receipt' => null]);
    }
}
