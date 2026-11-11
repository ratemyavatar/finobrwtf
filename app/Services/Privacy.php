<?php

namespace App\Services;

use App\Models\User;

class Privacy
{
    const SETTINGS = [
        'messages' => ['label' => 'Who can send me messages', 'levels' => ['everyone', 'friends', 'nobody']],
        'friend_requests' => ['label' => 'Who can send me friend requests', 'levels' => ['everyone', 'nobody']],
        'inventory' => ['label' => 'Who can see my inventory', 'levels' => ['everyone', 'friends', 'nobody']],
        'trades' => ['label' => 'Who can send me trades', 'levels' => ['everyone', 'friends', 'nobody']],
    ];
    const LEVELS = ['everyone' => 'Everyone', 'friends' => 'Friends', 'nobody' => 'No one'];

    public static function level(User $user, string $what): string
    {
        $level = $user->{'privacy_' . $what} ?? 'everyone';
        return in_array($level, self::SETTINGS[$what]['levels'] ?? [], true) ? $level : 'everyone';
    }

    public static function areFriends(User $a, User $b): bool
    {
        foreach ((array) ($a->friends ?? []) as $f) {
            if ((int) ($f['userid'] ?? 0) === (int) $b->id && ($f['status'] ?? '') === 'friends') {
                return true;
            }
        }
        return false;
    }

    public static function allows(User $owner, string $what, ?User $viewer): bool
    {
        if ($viewer && ((int) $viewer->id === (int) $owner->id || ($viewer->status ?? '') === 'admin')) {
            return true;
        }
        return match (self::level($owner, $what)) {
            'nobody' => false,
            'friends' => $viewer !== null && self::areFriends($owner, $viewer),
            default => true,
        };
    }
}
