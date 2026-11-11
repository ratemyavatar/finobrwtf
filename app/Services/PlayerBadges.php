<?php

namespace App\Services;

class PlayerBadges
{
    const SPECIAL = [];

    public static function membershipType(int $userId): string
    {
        return self::SPECIAL[$userId] ?? 'None';
    }

    public static function showsAdminIcon(int $userId, ?string $status): bool
    {
        return $status === 'admin' && !isset(self::SPECIAL[$userId]);
    }
}
