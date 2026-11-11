<?php

namespace App\Services;

class AnimationPacks
{
    const DEFAULT = 'default';

    const PACKS = [
        'default' => [
            'label' => 'Default',
            'description' => 'The normal in-game movement animations.',
            'anims' => [],
        ],
        'test2014' => [
            'label' => '2014 Test Animations',
            'description' => "zeuxcg's leaked walk and climb tests from May 2014.",
            'anims' => [
                'walk' => ['WalkAnim', 157760076],
                'run' => ['RunAnim', 157760076],
                'climb' => ['ClimbAnim', 157760107],
            ],
        ],
    ];

    public static function exists(string $pack): bool
    {
        return isset(self::PACKS[$pack]);
    }

    public static function forUser($user): string
    {
        $avatar = is_array($user?->avatar ?? null) ? $user->avatar : [];
        $pack = (string) ($avatar[0]['animations'] ?? self::DEFAULT);

        return self::exists($pack) ? $pack : self::DEFAULT;
    }

    public static function gameServerLines(string $pack, string $baseUrl): string
    {
        $lines = [];
        foreach (self::PACKS[$pack]['anims'] ?? [] as $config => [$child, $assetId]) {
            $lines[] = "{$config} {$child} {$baseUrl}/asset/?id={$assetId}";
        }

        return implode("\n", $lines);
    }
}
