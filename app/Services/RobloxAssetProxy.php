<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RobloxAssetProxy
{
    private const IMAGE_TYPES = [1, 13];
    private const CACHE_OK = 2592000;
    private const CACHE_MISS = 600;

    public static function fetch(int $assetId, int $versionId = 0): array
    {
        $key = $versionId > 0 ? "roblox_assetversion_b64_{$versionId}" : "roblox_asset_b64_{$assetId}";
        $cached = self::cacheGet($key);
        if (is_string($cached) && $cached !== '' && ($decoded = base64_decode($cached, true)) !== false) {
            return [$decoded, 200];
        }
        if (($miss = self::cacheGet("{$key}_miss")) !== null) {
            return ['', (int) $miss];
        }

        [$body, $status] = self::assetDelivery($versionId > 0 ? ['assetVersionId' => $versionId] : ['id' => $assetId]);

        if ($body === '' && $assetId > 0 && in_array($status, [401, 403, 409], true)
            && in_array(self::assetType($assetId), self::IMAGE_TYPES, true)) {
            $body = self::thumbnail($assetId);
            $status = $body !== '' ? 200 : $status;
        }

        if ($body !== '') {
            self::cachePut($key, base64_encode($body), self::CACHE_OK);
        } elseif ($status >= 400 && $status < 500 && $status !== 429) {
            self::cachePut("{$key}_miss", $status, self::CACHE_MISS);
        }

        return [$body, $status];
    }

    private static function cacheGet(string $key)
    {
        try {
            return Cache::get($key);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    private static function cachePut(string $key, $value, int $ttl): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function rewrite(string $body): string
    {
        if (!self::isXml($body)) {
            return $body;
        }
        $domain = config('finobe.domain');
        return str_ireplace(['roblox.com/asset', 'Accessory'], ["{$domain}/asset", 'Hat'], $body);
    }

    public static function contentType(string $body): string
    {
        return match (true) {
            str_starts_with($body, "\x89PNG") => 'image/png',
            str_starts_with($body, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($body, 'GIF8') => 'image/gif',
            str_starts_with($body, 'ID3'), str_starts_with($body, "\xFF\xFB") => 'audio/mpeg',
            str_starts_with($body, 'OggS') => 'audio/ogg',
            default => 'application/octet-stream',
        };
    }

    public static function thumbnailUrl(int $assetId, int $width = 420, int $height = 420): string
    {
        $size = self::thumbnailSize($width, $height);
        return Cache::remember("roblox_thumburl_{$assetId}_{$size}", 86400, function () use ($assetId, $size) {
            for ($try = 0; $try < 3; $try++) {
                $r = self::get('https://thumbnails.roblox.com/v1/assets', [
                    'assetIds' => $assetId, 'size' => $size, 'format' => 'Png', 'isCircular' => 'false',
                ]);
                $item = $r?->json('data.0');
                if (($item['state'] ?? '') === 'Completed' && !empty($item['imageUrl'])) {
                    return (string) $item['imageUrl'];
                }
                if (($item['state'] ?? '') !== 'Pending') {
                    break;
                }
                usleep(700000);
            }
            return '';
        }) ?: '';
    }

    private static function thumbnail(int $assetId): string
    {
        $url = self::thumbnailUrl($assetId, 512, 512);
        if ($url === '') {
            return '';
        }
        $r = self::get($url);
        $body = $r && $r->ok() ? $r->body() : '';
        return str_starts_with($body, "\x89PNG") ? $body : '';
    }

    private static function assetType(int $assetId): int
    {
        return (int) Cache::remember("roblox_assettype_{$assetId}", self::CACHE_OK, function () use ($assetId) {
            $r = self::get("https://economy.roblox.com/v2/assets/{$assetId}/details");
            return $r && $r->ok() ? (int) $r->json('AssetTypeId', 0) : 0;
        });
    }

    private static function assetDelivery(array $query): array
    {
        $headers = ['User-Agent' => 'Roblox/WinInet', 'requester' => 'server'];
        $cookie = (string) config('finobe.roblosecurity');
        if ($cookie !== '') {
            $headers['Cookie'] = '.ROBLOSECURITY=' . $cookie;
        }

        $status = 502;

        for ($try = 0; $try < 4; $try++) {
            $r = self::get('https://assetdelivery.roblox.com/v1/asset/', $query, $headers);
            $status = $r?->status() ?: 502;
            if ($r && $r->ok() && $r->body() !== '') {
                return [$r->body(), 200];
            }
            if ($status !== 429 && $status < 500) {
                break;
            }
            $wait = (int) ($r?->header('Retry-After') ?: 0);
            usleep(min(max($wait, 1), 3) * 1000000 * ($try + 1) / 2);
        }
        return ['', $status];
    }

    private static function get(string $url, array $query = [], array $headers = [])
    {
        try {
            return Http::withHeaders($headers)->accept('*/*')->timeout(15)->get($url, $query);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function isXml(string $body): bool
    {
        $head = ltrim(substr($body, 0, 64), "\xEF\xBB\xBF \t\r\n");
        return (str_starts_with($head, '<roblox') && !str_starts_with($head, '<roblox!')) || str_starts_with($head, '<?xml');
    }

    private static function thumbnailSize(int $width, int $height): string
    {
        $sizes = [30, 42, 50, 75, 110, 140, 150, 250, 420, 512, 700];
        $want = max($width, $height);
        foreach ($sizes as $s) {
            if ($s >= $want) {
                return "{$s}x{$s}";
            }
        }
        return '700x700';
    }
}
