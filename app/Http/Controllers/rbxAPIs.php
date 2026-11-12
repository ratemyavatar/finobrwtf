<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class rbxAPIs extends Controller
{
    private const DANCE_BASE_ID = 2140000000;

    protected $response;
    protected $db;

    public function __construct() {
        $this->response = [];
        $this->db = DB::connection('finobe');
    }

    public function validatePlaceJoin() {
        return response('true', 200);
    }

    public function productInfo(Request $request) {
        $data = $request->all();
        $assetId = (int) ($data['assetId'] ?? 0);

        if (!$this->db->table('assets')->where('id', $assetId)->exists()) {
            $this->response = [
                "code" => 404,
                "message" => "Invalid assetId"
            ];

            return response()->json($this->response, 404);
        }

        $asset = $this->db->table('assets')
            ->where('id', $assetId)
            ->first();

        $additional = json_decode($asset->additional);

        if (!User::find($asset->author)) {
            $this->response = [
                "code" => 404,
                "message" => "Invalid assetId"
            ];

            return response()->json($this->response, 404);
        }

        $user = User::find($asset->author);

        $this->response = [
            "AssetId" => $asset->id,
            "ProductId" => $asset->id,
            "Name" => $asset->title,
            "Description" => $asset->description,
            "AssetTypeId" => $asset->asset_type,
            "ProductType" => "User Product",
            "Creator" => [
                "Id" => $user->id,
                "Name" => $user->username,
            ],
            "IconImageAssetId" => $additional->media->imageAssetId ?? $asset->id,
            "Created" => $asset->created,
            "Updated" => $asset->updated,
            "PriceInRobux" => \App\Services\Purchasing::price(\App\Models\Asset::find($asset->id)),
            "PriceInTickets" => 0,
            "Sales" => 0,
            "IsNew" => true,
            "IsForSale" => \App\Services\Purchasing::forSale(\App\Models\Asset::find($asset->id)),
            "IsPublicDomain" => false,
            "IsLimited" => false,
            "IsLimitedUnique" => false,
            "Remaining" => 0,
            "MinimumMembershipLevel" => 0,
            "ContentRatingTypeId" => 0,
        ];

        return response()->json($this->response, 200);
    }

    public function getScriptStateAshx() {
        return response("0 0 0 00 0 1 0", 200)
            ->header("Cache-Control", "no-store, no-cache, must-revalidate")
            ->header("Pragma", "no-cache")
            ->header("Expires", "0");
    }

    public function asset(Request $request) {
        $data = $request->all();
        if (($data['id'] ?? null) === 'admin') {
            return $this->adminModel();
        }
        if (($data['id'] ?? null) === 'dance') {
            return $this->danceAsset((string) ($data['name'] ?? ''));
        }
        $assetId = (int)($data['ID'] ?? $data['Id'] ?? $data['iD'] ?? $data['id'] ?? 0);

        if (file_exists(storage_path("rbx/files/2012CoreGui/{$assetId}.lua")))
        {
            $script = "%{$assetId}%\r\n".file_get_contents(storage_path("rbx/files/2012CoreGui/{$assetId}.lua"));

            $script = preg_replace('#https?://www\.' . preg_quote(config('finobe.domain'), '#') . '#i', 'http://assetgame.' . config('finobe.domain'), $script);
            return response(SecurityNotary::SignScript($script, FormatVersion::V1, false));
        }

        if ($assetId > self::DANCE_BASE_ID && $assetId < self::DANCE_BASE_ID + 300000) {
            $dance = $this->danceFile($assetId);
            if ($dance !== null) {
                return response($dance[0], 200)
                    ->header('Content-Type', $dance[1])
                    ->header('Cache-Control', 'public, max-age=86400');
            }
        }

        $animation = storage_path("rbx/files/animations/{$assetId}");
        if ($assetId > 0 && is_file($animation)) {
            return response()->file($animation, ['Content-Type' => 'application/octet-stream']);
        }

        $r15Mesh = storage_path("rbx/files/r15/{$assetId}");
        if ($assetId > 0 && is_file($r15Mesh)) {
            return response()->file($r15Mesh, ['Content-Type' => 'application/octet-stream']);
        }

        if (Asset::isAssetExist($assetId))
        {
            $renderToken = (string) ($data['rendertoken'] ?? '');
            $renderAllowed = $renderToken !== '' && (int) Cache::get("place_render_token_{$renderToken}") === $assetId;

            if(!$renderAllowed && !Asset::isAllowedToDownload($assetId))
            {
                return response('', 403);
            }

            {
                $assetinfo = Asset::getAssetData($assetId);

                if(!in_array($assetinfo->visibility, ['n', 'r'])) {
                    return response('', 200);
                }

                if($assetinfo->visibility == 'r' && !RobloxUtilities::IsTrustedServerRequest()) {
                    if(!Auth::check() || Auth::user()->toArray()['status'] != 'admin') {
                        return response('', 200);
                    }
                }

                if($assetinfo->asset_type != 3) {
                    $path = Asset::getCdnLink($assetId);
                    $body = is_file($path) ? file_get_contents($path) : false;
                    if ($body === false) {
                        abort(404);
                    }

                    if (is_string($body) && !str_starts_with($body, '<roblox!')) {
                        $rewritten = preg_replace(
                            ['#https?://www\\.' . preg_quote(config('finobe.domain'), '#') . '#i', '#https?://(?:www\\.)?roblox\\.com/asset#i'],
                            ['http://assetgame.' . config('finobe.domain'), 'http://assetgame.' . config('finobe.domain') . '/asset'],
                            $body
                        );
                        if (is_string($rewritten)) {
                            $body = $rewritten;
                        }
                    }

                    if ((int) $assetinfo->asset_type === 9 && is_string($body) && str_starts_with($body, '<roblox!')
                        && \App\Services\ClientVersions::of($assetinfo) === '2012') {
                        try {
                            $body = \App\Services\Place2012::convert($body);
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                    return response($body, 200)
                        ->header('Content-Type', 'application/octet-stream')
                        ->header('Content-Length', (string) strlen((string) $body));
                } else {
                    $filepath = config('finobe.cdn_path') . '/audios/' . $assetinfo->file;

                    if (!file_exists($filepath)) {
                        abort(404);
                    }

                    $size = filesize($filepath);
                    $start = 0;
                    $end = $size - 1;

                    $headers = [
                        'Content-Type' => 'audio/mpeg',
                        'Accept-Ranges' => 'bytes',
                    ];

                    if (request()->header('Range')) {
                        preg_match('/bytes=(\d*)-(\d*)/', request()->header('Range'), $matches);

                        if (isset($matches[1]) && $matches[1] !== '') {
                            $start = intval($matches[1]);
                        }

                        if (isset($matches[2]) && $matches[2] !== '') {
                            $end = intval($matches[2]);
                        }

                        if ($start > $end || $end >= $size) {
                            return response('', 416)->withHeaders([
                                'Content-Range' => "bytes */$size"
                            ]);
                        }

                        $length = $end - $start + 1;

                        $headers['Content-Range'] = "bytes $start-$end/$size";
                        $headers['Content-Length'] = $length;

                        $stream = function () use ($filepath, $start, $length) {
                            $fp = fopen($filepath, 'rb');
                            fseek($fp, $start);
                            $bufferSize = 8192;
                            $bytesToOutput = $length;

                            while (!feof($fp) && $bytesToOutput > 0) {
                                $readLength = min($bufferSize, $bytesToOutput);
                                echo fread($fp, $readLength);
                                flush();
                                $bytesToOutput -= $readLength;
                            }

                            fclose($fp);
                        };

                        return response()->stream($stream, 206, $headers);
                    }

                    return response()->file($filepath, array_merge($headers, ['Content-Length' => $size]));
                }
            }
        } else {
            $versionId = (int) ($data['assetversionid'] ?? $data['assetVersionId'] ?? $data['AssetVersionId'] ?? 0);
            if ($assetId <= 0 && $versionId <= 0) {
                abort(404);
            }

            [$body, $status] = \App\Services\RobloxAssetProxy::fetch($assetId, $versionId);
            if ($body === '') {
                abort($status >= 400 && $status < 500 && $status !== 429 ? $status : 503, 'Asset fetch failed: ' . $status);
            }

            $body = \App\Services\RobloxAssetProxy::rewrite($body);
            return response($body, 200)->header('Content-Type', \App\Services\RobloxAssetProxy::contentType($body));
        }
    }

    public function assetThumbnail(Request $request) {
        $assetId = (int) ($request->query('assetId') ?? $request->query('assetid') ?? $request->query('aid') ?? $request->query('id') ?? 0);
        $width = (int) ($request->query('width') ?? $request->query('x') ?? 420);
        $height = (int) ($request->query('height') ?? $request->query('y') ?? 420);
        if ($assetId <= 0) {
            abort(404);
        }

        if (Asset::isAssetExist($assetId)) {
            return redirect('/asset/?id=' . $assetId);
        }

        $url = \App\Services\RobloxAssetProxy::thumbnailUrl($assetId, $width, $height);
        return $url !== '' ? redirect($url) : abort(404);
    }

    public function avatarThumbnail(Request $request) {
        $userId = (int) ($request->query('userId') ?? $request->query('userid') ?? $request->query('id') ?? 0);
        $user = $userId > 0 ? \App\Models\User::find($userId) : null;
        $pfp = $user->pfp ?? null;

        if ($user) {
            \App\Services\AvatarRenderer::renderIfMissing($user->id, $pfp);
        }

        $dir = config('finobe.cdn_path') . '/avatar/';
        $file = $pfp ? $dir . basename($pfp) : '';
        if ($file === '' || !is_file($file)) {
            $file = $dir . basename(\App\Services\AvatarRenderer::DEFAULT_PFP);
        }
        if (!is_file($file)) {
            abort(404);
        }

        return response(file_get_contents($file), 200)
            ->header('Content-Type', 'image/png')
            ->header('Content-Length', (string) filesize($file))
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function registerJobId($jobId) {
        if (RobloxUtilities::IsFinobeCloudAuthorized())
        {
            $this->db->table('servers')
                ->where('jobId', $jobId)
                ->update([
                    'status' => 2,
                    'last_alive' => now(),
                ]);
        }
        return response('', 200);
    }

    public function verifyPlayer($jobId, $userId) {
        if (!RobloxUtilities::IsFinobeCloudAuthorized()) {
            return response('', 403);
        }
        $userId = (int) $userId;
        $cacheKey = "gameserver_join_{$jobId}_{$userId}";
        $key = (string) request()->query('key', '');
        $expected = Cache::get($cacheKey);

        if (!is_string($expected) || $key === '' || !hash_equals($expected, $key) || !Cache::add("{$cacheKey}_used_{$key}", true, 600)) {
            return response('0', 200);
        }

        Cache::forget($cacheKey);
        $user = User::find($userId);
        if (!$user || ($user->banned ?? false) || \App\Services\Restrictions::message($userId, 'play')) {
            return response('0', 200);
        }

        return response('1|' . $user->username, 200)->header('Content-Type', 'text/plain');
    }

    public function danceAsset(string $name) {
        $folder = storage_path('rbx/files/dances');

        if ($name === 'list') {
            return response((string) @file_get_contents("{$folder}/list.txt"), 200)->header('Content-Type', 'text/plain');
        }

        if ($name === 'client') {
            $source = (string) @file_get_contents(storage_path('rbx/files/scripts/dance2016.lua'));
            $source = str_replace(']]>', ']]]]><![CDATA[>', $source);
            $model = '<roblox xmlns:xmime="http://www.w3.org/2005/05/xmlmime" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://www.roblox.com/roblox.xsd" version="4">'
                . '<External>null</External><External>nil</External>'
                . '<Item class="LocalScript" referent="RBX0"><Properties><bool name="Disabled">false</bool>'
                . '<Content name="LinkedSource"><null></null></Content><string name="Name">FinobeDance</string>'
                . '<ProtectedString name="Source"><![CDATA[' . $source . ']]></ProtectedString></Properties></Item></roblox>';
            return response($model, 200)->header('Content-Type', 'text/xml');
        }

        return response('', 404);
    }

    private function danceFile(int $assetId): ?array {
        $folder = storage_path('rbx/files/dances');
        $offset = $assetId - self::DANCE_BASE_ID;

        if ($offset > 200000) {
            $ui = [200001 => 'wheel', 200002 => 'highlight'][$offset] ?? null;
            $file = $ui ? "{$folder}/ui/{$ui}.png" : null;
            return $file && is_file($file) ? [(string) file_get_contents($file), 'image/png'] : null;
        }

        $thumb = $offset > 100000;
        $danceId = $thumb ? $assetId - 100000 : $assetId;
        $list = @file("{$folder}/list.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($list as $line) {
            [$id, $slug] = array_pad(explode("\t", $line, 3), 2, '');
            if ((int) $id !== $danceId || !preg_match('/^[a-z0-9]{1,64}$/', $slug)) {
                continue;
            }
            if ($thumb) {
                $file = "{$folder}/thumbs/{$slug}.png";
                return is_file($file) ? [(string) file_get_contents($file), 'image/png'] : null;
            }
            $file = "{$folder}/{$slug}.rbxmx.gz";
            return is_file($file) ? [(string) gzdecode((string) file_get_contents($file)), 'text/xml'] : null;
        }

        return null;
    }

    public function adminModel() {
        if (!RobloxUtilities::IsFinobeCloudAuthorized()) {
            return response('', 403);
        }

        $model = @file_get_contents(storage_path('rbx/files/admin/admin.rbxmx'));
        if ($model === false) {
            return response('', 404);
        }

        $owners = implode(', ', array_map(fn ($id) => '"=' . (int) $id . '"', config('finobe.ingame_admins', [])));

        return response(str_replace('FINOBE_OWNERS', $owners, $model), 200)
            ->header('Content-Type', 'text/xml')
            ->header('Cache-Control', 'no-store');
    }

    public function playerAnimations($userId) {
        if (!RobloxUtilities::IsFinobeCloudAuthorized()) {
            return response('', 403);
        }

        $user = User::find((int) $userId);
        $pack = \App\Services\AnimationPacks::forUser($user);

        return response(\App\Services\AnimationPacks::gameServerLines($pack, 'https://www.' . config('finobe.domain')), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function visitJobId($jobId) {
        if (RobloxUtilities::IsFinobeCloudAuthorized())
        {
            $server = $this->db->table('servers')
                ->where('jobId', $jobId)
                ->first();

            if (!$server) {
                return response('', 404);
            }

            $assetId = $server->placeid;

            if ($this->db->table('assets')->where('id', $assetId)->exists()) {
                $asset = $this->db->table('assets')
                    ->where('id', $assetId)
                    ->first();

                if ($asset->asset_type == 9) {
                    $additional = json_decode($asset->additional);
                    $additional->visits++;
                    $additional = json_encode($additional);

                    $this->db->table('assets')
                        ->where('id', $assetId)
                        ->update([
                            'additional' => $additional
                        ]);
                }
            }
        }
        return response('', 200);
    }

    public function shutdownJobId($jobId) {
        if (RobloxUtilities::IsFinobeCloudAuthorized())
        {
            $server = $this->db->table('servers')->where('jobId', $jobId)->first();
            $place = $server ? $this->db->table('assets')->where('id', $server->placeid)->first() : null;
            $version = $place ? \App\Services\ClientVersions::of($place) : \App\Services\ClientVersions::DEFAULT;
            $is2012 = !\App\Services\ClientVersions::usesRcc($version);
            [$rccHost, $rccPort] = \App\Services\ClientVersions::rcc($version);

            if ($server) {
                \Illuminate\Support\Facades\Cache::put('datastore-closed-job:' . $jobId, (int) $server->placeid, now()->addMinutes(10));
            }

            $deleted = $this->db->table('servers')
                ->where('jobId', $jobId)
                ->delete();

            if ($deleted) {
                app()->terminating(function () use ($jobId, $is2012, $rccHost, $rccPort, $server) {
                    try {
                        if ($is2012) {
                            \App\Services\Host2012::stop($jobId, optional(\App\Services\GameNodes::forServer($server))->control_url);
                            return;
                        }
                        (new RobloxArbiterUtilities($rccHost, $rccPort))->CloseJob($jobId);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            }
        }
        return response('', 200);
    }

    public function aliveJobId($jobId) {
        if (RobloxUtilities::IsFinobeCloudAuthorized())
        {
            $this->db->table('servers')
                ->where('jobId', $jobId)
                ->update(['last_alive' => now()]);
        }
        return response('', 200);
    }

    public function update(Request $request) {
        if (!RobloxUtilities::IsFinobeCloudAuthorized()) {
            $this->response = [
                "code" => 403,
                "message" => "You're not authorized to access this page."
            ];

            return response()->json($this->response, 403);
        }

        $json = $request->getContent();
        $data = json_decode($json);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->response = [
                "code" => 400,
                "message" => "Invalid JSON data."
            ];

            return response()->json($this->response, 400);
        }
        $jobId = $data->jobId ?? "";
        $players = json_encode($data->players);

        if (!$this->db->table('servers')->where('jobId', $jobId)->exists()) {
            $this->response = [
                "code" => 400,
                "message" => "Invalid jobId"
            ];

            return response()->json($this->response, 400);
        }

        $this->db->table('servers')
            ->where('jobId', $jobId)
            ->update([
                'players' => $players
            ]);

        $this->response = [
            "code" => 200,
            "message" => "Players successfully updated"
        ];

        return response()->json($this->response, 200);
    }

    public function studioAshx() {
        $domain = config('finobe.domain');
        $src = '-- Setup studio cmd bar & load core scripts

        pcall(function() game:GetService("InsertService"):SetFreeModelUrl("http://www.finobe.wtf/Game/Tools/InsertAsset.ashx?type=fm&q=%s&pg=%d&rs=%d") end)
        pcall(function() game:GetService("InsertService"):SetFreeDecalUrl("http://www.finobe.wtf/Game/Tools/InsertAsset.ashx?type=fd&q=%s&pg=%d&rs=%d") end)

        game:GetService("ScriptInformationProvider"):SetAssetUrl("http://www.finobe.wtf/Asset/")
        game:GetService("InsertService"):SetBaseSetsUrl("http://www.finobe.wtf/Game/Tools/InsertAsset.ashx?nsets=10&type=base")
        game:GetService("InsertService"):SetUserSetsUrl("http://www.finobe.wtf/Game/Tools/InsertAsset.ashx?nsets=20&type=user&userid=%d")
        game:GetService("InsertService"):SetCollectionUrl("http://www.finobe.wtf/Game/Tools/InsertAsset.ashx?sid=%d")
        game:GetService("InsertService"):SetAssetUrl("http://www.finobe.wtf/Asset/?id=%d")
        game:GetService("InsertService"):SetAssetVersionUrl("http://www.finobe.wtf/Asset/?assetversionid=%d")

        pcall(function() game:GetService("SocialService"):SetFriendUrl("http://www.finobe.wtf/Game/LuaWebService/HandleSocialRequest.ashx?method=IsFriendsWith&playerid=%d&userid=%d") end)
        pcall(function() game:GetService("SocialService"):SetBestFriendUrl("http://www.finobe.wtf/Game/LuaWebService/HandleSocialRequest.ashx?method=IsBestFriendsWith&playerid=%d&userid=%d") end)
        pcall(function() game:GetService("SocialService"):SetGroupUrl("http://www.finobe.wtf/Game/LuaWebService/HandleSocialRequest.ashx?method=IsInGroup&playerid=%d&groupid=%d") end)
        pcall(function() game:GetService("SocialService"):SetGroupRankUrl("http://www.finobe.wtf/Game/LuaWebService/HandleSocialRequest.ashx?method=GetGroupRank&playerid=%d&groupid=%d") end)
        pcall(function() game:GetService("SocialService"):SetGroupRoleUrl("http://www.finobe.wtf/Game/LuaWebService/HandleSocialRequest.ashx?method=GetGroupRole&playerid=%d&groupid=%d") end)
        pcall(function() game:GetService("GamePassService"):SetPlayerHasPassUrl("http://www.finobe.wtf/Game/GamePass/GamePassHandler.ashx?Action=HasPass&UserID=%d&PassID=%d") end)
        pcall(function() game:GetService("MarketplaceService"):SetProductInfoUrl("https://api.finobe.wtf/marketplace/productinfo?assetId=%d") end)
        pcall(function() game:GetService("MarketplaceService"):SetDevProductInfoUrl("https://api.finobe.wtf/marketplace/productDetails?productId=%d") end)
        pcall(function() game:GetService("MarketplaceService"):SetPlayerOwnsAssetUrl("https://api.finobe.wtf/ownership/hasasset?userId=%d&assetId=%d") end)

        local result = pcall(function() game:GetService("ScriptContext"):AddStarterScript(37801172) end)
        if not result then
          pcall(function() game:GetService("ScriptContext"):AddCoreScript(37801172,game:GetService("ScriptContext"),"StarterScript") end)
        end';

        $src = str_replace('finobe.wtf', $domain, $src);
        $src = preg_replace('#https?://www\.' . preg_quote($domain, '#') . '#i', 'http://assetgame.' . $domain, $src);
        return response(SecurityNotary::SignScript($src, FormatVersion::V1), 200)
            ->header("Cache-Control", "no-store, no-cache, must-revalidate")
            ->header("Pragma", "no-cache")
            ->header("Expires", "0");
    }

}