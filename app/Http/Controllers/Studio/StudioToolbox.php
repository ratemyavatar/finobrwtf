<?php

namespace App\Http\Controllers\Studio;

use App\Models\Asset;
use App\Models\Purchases;
use App\Models\User;
use App\Services\WordFilter;

class StudioToolbox
{
    const PER_PAGE = 24;

    const CATEGORIES = [
        'models' => ['Models', 10, false],
        'decals' => ['Decals', 1, false],
        'audio' => ['Audio', 3, false],
        'my-models' => ['My Models', 10, true],
        'my-decals' => ['My Decals', 1, true],
        'my-audio' => ['My Audio', 3, true],
    ];

    public static function categories(bool $signedIn): array
    {
        $out = [];
        foreach (self::CATEGORIES as $key => [$name, $type, $mine]) {
            if ($mine && !$signedIn) {
                continue;
            }
            $out[] = ['key' => $key, 'name' => $name];
        }

        return $out;
    }

    public static function search(?User $user, string $category, string $q, int $page): array
    {
        if (!isset(self::CATEGORIES[$category])) {
            $category = 'models';
        }
        [, $type, $mine] = self::CATEGORIES[$category];

        if ($mine && !$user) {
            return ['items' => [], 'page' => 1, 'pages' => 1];
        }

        $query = Asset::where('asset_type', $type);
        if ($mine) {
            $query->where('author', $user->id)->whereIn('visibility', ['n', 'r']);
        } else {
            $query->where('visibility', 'n');
        }
        if ($q !== '') {
            $query->where('title', 'like', '%' . addcslashes(mb_substr($q, 0, 60), '%_\\') . '%');
        }

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $assets = $query->orderByDesc('id')->skip(($page - 1) * self::PER_PAGE)->take(self::PER_PAGE)->get();

        $owned = $user ? Purchases::where('username', $user->username)->where('type', 1)
            ->whereIn('assetid', $assets->pluck('id')->all())->pluck('assetid')->map(fn ($id) => (int) $id)->all() : [];

        $items = [];
        foreach ($assets as $asset) {
            $additional = is_array($asset->additional) ? $asset->additional : (json_decode((string) $asset->additional, true) ?: []);
            $price = (int) ($additional['price'] ?? 0);
            $usable = $asset->visibility === 'n'
                && ($type !== 10 || $price === 0 || ($user && ((int) $asset->author === (int) $user->id || in_array((int) $asset->id, $owned, true))));
            $items[] = [
                'id' => (int) $asset->id,
                'name' => WordFilter::apply((string) $asset->title),
                'type' => $category,
                'thumbnail' => self::thumbnail($asset, $additional),
                'pending' => $asset->visibility === 'r',
                'usable' => $usable,
                'insert' => $usable ? self::insertUrl((int) $type, (int) $asset->id) : null,
                'typeId' => (int) $type === 1 ? 13 : (int) $type,
            ];
        }

        return ['items' => $items, 'page' => $page, 'pages' => $pages];
    }

    public static function thumbnail(Asset $asset, array $additional): string
    {
        $thumb = $additional['media']['thumbnail'] ?? null;
        if (is_string($thumb) && preg_match('~^(https?://|/(?!/))~i', $thumb)) {
            return $thumb;
        }
        if ((int) $asset->asset_type === 1 && $asset->visibility === 'n') {
            return '/asset/?id=' . (int) $asset->id;
        }

        return (int) $asset->asset_type === 3 ? '/s/img/studio/audio.png' : '/s/img/studio/model.png';
    }

    public static function insertUrl(int $type, int $id): string
    {
        $base = 'http://www.' . config('finobe.domain') . '/asset/?id=';

        return match ($type) {
            1 => "{$base}studiodecal&target={$id}",
            3 => "{$base}studiosound&target={$id}",
            default => "{$base}{$id}",
        };
    }
}
