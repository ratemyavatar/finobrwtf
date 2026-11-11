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

        $avatar = $this->avatarForRequest($request, $user);
        if ($avatar === null) {
            return response('Outfit preview expired', 404);
        }

        $params = ['userId' => $user->id];
        if ($request->query('outfitToken')) {
            $params['outfitToken'] = $request->query('outfitToken');
        }
        $bodyColorsRoute = $request->query('outfitToken') ? 'client-routes.outfit-body-colors' : 'client-routes.body-colors';

        $base = "http://assetgame." . config('finobe.domain');
        $charApp = $base . route($bodyColorsRoute, $params, false) . ';';

        $ids = array_merge(
            $avatar['equippedGearVersionIds'] ?? [],
            $avatar['backpackGearVersionIds'] ?? []
        );

        $ids = self::expandPackages(array_map('intval', $ids));

        $charApp .= $ids ? implode(";", array_map(
            fn($id) => $base . route('client-routes.asset', ['id' => $id], false),
            $ids
        )) : "";

        return response($charApp, 200);
    }

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
