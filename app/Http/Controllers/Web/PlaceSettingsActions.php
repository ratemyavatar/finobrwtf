<?php

namespace App\Http\Controllers\Web;

use App\Models\Asset;
use App\Models\PlaceBadgeAward;
use App\Models\User;
use App\Services\PlaceAccess;
use App\Http\Controllers\Asset as AssetHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Services\Discord;

/*
    PlaceSettingsActions
     - everything on the place settings page besides the "General" form:
       place file, custom thumbnail, badges, user restrictions
*/
class PlaceSettingsActions
{
    const MAX_BADGES = 50;

    public function __construct(private Asset $place, private array $user)
    {
    }

    private function back(string $tab, ?string $ok = null, ?string $error = null)
    {
        if ($ok) Session::put('successv2', $ok);
        if ($error) Session::put('error', $error);

        return redirect(\App\Support\Paths::placeEdit((int) $this->place->id, $tab));
    }

    private function fail(string $tab, $validator)
    {
        return $this->back($tab, null, $validator->errors()->first());
    }

    private function cdn(string $path = ''): string
    {
        return AssetHelper::ensureDirectory(config('finobe.cdn_path') . '/' . $path);
    }

    private function reviewing(): string
    {
        return AssetHelper::ensureDirectory(public_path('dynamic/reviewing'));
    }

    private function rerenderUnlessCustom(): void
    {
        if (empty($this->place->additional['media']['customThumbnail'])) {
            \App\Jobs\RenderPlaceThumbnail::dispatchAfterResponse((int) $this->place->id);
        }
    }

    private function replacePlaceFile(string $contents): void
    {
        $hash = uniqid();
        File::put($this->cdn('assets') . '/' . $hash, $contents);

        $old = $this->place->file;
        $this->place->file = $hash;
        $this->place->save();

        if ($old && $old !== $hash) {
            File::delete(config('finobe.cdn_path') . '/assets/' . basename($old));
        }
    }

    public function handle(Request $request)
    {
        return match ((string) $request->input('action')) {
            'upload_place' => $this->uploadPlace($request),
            'reset_place' => $this->resetPlace(),
            'upload_thumbnail' => $this->uploadThumbnail($request),
            'create_badge' => $this->createBadge($request),
            'delete_badge' => $this->deleteBadge($request),
            'save_restrictions' => $this->saveRestrictions($request),
            default => $this->back('general'),
        };
    }

    // ---------- place file ----------

    private function uploadPlace(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'place_file' => 'required|file|max:51200',
        ], ['place_file.max' => 'Place files can be up to 50 MB.', 'place_file.required' => 'Pick a place file first.']);

        if ($validator->fails()) {
            return $this->fail('general', $validator);
        }

        $file = $request->file('place_file');
        $extension = strtolower($file->getClientOriginalExtension());
        $contents = file_get_contents($file->getPathname());

        // binary places start with "<roblox!", XML ones with "<roblox "
        if (!in_array($extension, ['rbxl', 'rbxlx'], true) || !preg_match('/^\s*<roblox[!\s]/', substr($contents, 0, 64))) {
            return $this->back('general', null, "That doesn't look like a place file (.rbxl or .rbxlx).");
        }

        $this->replacePlaceFile($contents);
        $this->rerenderUnlessCustom();

        return $this->back('general', 'Place uploaded! Your game now uses the new file.');
    }

    private function resetPlace()
    {
        $version = $this->place->additional['version'] ?? '2016';
        $template = storage_path("rbx/files/places/{$version}.rbxl");

        if (!is_readable($template)) {
            $template = storage_path('rbx/files/places/2016.rbxl');
        }

        if (!is_readable($template)) {
            return $this->back('general', null, "Couldn't find the baseplate to reset to.");
        }

        $this->replacePlaceFile(file_get_contents($template));
        $this->rerenderUnlessCustom();

        return $this->back('general', 'Place reset to a fresh baseplate.');
    }

    // ---------- thumbnail ----------

    private function uploadThumbnail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'thumbnail' => 'required|file|mimes:png,jpg,jpeg|max:5120|dimensions:min_width=320,min_height=180',
        ], [
            'thumbnail.required' => 'Pick an image first.',
            'thumbnail.mimes' => 'Thumbnails have to be PNG or JPG.',
            'thumbnail.max' => 'Thumbnails can be up to 5 MB.',
            'thumbnail.dimensions' => 'Thumbnails need to be at least 320x180.',
        ]);

        if ($validator->fails()) {
            return $this->fail('thumbnail', $validator);
        }

        $additional = $this->place->additional;

        // replace an earlier upload that's still waiting for review
        if (!empty($additional['media']['pendingThumbnail'])) {
            $this->discardPending((int) $additional['media']['pendingThumbnail']);
        }

        $filename = uniqid() . '.' . strtolower($request->file('thumbnail')->extension());
        $request->file('thumbnail')->move($this->reviewing(), $filename);

        $pending = Asset::create([
            'asset_type' => 1,
            'title' => 'Thumbnail for ' . $this->place->title,
            'author' => $this->user['id'],
            'file' => $filename,
            'description' => '',
            'visibility' => 'r',
            'additional' => ['placeThumbnailFor' => (int) $this->place->id],
        ]);

        $additional['media']['pendingThumbnail'] = $pending->id;
        $this->place->additional = $additional;
        $this->place->save();

        Discord::send($this->user['username'] . ' uploaded a game thumbnail, moderate it! [ ' . config('app.url') . '/admin/assets ]');

        return $this->back('thumbnail', "Uploaded! It'll show up once a moderator approves it.");
    }

    private function discardPending(int $assetId): void
    {
        $asset = Asset::where('id', $assetId)->where('asset_type', 1)->where('visibility', 'r')->first();

        if ($asset) {
            File::delete(public_path('dynamic/reviewing/' . basename($asset->file)));
            $asset->delete();
        }
    }

    // ---------- badges ----------

    private function createBadge(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'badge_name' => 'required|string|min:3|max:50',
            'badge_description' => 'nullable|string|max:1000',
            'badge_icon' => 'required|file|mimes:png,jpg,jpeg|max:2048|dimensions:min_width=64,min_height=64',
        ], [
            'badge_name.required' => 'Give your badge a name.',
            'badge_icon.required' => 'Your badge needs an icon.',
            'badge_icon.mimes' => 'Badge icons have to be PNG or JPG.',
            'badge_icon.max' => 'Badge icons can be up to 2 MB.',
            'badge_icon.dimensions' => 'Badge icons need to be at least 64x64.',
        ]);

        if ($validator->fails()) {
            return $this->fail('badges', $validator);
        }

        if (self::badgesFor((int) $this->place->id)->count() >= self::MAX_BADGES) {
            return $this->back('badges', null, 'You already have ' . self::MAX_BADGES . ' badges for this game.');
        }

        $filename = uniqid() . '.' . strtolower($request->file('badge_icon')->extension());
        $request->file('badge_icon')->move($this->reviewing(), $filename);

        Asset::create([
            'asset_type' => 21,
            'title' => trim($request->input('badge_name')),
            'author' => $this->user['id'],
            'file' => $filename,
            'description' => trim((string) $request->input('badge_description', '')),
            'visibility' => 'r',
            'additional' => ['placeId' => (int) $this->place->id, 'media' => ['thumbnail' => null]],
        ]);

        Discord::send($this->user['username'] . ' made a game badge, moderate it! [ ' . config('app.url') . '/admin/assets ]');

        return $this->back('badges', "Badge made! It'll work in-game once a moderator approves the icon.");
    }

    private function deleteBadge(Request $request)
    {
        $badge = self::badgesFor((int) $this->place->id)->firstWhere('id', (int) $request->input('badge_id'));

        if (!$badge) {
            return $this->back('badges', null, "That badge doesn't exist.");
        }

        PlaceBadgeAward::where('badge_id', $badge->id)->delete();
        File::delete(public_path('dynamic/reviewing/' . basename($badge->file)));
        File::delete(config('finobe.cdn_path') . '/thumbnails/' . basename($badge->file));
        $badge->delete();

        return $this->back('badges', 'Badge deleted.');
    }

    public static function badgesFor(int $placeId)
    {
        return Asset::where('asset_type', 21)
            ->where('additional', 'like', '%"placeId":' . $placeId . '%')
            ->orderBy('id')
            ->get()
            ->filter(fn($b) => (int) ($b->additional['placeId'] ?? 0) === $placeId)
            ->values();
    }

    // ---------- user restrictions ----------

    private function saveRestrictions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'access' => 'required|in:' . implode(',', PlaceAccess::ACCESS),
            'min_account_age' => 'nullable|integer|min:0|max:3650',
            'blocked' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return $this->fail('restrictions', $validator);
        }

        $names = array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $request->input('blocked', '')))));
        $ids = $names ? User::whereIn('username', $names)->pluck('id')->map(fn($id) => (int) $id)->all() : [];
        $ids = array_values(array_diff($ids, [(int) $this->place->author]));
        $unknown = count($names) - count(User::whereIn('username', $names)->pluck('id'));

        $additional = $this->place->additional;
        $additional['restrictions'] = [
            'access' => $request->input('access'),
            'min_account_age' => (int) $request->input('min_account_age', 0),
            'blocked' => $ids,
        ];
        $this->place->additional = $additional;
        $this->place->save();

        return $this->back('restrictions', 'Saved!' . ($unknown > 0 ? " ({$unknown} username(s) weren't found and got skipped)" : ''));
    }
}
