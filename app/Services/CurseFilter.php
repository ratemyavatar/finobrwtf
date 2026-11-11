<?php

namespace App\Services;

class CurseFilter
{
    const WORDS = [
        'fuck', 'fucking', 'fucked', 'fucker', 'motherfucker', 'shit', 'shitty', 'bullshit', 'bitch', 'bitches',
        'ass', 'asshole', 'bastard', 'damn', 'goddamn', 'dick', 'dickhead', 'cock', 'cunt', 'pussy', 'piss',
        'pissed', 'crap', 'whore', 'slut', 'wanker', 'twat', 'bollocks', 'retard', 'retarded', 'fag', 'faggot',
        'nigger', 'nigga', 'tranny', 'kys',
    ];

    public static function on(): bool
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        return $user && !empty($user->curse_filter);
    }

    public static function clean(?string $text): string
    {
        $text = (string) $text;
        static $pattern = null;
        $pattern ??= '/\b(' . implode('|', array_map(fn ($w) => preg_quote($w, '/'), self::WORDS)) . ')\b/i';
        return preg_replace_callback($pattern, fn ($m) => str_repeat('#', mb_strlen($m[0])), $text);
    }

    public static function forViewer(?string $text): string
    {
        return self::on() ? self::clean($text) : (string) $text;
    }
}
