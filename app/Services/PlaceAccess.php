<?php

namespace App\Services;

use App\Models\User;

class PlaceAccess
{
    const ACCESS = ['everyone', 'friends', 'me'];

    public static function restrictions(array $additional): array
    {
        $r = $additional['restrictions'] ?? [];

        return [
            'access' => in_array($r['access'] ?? null, self::ACCESS, true) ? $r['access'] : 'everyone',
            'min_account_age' => max(0, (int) ($r['min_account_age'] ?? 0)),
            'blocked' => array_values(array_map('intval', $r['blocked'] ?? [])),
        ];
    }

    public static function denyReason($place, $user): ?string
    {
        $place = (array) $place;
        $userId = (int) (is_array($user) ? $user['id'] : $user->id);
        $ownerId = (int) $place['author'];

        if ($userId === $ownerId) {
            return null;
        }

        $additional = is_array($place['additional']) ? $place['additional'] : (json_decode($place['additional'], true) ?: []);
        $r = self::restrictions($additional);

        if (in_array($userId, $r['blocked'], true)) {
            return "You can't join this game";
        }

        if ($r['access'] === 'me') {
            return 'This game is only open to its creator right now';
        }

        if ($r['access'] === 'friends') {
            $owner = User::find($ownerId);
            $friends = $owner ? ($owner->friends ?? []) : [];
            $isFriend = false;

            foreach ($friends as $friend) {
                if ((int) ($friend['userid'] ?? 0) === $userId && ($friend['status'] ?? '') === 'friends') {
                    $isFriend = true;
                    break;
                }
            }

            if (!$isFriend) {
                return 'This game is friends only';
            }
        }

        if ($r['min_account_age'] > 0) {
            $u = User::find($userId);
            $created = $u ? strtotime((string) ($u->created ?? '')) : false;

            if ($created && (time() - $created) < $r['min_account_age'] * 86400) {
                return "Your account needs to be at least {$r['min_account_age']} day(s) old to join this game";
            }
        }

        return null;
    }
}
