<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;

use App\Models\User;
use App\Models\Asset;
use App\Http\Controllers\Asset as AssetHelper;

class CharacterAppearanceController extends Controller
{
    /**
     * A thumbnail render may request an outfit snapshot instead of the live avatar.
     * The token is issued only to RCC and expires after five minutes.
     */
    private function avatarForRequest(Request $request, User $user): ?array
    {
        $token = (string) $request->query('outfitToken', '');
        if ($token === '') {
            $avatar = $user->avatar;
            return is_array($avatar) && is_array($avatar[0] ?? null) ? $avatar[0] : [];
        }

        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        $render = Cache::get("outfit_thumbnail_token_{$token}");
        if (!is_array($render) || (int) ($render['userId'] ?? 0) !== (int) $user->id) {
            return null;
        }

        // The token holds the immutable saved snapshot, not a reference to
        // users.avatar. An invalid/expired token fails closed rather than
        // silently rendering the user's current avatar.
        $saved = $render['avatar'] ?? null;
        return is_array($saved) ? $saved : null;
    }

    public function outfitCharacterFetch(Request $request)
    {
        if (!(string) $request->query('outfitToken', '')) {
            return response('Outfit preview token required', 404);
        }
        return $this->characterFetch($request);
    }

    public function outfitBodyColors(Request $request)
    {
        if (!(string) $request->query('outfitToken', '')) {
            return response('Outfit preview token required', 404);
        }
        return $this->bodyColors($request);
    }

    public function bodyColors(Request $request) {
        $validator = Validator::make($request->all(), [
			'userId' => [
				'required',
				Rule::exists('App\Models\User', 'id')
			]
		]);

        if($validator->fails()) {
            return response()->json(['code' => 0, 'error' => 'Not found'], 404);
		}

		$valid = $validator->valid();
		$user = User::find($valid['userId']);

        // accounts with no saved avatar yet get the default colours instead of a 500
        $avatar = $this->avatarForRequest($request, $user);
        if ($avatar === null) {
            return response('Outfit preview expired', 404);
        }

        $saved = $avatar['bodyColors'] ?? [];
        $colors = [];
        foreach (['headColorId' => 24, 'leftArmColorId' => 24, 'leftLegColorId' => 119, 'rightArmColorId' => 24,
                  'rightLegColorId' => 119, 'torsoColorId' => 23] as $k => $default) {
            $colors[$k] = (int) ($saved[$k] ?? $default);
        }

        return '<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.finobe.net/roblox.xsd" version="4">
            <External>null</External>
            <External>nil</External>
            <Item class="BodyColors">
                <Properties>
                    <int name="HeadColor">'.$colors['headColorId'].'</int>
                    <int name="LeftArmColor">'.$colors['leftArmColorId'].'</int>
                    <int name="LeftLegColor">'.$colors['leftLegColorId'].'</int>
                    <string name="Name">Body Colors</string>
                    <int name="RightArmColor">'.$colors['rightArmColorId'].'</int>
                    <int name="RightLegColor">'.$colors['rightLegColorId'].'</int>
                    <int name="TorsoColor">'.$colors['torsoColorId'].'</int>
                    <bool name="archivable">true</bool>
                </Properties>
            </Item>
        </roblox>';
    }

    public function characterFetch(Request $request) {
        $validator = Validator::make($request->all(), [
			'userId' => [
				'required',
				Rule::exists('App\Models\User', 'id')
			]
		]);

        if($validator->fails()) {
            return response()->json(['code' => 0, 'error' => 'Not found'], 404);
		}

		$valid = $validator->valid();
        $user = User::find($valid['userId']);

        //TODO(Karma): Database structure actually should be revamped, as current is hell, too much json which is not supposed to be used?
        $avatar = $this->avatarForRequest($request, $user);
        if ($avatar === null) {
            return response('Outfit preview expired', 404);
        }

        $params = ['userId' => $user->id];
        if ($request->query('outfitToken')) {
            $params['outfitToken'] = $request->query('outfitToken');
        }
        $bodyColorsRoute = $request->query('outfitToken') ? 'client-routes.outfit-body-colors' : 'client-routes.body-colors';
        // route() defaults to the https www host, which the 2012 client can't follow (301);
        // pin to assetgame, which serves both clients, while keeping the outfit-token routes
        $base = "http://assetgame." . config('finobe.domain');
        $charApp = $base . route($bodyColorsRoute, $params, false) . ';';

        $ids = array_merge(
            $avatar['equippedGearVersionIds'] ?? [],
            $avatar['backpackGearVersionIds'] ?? []
        );

        // A package is not a wearable: the client can't load a type-32 asset, so swap
        // each equipped package for the items it contains before building the charapp.
        $ids = self::expandPackages(array_map('intval', $ids));

        $charApp .= $ids ? implode(";", array_map(
            fn($id) => $base . route('client-routes.asset', ['id' => $id], false),
            $ids
        )) : "";

        return response($charApp, 200);
    }

    /**
     * Replace any package (type 32) in the list with the asset ids it contains.
     * Non-package ids pass through unchanged; the result keeps its order and is unique.
     *
     * @param int[] $ids
     * @return int[]
     */
    public static function expandPackages(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            if ((int) Asset::where('id', $id)->value('asset_type') === 32) {
                foreach (AssetHelper::getPackageAssets($id) as $contained) {
                    $out[] = (int) $contained;
                }
            } else {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }
}
