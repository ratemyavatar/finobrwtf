<?php

namespace App\Services;

use App\Http\Controllers\RobloxArbiterUtilities;
use App\Http\Controllers\RobloxUtilities;
use App\Http\Controllers\dataController as DataController;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * A saved outfit gets its own RCC thumbnail. Never change users.avatar or users.pfp
 * to render an outfit that the user is not currently wearing.
 */
class OutfitPreviewRenderer
{
    public static function render(User $user, int $outfitId): string
    {
        $outfit = DB::connection('finobe')->table('outfits')
            ->where('id', $outfitId)
            ->where('user_id', $user->id)
            ->first();

        if (!$outfit) {
            throw new \RuntimeException('Outfit not found.');
        }

        $saved = json_decode((string) $outfit->avatar, true);
        if (!is_array($saved) || !isset($saved['equippedGearVersionIds']) || !is_array($saved['equippedGearVersionIds'])) {
            throw new \RuntimeException("Outfit {$outfitId} does not contain a valid saved avatar.");
        }

        // Pass the six colours to the RCC directly as a plain string. The SOAP
        // argument protocol only supports scalar Lua values (not PHP arrays).
        $bodyColors = is_array($saved['bodyColors'] ?? null) ? $saved['bodyColors'] : [];
        $defaults = [
            'headColorId' => 24,
            'torsoColorId' => 23,
            'leftArmColorId' => 24,
            'rightArmColorId' => 24,
            'leftLegColorId' => 119,
            'rightLegColorId' => 119,
        ];
        $colorIds = [];
        foreach ($defaults as $key => $default) {
            $value = filter_var($bodyColors[$key] ?? $default, FILTER_VALIDATE_INT);
            $colorIds[] = $value !== false && $value > 0 ? $value : $default;
        }
        $colorArgument = implode(',', $colorIds);

        // A file belongs to the exact saved snapshot. Never use users.pfp or the
        // user's current gear, colours or pose as a preview/fallback.
        $hash = substr(hash('sha256', (string) $outfit->avatar), 0, 12);
        $filename = "{$outfitId}-{$hash}-v3.png";
        $relative = "outfits/{$filename}";
        $directory = config('finobe.cdn_path') . '/outfits';
        $destination = "{$directory}/{$filename}";

        if (File::isFile($destination) && File::size($destination) > 8) {
            return $relative;
        }

        // Freeze this exact outfit in the shared cache for RCC's CharacterFetch
        // and BodyColors calls. Changing the user's live avatar cannot affect it.
        $token = bin2hex(random_bytes(16));
        $cacheKey = "outfit_thumbnail_token_{$token}";
        Cache::put($cacheKey, ['userId' => (int) $user->id, 'avatar' => $saved], now()->addMinutes(5));
        $pose = (string) ($saved['pose'] ?? 'default');
        if (!isset(AvatarRenderer::POSES[$pose])) {
            $pose = 'default';
        }

        try {
            $domain = env('FINOBE_DOMAIN', 'finobe.wtf');
            $site = "https://www.{$domain}";
            $arbiter = new RobloxArbiterUtilities(DataController::arbiter_pool(), (int) config('finobe.arbiter.port'));
            $job = $arbiter->ConstructJob(
                RobloxUtilities::GenerateGUID(),
                AvatarRenderer::SCRIPT,
                60,
                0,
                2,
                'ScriptExecution',
                ["{$site}/asset/OutfitCharacterFetch.ashx?userId={$user->id}&outfitToken={$token}", $site, 'PNG', 512, 512, $pose, $colorArgument]
            );

            $image = base64_decode((string) $arbiter->OpenJobEx($job), true);
        } finally {
            Cache::forget($cacheKey);
        }

        if (empty($image) || !str_starts_with($image, "\x89PNG\r\n\x1a\n")) {
            throw new \RuntimeException("Outfit {$outfitId} thumbnail render returned no PNG.");
        }

        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0775, true);
        }

        if (File::put($destination, $image) === false) {
            throw new \RuntimeException("Could not write thumbnail for outfit {$outfitId}.");
        }

        return $relative;
    }

    public static function delete(int $outfitId): void
    {
        $directory = config('finobe.cdn_path') . '/outfits';
        File::delete("{$directory}/{$outfitId}.png");
        foreach (File::glob("{$directory}/{$outfitId}-*.png") as $file) {
            File::delete($file);
        }
    }
}
