<?php

namespace App\Services;

use App\Http\Controllers\Client\AuthenticationController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class StudioAuth
{
    const TOKEN_SECONDS = 3600;

    public static function user(Request $request): ?User
    {
        if (Auth::check()) {
            return Auth::user();
        }

        $token = (string) $request->query('token', '');
        if ($token !== '' && preg_match('/^[a-f0-9]{48}$/', $token)) {
            $id = Cache::get("studio_token_{$token}");
            if ($id) {
                return User::find((int) $id);
            }
        }

        $id = AuthenticationController::clientUserId($request);

        return $id ? User::find($id) : null;
    }

    public static function token(User $user): string
    {
        $token = bin2hex(random_bytes(24));
        Cache::put("studio_token_{$token}", (int) $user->id, self::TOKEN_SECONDS);

        return $token;
    }

    public static function banned(?User $user): bool
    {
        return !$user || \App\Http\Middleware\Moderation::restricted($user);
    }
}
