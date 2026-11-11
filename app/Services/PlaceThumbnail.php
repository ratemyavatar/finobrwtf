<?php

namespace App\Services;

use App\Models\Asset;
use App\Http\Controllers\RobloxUtilities;
use App\Http\Controllers\RobloxArbiterUtilities;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class PlaceThumbnail
{
    const PLACEHOLDER = '/s/img/finnobe3logo.png';

    const SCRIPT = '
        local placeUrl, url, accessKey, fileExtension, x, y = ...

        pcall(function() game:GetService("ScriptInformationProvider"):SetAccessKey(accessKey) end)
        pcall(function() game:GetService("ContentProvider"):SetBaseUrl(url) end)
        game:GetService("ScriptContext").ScriptsDisabled = true

        game:Load(placeUrl)

        game:GetService("ThumbnailGenerator").GraphicsMode = 4
        return game:GetService("ThumbnailGenerator"):Click(fileExtension, x, y, false)
    ';

    public static function url(array $additional): string
    {
        $thumbnail = $additional['media']['thumbnail'] ?? null;

        if ($thumbnail && str_starts_with($thumbnail, 'thumbnails/')) {
            return 'https://cdn.' . config('finobe.domain') . '/' . $thumbnail;
        }

        return self::PLACEHOLDER;
    }

    public static function needsRender(array $additional): bool
    {
        $thumbnail = $additional['media']['thumbnail'] ?? null;

        if (!$thumbnail || !str_starts_with($thumbnail, 'thumbnails/')) {
            return true;
        }

        return !File::exists(config('finobe.cdn_path') . '/thumbnails/' . basename($thumbnail));
    }

    public static function renderIfMissing(int $placeId, array $additional): void
    {
        if (self::needsRender($additional) && Cache::add("place_thumbnail_autorender_{$placeId}", true, 600)) {
            \App\Jobs\RenderPlaceThumbnail::dispatchAfterResponse($placeId);
        }
    }

    public static function render(int $placeId): string
    {
        $place = Asset::where('id', $placeId)->where('asset_type', 9)->first();

        if (!$place) {
            throw new \RuntimeException("Place {$placeId} not found");
        }

        $site = 'https://www.' . config('finobe.domain');

        $token = \Illuminate\Support\Str::random(48);
        Cache::put("place_render_token_{$token}", $placeId, 300);

        $arbiter = new RobloxArbiterUtilities(config('finobe.arbiter.host'), (int) config('finobe.arbiter.port'));
        $job = $arbiter->ConstructJob(
            RobloxUtilities::GenerateGUID(),
            self::SCRIPT,
            90,
            0,
            2,
            "ScriptExecution",
            ["{$site}/asset/?id={$placeId}&rendertoken={$token}", $site, (string) config('finobe.cloud_key'), "PNG", 768, 432]
        );

        try {
            $image = base64_decode((string) $arbiter->OpenJobEx($job), true);
        } finally {
            Cache::forget("place_render_token_{$token}");
        }

        if (empty($image) || !str_starts_with($image, "\x89PNG")) {
            throw new \RuntimeException("Place thumbnail render for {$placeId} returned no image (check the RCC log on the arbiter)");
        }

        $directory = config('finobe.cdn_path') . '/thumbnails';
        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0775, true);
        }

        $filename = uniqid() . '.png';
        File::put("{$directory}/{$filename}", $image);

        $place = Asset::find($placeId);
        $additional = $place->additional;
        $old = $additional['media']['thumbnail'] ?? null;

        $additional['media']['thumbnail'] = "thumbnails/{$filename}";
        $additional['media']['customThumbnail'] = false;
        $place->additional = $additional;
        $place->save();

        if ($old && str_starts_with($old, 'thumbnails/')) {
            File::delete($directory . '/' . basename($old));
        }

        return self::url($additional);
    }
}
