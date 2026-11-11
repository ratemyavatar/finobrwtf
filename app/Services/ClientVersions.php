<?php

namespace App\Services;

/**
 * Which client a place runs on (2012 or 2016) and how that client is served: see 'clients' in
 * config/finobe.php. A place's version lives in its `additional` JSON; places from before versions were
 * stored are 2016.
 */
class ClientVersions
{
    public const DEFAULT = '2016';

    /** @return array<string, array> enabled versions, oldest first */
    public static function enabled(): array
    {
        $all = array_filter(config('finobe.clients', []), fn($c) => !empty($c['enabled']));
        ksort($all);
        return $all;
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_map('strval', array_keys(self::enabled()));
    }

    public static function isValid(?string $version): bool
    {
        return $version !== null && in_array($version, self::names(), true);
    }

    /** the version of a place row (or its decoded `additional`) */
    public static function of($placeOrAdditional): string
    {
        $additional = $placeOrAdditional;
        if (is_object($additional) && property_exists($additional, 'additional')) {
            $additional = json_decode($additional->additional ?? '{}', true) ?: [];
        }
        $version = is_array($additional) ? ($additional['version'] ?? null) : ($additional->version ?? null);
        // a year that isn't (or is no longer) a client here, e.g. 2017, runs on the default client
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

    /** [host, port] of the RCC SOAP endpoint for that version */
    public static function rcc(string $version): array
    {
        $c = self::get($version);
        // an empty value (e.g. a blank line in .env) means "use the 2016 RCC's machine"
        $host = trim((string) ($c['rcc_host'] ?? '')) ?: (string) config('finobe.arbiter.host');
        $port = (int) ($c['rcc_port'] ?? 0) ?: (int) config('finobe.arbiter.port');
        return [$host, $port];
    }

    /** public address players connect to for that version's game servers */
    public static function gameserverHost(string $version): string
    {
        return trim((string) (self::get($version)['gameserver_host'] ?? '')) ?: (string) config('finobe.gameserver.host');
    }

    public static function scheme(string $version): string
    {
        return self::get($version)['scheme'] ?? 'https';
    }
}
