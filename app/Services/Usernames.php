<?php

namespace App\Services;

use App\Models\User;

final class Usernames
{
    public const PATTERN = '/^(?!_)(?!.*_$)(?!.*_.*_)[A-Za-z0-9_]{3,20}$/';

    public const FORBIDDEN = [
        'raped', 'dick', 'fuck', 'shit', 'fag', 'f@g', 'd1ck', 'pussy',
        'jew', 'tranny', 'tr@nny', 'goon', 'g@@n', 'g00n', 'gyat', 'gy@t', 'r@ped', 'tities', 't1t1es',
        'nigg', 'n!gger', 'nigga', 'n1gga', 'n1gger', 'pedo', 'faggot',
    ];

    public static function problem(string $name, ?int $ignoreUserId = null): ?string
    {
        if (!preg_match(self::PATTERN, $name)) {
            return 'Usernames are 3 to 20 letters, numbers or one underscore (not at the start or end).';
        }

        $lower = strtolower($name);

        foreach (self::FORBIDDEN as $phrase) {
            if (str_contains($lower, $phrase)) {
                return "You can't use that username.";
            }
        }

        $taken = User::whereRaw('LOWER(username) = ?', [$lower])
            ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
            ->exists();

        return $taken ? 'That username is taken.' : null;
    }
}
