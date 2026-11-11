<?php

namespace App\Services;

use App\Models\User;

class ClientTicket
{
    const LIFETIME = 900;

    public static function issue($user): string
    {
        $id = (int) ($user->id ?? 0);
        $expires = time() + self::LIFETIME;

        return "{$id}-{$expires}-" . self::sign($id, $expires, (string) ($user->token ?? ''));
    }

    public static function resolve($ticket): ?User
    {
        if (!is_string($ticket) || !preg_match('/^(\d{1,12})-(\d{10,12})-([0-9a-f]{48})$/', $ticket, $m)) {
            return null;
        }

        $id = (int) $m[1];
        $expires = (int) $m[2];

        if ($expires < time() || $expires > time() + self::LIFETIME) {
            return null;
        }

        $user = User::find($id);
        $token = (string) ($user->token ?? '');

        if (!$user || $token === '' || !hash_equals(self::sign($id, $expires, $token), $m[3])) {
            return null;
        }

        return $user;
    }

    private static function sign(int $id, int $expires, string $token): string
    {
        return substr(hash_hmac('sha256', "client-ticket|{$id}|{$expires}|{$token}", (string) config('app.key')), 0, 48);
    }
}
