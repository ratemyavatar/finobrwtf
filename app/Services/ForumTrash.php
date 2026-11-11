<?php

namespace App\Services;

class ForumTrash
{
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    public static function phrases(): array
    {
        return array_values(array_filter(array_map(
            fn ($phrase) => self::normalize((string) $phrase),
            (array) config('forum.trash', [])
        )));
    }

    public static function matches(string $title): bool
    {
        $text = ' ' . self::normalize($title) . ' ';

        foreach (self::phrases() as $phrase) {
            if (str_contains($text, ' ' . $phrase . ' ')) {
                return true;
            }
        }

        return false;
    }
}
