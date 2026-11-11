<?php

namespace App\Services;

final class WordFilter
{
    private const PHRASES = ['fuck', 'fucking', 'roblox', 'rob lox', 'robux', 'ass', 'asshole', 'shit', 'r*blox'];

    private const REPLACEMENTS = ['OBAMA BALL', 'sonic 06', 'blockland.us'];

    public static function apply(?string $text): string
    {
        $pattern = '/\b(' . implode('|', array_map(fn ($p) => preg_quote($p, '/'), self::PHRASES)) . ')\b/i';

        return preg_replace_callback($pattern, fn () => self::REPLACEMENTS[array_rand(self::REPLACEMENTS)], (string) $text);
    }
}
