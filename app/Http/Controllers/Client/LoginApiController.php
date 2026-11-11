<?php

namespace App\Http\Controllers\Client;

use App\Admin\Workbench;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlayerBadges;
use App\Services\TwoFactor;
use App\Support\AvatarImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginApiController extends Controller
{
    const DUMMY_HASH = '$2y$12$EUBQ0KD8QmbmTkq5l3WxU.8mMowQmgiN/AF7aVDqY4WJfuTbszVmi';

    public function login(Request $request)
    {
        [$user, $failure] = self::attempt($request);

        if ($failure !== null) {
            return match ($failure) {
                'missing' => self::error(3, 'Username and password are required. Please try again.', 400),
                'locked' => self::error(4, 'Your account has been locked. Log in on the website for details.', 403),
                'twostep' => self::error(5, 'Two step verification is on for this account. Log in on the website instead.', 403),
                default => self::error(1, 'Incorrect username or password. Please try again.', 403),
            };
        }

        return response()->json([
            'userId' => (int) $user->id,
            'membership' => PlayerBadges::membershipType((int) $user->id),
            'username' => $user->username,
            'isUnder13' => false,
            'countryCode' => 'US',
        ]);
    }

    public function mobile(Request $request)
    {
        [$user, $failure] = self::attempt($request);

        if ($failure !== null) {
            return response()->json(['Status' => match ($failure) {
                'missing' => 'MissingRequiredField',
                'locked' => 'AccountNotApproved',
                'twostep' => 'TwoStepVerificationRequired',
                default => 'InvalidPassword',
            }]);
        }

        $thumbnail = AvatarImage::url($user->pfp);
        if (str_starts_with($thumbnail, '/')) {
            $thumbnail = 'https://www.' . config('finobe.domain') . $thumbnail;
        }

        return response()->json([
            'Status' => 'OK',
            'UserInfo' => [
                'userId' => (int) $user->id,
                'username' => $user->username,
                'RobuxBalance' => (int) $user->Dius,
                'IsAnyBuildersClubMember' => PlayerBadges::membershipType((int) $user->id) !== 'None',
                'ThumbnailUrl' => $thumbnail,
            ],
        ]);
    }

    private static function attempt(Request $request): array
    {
        $name = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($name === '' || $password === '' || strlen($name) > 255 || strlen($password) > 1024) {
            return [null, 'missing'];
        }

        $user = str_contains($name, '@')
            ? User::where('email', $name)->first()
            : User::whereRaw('LOWER(username) = ?', [strtolower($name)])->first();

        $valid = Hash::check($password, $user->password ?? self::DUMMY_HASH);

        if (!$user || !$valid) {
            return [null, 'invalid'];
        }

        if (in_array($user->status ?? '', ['banned', 'terminated'], true) || self::hasActiveBan($user)) {
            return [null, 'locked'];
        }

        if (TwoFactor::enabled($user)) {
            return [null, 'twostep'];
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        Auth::login($user);

        return [$user, null];
    }

    private static function hasActiveBan(User $user): bool
    {
        return DB::connection('finobe')->table('bans')
            ->where('username', $user->username)
            ->where('reactivated', Workbench::flag('bans', 'reactivated', false))
            ->where('undone', Workbench::flag('bans', 'undone', false))
            ->exists();
    }

    private static function error(int $code, string $message, int $status)
    {
        return response()->json(['errors' => [['code' => $code, 'message' => $message]]], $status);
    }
}
