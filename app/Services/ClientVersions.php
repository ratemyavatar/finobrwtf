<?php

namespace App\Services;

class ClientVersions
{
    public const DEFAULT = '2016';

    public static function enabled(): array
    {
        $all = array_filter(config('finobe.clients', []), fn($c) => !empty($c['enabled']));
        ksort($all);
        return $all;
    }

    public static function names(): array
    {
        return array_map('strval', array_keys(self::enabled()));
    }

    public static function isValid(?string $version): bool
    {
        return $version !== null && in_array($version, self::names(), true);
    }

    public static function of($placeOrAdditional): string
    {
        $additional = $placeOrAdditional;
        if (is_object($additional) && property_exists($additional, 'additional')) {
            $additional = json_decode($additional->additional ?? '{}', true) ?: [];
        }
        $version = is_array($additional) ? ($additional['version'] ?? null) : ($additional->version ?? null);

        return $version !== null && $version !== '' && config('finobe.clients.' . $version) ? (string) $version : self::DEFAULT;
    }

    public static function get(string $version): array
    {
        return config("finobe.clients.{$version}") ?? config('finobe.clients.' . self::DEFAULT);
    }

    public static function usesRcc(string $version): bool
    {
        return (self::get($version)['server'] ?? 'rcc') === 'rcc';
    }

    public static function joinFormat(string $version): string
    {
        return self::get($version)['join'] ?? 'json';
    }

    public static function rcc(string $version): array
    {
        $c = self::get($version);

        $host = trim((string) ($c['rcc_host'] ?? '')) ?: (string) config('finobe.arbiter.host');
        $port = (int) ($c['rcc_port'] ?? 0) ?: (int) config('finobe.arbiter.port');
        return [$host, $port];
    }

    public static function gameserverHost(string $version): string
    {
        return trim((string) (self::get($version)['gameserver_host'] ?? '')) ?: (string) config('finobe.gameserver.host');
    }

    public static function scheme(string $version): string
    {
        return self::get($version)['scheme'] ?? 'https';
    }
}
