<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Asset as AssetHelper;
use App\Http\Controllers\Controller;
use App\Http\Controllers\FormatVersion;
use App\Http\Controllers\SecurityNotary;
use App\Jobs\RenderPlaceThumbnail;
use App\Models\Asset;
use App\Models\User;
use App\Services\ClientVersions;
use App\Services\StudioAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class StudioDataController extends Controller
{
    const MAX_PLACE_BYTES = 50 * 1024 * 1024;
    const MAX_MODEL_BYTES = 20 * 1024 * 1024;
    const MODELS_PER_HOUR = 20;

    private static function base(): string
    {
        return 'https://www.' . config('finobe.domain');
    }

    private static function ownedPlace(?User $user, int $placeId): ?Asset
    {
        if (!$user || StudioAuth::banned($user) || $placeId < 1) {
            return null;
        }

        return Asset::where('id', $placeId)->where('asset_type', 9)->where('author', $user->id)->first();
    }

    private static function script(array $values)
    {
        $script = Storage::get('penelope/scripts/edit.lua');
        foreach ($values as $key => $value) {
            $script = str_replace('{' . $key . '}', $value, $script);
        }
        $script = str_replace('finobe.wtf', config('finobe.domain'), $script);

        return response(SecurityNotary::SignScript($script, FormatVersion::V2, true), 200)
            ->header('Content-Type', 'text/plain')
            ->header('Cache-Control', 'no-store');
    }

    private static function luaString(string $value): string
    {
        return addcslashes($value, "\\\"\n\r\0");
    }

    public function edit(Request $request)
    {
        $template = (string) $request->query('template', '');
        if ($template !== '') {
            return $this->template($request, $template);
        }

        $user = StudioAuth::user($request);
        $placeId = (int) $request->query('placeid', 0);
        $place = self::ownedPlace($user, $placeId);

        if (!$place) {
            return response('', 403);
        }

        $token = bin2hex(random_bytes(24));
        Cache::put("place_render_token_{$token}", $placeId, 600);

        return self::script([
            'placeId' => (string) $placeId,
            'creatorId' => (string) (int) $user->id,
            'placeUrl' => self::luaString('http://www.' . config('finobe.domain') . "/asset/?id={$placeId}&rendertoken={$token}"),
            'uploadUrl' => self::luaString(self::base() . "/data/upload.ashx?assetid={$placeId}"),
        ]);
    }

    public function template(Request $request, string $name)
    {
        if (!isset(StudioController::TEMPLATES[$name])) {
            return response('', 404);
        }
        $user = StudioAuth::user($request);

        return self::script([
            'placeId' => '0',
            'creatorId' => (string) (int) ($user->id ?? 0),
            'placeUrl' => self::luaString('http://www.' . config('finobe.domain') . "/asset/?id=template&name={$name}"),
            'uploadUrl' => '',
        ]);
    }

    public static function templateFile(string $name)
    {
        $template = StudioController::TEMPLATES[$name] ?? null;
        $path = $template ? storage_path('rbx/files/places/' . $template[1]) : null;

        if (!$path || !is_file($path)) {
            return response('', 404);
        }

        return response()->file($path, ['Content-Type' => 'application/octet-stream']);
    }

    private static function readUpload(Request $request, int $limit): ?string
    {
        $body = $request->getContent();
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = @gzdecode($body, $limit + 1);
            if ($body === false) {
                return null;
            }
        }
        if ($body === '' || strlen($body) > $limit || !preg_match('/^\s*<roblox[!\s]/', substr($body, 0, 64))) {
            return null;
        }

        return $body;
    }

    public function uploadPlace(Request $request)
    {
        $user = StudioAuth::user($request);
        $place = self::ownedPlace($user, (int) $request->query('assetid', 0));

        if (!$place) {
            return response('You do not have permission to save this place.', 403);
        }

        $contents = self::readUpload($request, self::MAX_PLACE_BYTES);
        if ($contents === null) {
            return response('That is not a place file, or it is over 50 MB.', 400);
        }

        $hash = uniqid();
        File::put(AssetHelper::ensureDirectory(config('finobe.cdn_path') . '/assets') . "/{$hash}", $contents);
        $old = $place->file;
        $additional = is_array($place->additional) ? $place->additional : (json_decode((string) $place->additional, true) ?: []);
        $additional['version'] = '2016';
        $place->file = $hash;
        $place->additional = $additional;
        $place->save();
        if ($old && $old !== $hash && basename($old) === $old) {
            File::delete(config('finobe.cdn_path') . '/assets/' . $old);
        }

        if (empty($additional['media']['customThumbnail'])) {
            RenderPlaceThumbnail::dispatchAfterResponse((int) $place->id);
        }

        return response((string) $place->id, 200)->header('Content-Type', 'text/plain');
    }

    public function createPlace(Request $request)
    {
        $user = Auth::user();
        if (!$user || StudioAuth::banned($user)) {
            return response()->json(['error' => 'Log in to publish.'], 403);
        }
        if (!config('finobe.features.create_places')) {
            return response()->json(['error' => 'Making places is turned off right now.'], 403);
        }

        $title = trim((string) $request->input('title'));
        $description = trim((string) $request->input('description', ''));
        if (mb_strlen($title) < 3 || mb_strlen($title) > 255 || mb_strlen($description) > 8192) {
            return response()->json(['error' => 'The name must be 3 to 255 characters.'], 422);
        }
        if (Asset::where('author', $user->id)->where('asset_type', 9)->count() >= (int) $user->slots) {
            return response()->json(['error' => 'You have used all of your place slots.'], 403);
        }

        $template = storage_path('rbx/files/places/2016.rbxl');
        $id = AssetHelper::createAsset($title, 9, (int) $user->id, is_file($template) ? file_get_contents($template) : '', $description, 'n', [
            'visits' => 0,
            'version' => '2016',
            'maxplayers' => 15,
            'category' => 'original',
            'featured' => false,
            'gears' => ['combat' => true, 'social' => true, 'building' => true, 'musical' => true],
            'uncopylocked' => false,
            'allowplaying' => true,
            'chat_type' => 'classic',
            'media' => ['imageAssetId' => 0],
            'hidden' => false,
        ]);

        return response()->json(['id' => $id, 'upload' => self::base() . "/data/upload.ashx?assetid={$id}&token=" . StudioAuth::token($user)]);
    }

    public function newModel(Request $request)
    {
        $user = StudioAuth::user($request);
        if (!$user || StudioAuth::banned($user)) {
            return response('Log in to publish.', 403);
        }
        if ((int) $request->query('type', 10) !== 10) {
            return response('Only models can be published from here.', 400);
        }

        $name = trim((string) $request->query('name', ''));
        $description = trim((string) $request->query('description', ''));
        if (mb_strlen($name) < 3 || mb_strlen($name) > 255 || mb_strlen($description) > 8192) {
            return response('The name must be 3 to 255 characters.', 422);
        }

        $key = "studio_models_{$user->id}_" . date('YmdH');
        if ((int) Cache::get($key, 0) >= self::MODELS_PER_HOUR) {
            return response('You are publishing too fast. Try again later.', 429);
        }

        $contents = self::readUpload($request, self::MAX_MODEL_BYTES);
        if ($contents === null) {
            return response('Select something to publish first.', 400);
        }
        Cache::put($key, (int) Cache::get($key, 0) + 1, 3600);

        $file = uniqid();
        File::put(AssetHelper::ensureDirectory(public_path('dynamic/reviewing')) . "/{$file}", $contents);
        $asset = Asset::create([
            'asset_type' => 10,
            'title' => $name,
            'author' => $user->id,
            'file' => $file,
            'description' => $description,
            'visibility' => 'r',
            'additional' => ['price' => 0, 'onSale' => false, 'media' => ['imageAssetId' => 0], 'oldUser' => ''],
        ]);

        return response((string) $asset->id, 200)->header('Content-Type', 'text/plain');
    }

    public static function decal(int $id)
    {
        $asset = Asset::where('id', $id)->where('asset_type', 1)->where('visibility', 'n')->first();
        if (!$asset) {
            return response('', 404);
        }

        return self::model('Decal', [
            'string Name' => (string) $asset->title,
            'Content Texture' => 'http://www.' . config('finobe.domain') . "/asset/?id={$id}",
        ]);
    }

    public static function audio(int $id)
    {
        $asset = Asset::where('id', $id)->where('asset_type', 3)->where('visibility', 'n')->first();
        if (!$asset) {
            return response('', 404);
        }

        return self::model('Sound', [
            'string Name' => (string) $asset->title,
            'Content SoundId' => 'http://www.' . config('finobe.domain') . "/asset/?id={$id}",
        ]);
    }

    private static function model(string $class, array $properties)
    {
        $xml = '<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" version="4">'
            . '<Item class="' . $class . '" referent="RBX0"><Properties>';
        foreach ($properties as $key => $value) {
            [$type, $name] = explode(' ', $key, 2);
            $value = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
            $xml .= $type === 'Content'
                ? "<Content name=\"{$name}\"><url>{$value}</url></Content>"
                : "<{$type} name=\"{$name}\">{$value}</{$type}>";
        }
        $xml .= '</Properties></Item></roblox>';

        return response($xml, 200)->header('Content-Type', 'application/octet-stream');
    }

    public function universeOfPlace(Request $request)
    {
        $id = (int) ($request->query('placeid') ?? $request->query('placeId') ?? 0);
        $exists = Asset::where('id', $id)->where('asset_type', 9)->exists();

        return response()->json($exists ? ['UniverseId' => $id] : ['UniverseId' => null]);
    }

    public function universeInfo(Request $request)
    {
        $id = (int) ($request->query('universeid') ?? $request->query('universeId') ?? $request->query('placeId') ?? 0);
        $place = Asset::where('id', $id)->where('asset_type', 9)->first();
        if (!$place) {
            return response()->json(['Name' => null, 'RootPlace' => null], 404);
        }

        return response()->json(['Name' => (string) $place->title, 'RootPlace' => $id]);
    }

    public function universePlaces(Request $request)
    {
        $id = (int) ($request->query('universeid') ?? $request->query('universeId') ?? 0);
        $place = Asset::where('id', $id)->where('asset_type', 9)->first();

        return response()->json([
            'FinalPage' => true,
            'RootPlace' => $place ? $id : null,
            'Places' => $place ? [['PlaceId' => $id, 'Name' => (string) $place->title]] : [],
            'PageSize' => 50,
        ]);
    }

    public function placeSettings(int $placeId)
    {
        $place = Asset::where('id', $placeId)->where('asset_type', 9)->first();
        if (!$place) {
            return response()->json(['error' => 'not found'], 404);
        }
        $owner = User::find((int) $place->author);
        $creator = [
            'CreatorType' => 'User',
            'CreatorTargetId' => (int) $place->author,
            'Name' => (string) ($owner->username ?? ''),
        ];

        return response()->json([
            'Id' => $placeId,
            'Name' => (string) $place->title,
            'Creator' => $creator,
            'CreatorType' => 'User',
            'CreatorTargetId' => (int) $place->author,
            'UniverseId' => $placeId,
        ]);
    }

    public function gameStartInfo(int $universeId)
    {
        return response()->json(['gameAvatarType' => 'MorphToR6', 'r15Morphing' => false]);
    }

    public function canManage(int $userId, int $assetId)
    {
        $can = Asset::where('id', $assetId)->where('author', $userId)->exists();

        return response()->json(['Success' => true, 'CanManage' => $can]);
    }

    public function mySettings(Request $request)
    {
        $user = StudioAuth::user($request);
        if (!$user) {
            return response()->json(['UserId' => 0], 401);
        }

        return response()->json([
            'UserId' => (int) $user->id,
            'Name' => (string) $user->username,
            'UserAbove13' => true,
            'UseSuperSafeChat' => false,
            'AccountAgeInDays' => max(0, (int) floor((time() - strtotime((string) ($user->created_at ?? 'now'))) / 86400)),
            'IsAnyBC' => false,
            'IsOBC' => false,
            'IsTBC' => false,
        ]);
    }

    public function crash()
    {
        return response('', 200);
    }
}
