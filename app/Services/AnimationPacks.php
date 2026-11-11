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
        'r15_2016' => [
            'label' => '2016 R15 Animations',
            'description' => 'The old 2016 R15 movement. In game your character uses the R15 body.',
            'rig' => 'R15',
            'anims' => [
                'climb' => ['ClimbAnim', 387946868],
                'fall' => ['FallAnim', 387948187],
                'idle' => [['Animation1', 387947158, 9], ['Animation2', 387947464, 1], ['Animation3', 387947796, 1]],
                'jump' => ['JumpAnim', 387946624],
                'run' => ['RunAnim', 387947975],
                'sit' => ['SitAnim', 178130996],
                'toolnone' => ['ToolNoneAnim', 387947975],
                'walk' => ['WalkAnim', 382460631],
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
        if (($rig = self::PACKS[$pack]['rig'] ?? null) !== null) {
            $lines[] = "rig {$rig} 0";
        }
        foreach (self::PACKS[$pack]['anims'] ?? [] as $config => $anims) {
            foreach (is_array($anims[0]) ? $anims : [$anims] as $anim) {
                $lines[] = "{$config} {$anim[0]} {$baseUrl}/asset/?id={$anim[1]}" . (isset($anim[2]) ? " {$anim[2]}" : '');
            }
        }

        return implode("\n", $lines);
    }
}
