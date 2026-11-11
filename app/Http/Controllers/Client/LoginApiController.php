<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginApiController extends Controller
{
    const DUMMY_HASH = '$2y$12$EUBQ0KD8QmbmTkq5l3WxU.8mMowQmgiN/AF7aVDqY4WJfuTbszVmi';

    public function login(Request $request)
    {
        $name = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($name === '' || $password === '' || strlen($name) > 255 || strlen($password) > 1024) {
            return self::error(3, 'Username and password are required. Please try again.', 400);
        }

        $user = str_contains($name, '@')
            ? User::where('email', $name)->first()
            : User::whereRaw('LOWER(username) = ?', [strtolower($name)])->first();

        $valid = Hash::check($password, $user->password ?? self::DUMMY_HASH);

        if (!$user || !$valid) {
            return self::error(1, 'Incorrect username or password. Please try again.', 403);
        }

        if (($user->banned ?? false) || in_array($user->status ?? '', ['banned', 'terminated'], true)) {
            return self::error(4, 'Your account has been locked. Log in on the website for details.', 403);
        }

        if (TwoFactor::enabled($user)) {
            return self::error(5, 'Two step verification is on for this account. Log in on the website instead.', 403);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        Auth::login($user);

        return response()->json([
            'userId' => (int) $user->id,
            'membership' => \App\Services\PlayerBadges::membershipType((int) $user->id),
            'username' => $user->username,
            'isUnder13' => false,
            'countryCode' => 'US',
        ]);
    }

    private static function error(int $code, string $message, int $status)
    {
        return response()->json(['errors' => [['code' => $code, 'message' => $message]]], $status);
    }
}
