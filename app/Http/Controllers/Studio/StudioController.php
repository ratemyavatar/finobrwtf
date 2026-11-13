<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\PlaceThumbnail;
use App\Services\StudioAuth;
use App\Services\TwoFactor;
use App\Support\AvatarImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class StudioController extends Controller
{
    const TEMPLATES = [
        'baseplate' => ['Baseplate', '2016.rbxl'],
    ];

    public function welcome(Request $request)
    {
        $user = Auth::user();
        $places = [];

        if ($user) {
            $places = Asset::where('author', $user->id)->where('asset_type', 9)
                ->orderByDesc('updated')->limit(60)->get()
                ->map(fn ($place) => self::placeCard($place))->all();
        }

        return response()->view('studio.welcome', [
            'user' => $user,
            'avatar' => $user ? AvatarImage::url($user->pfp ?? null) : null,
            'places' => $places,
            'templates' => collect(self::TEMPLATES)->map(fn ($t, $key) => ['key' => $key, 'name' => $t[0]])->values()->all(),
            'domain' => config('finobe.domain'),
            'slots' => $user ? max(0, (int) $user->slots - count($places)) : 0,
        ])->header('Cache-Control', 'no-store');
    }

    public static function placeCard(Asset $place): array
    {
        $additional = is_array($place->additional) ? $place->additional : (json_decode((string) $place->additional, true) ?: []);

        return [
            'id' => (int) $place->id,
            'title' => (string) $place->title,
            'thumbnail' => PlaceThumbnail::url($additional),
            'updated' => date('M j, Y', strtotime((string) $place->updated)),
            'version' => (string) ($additional['version'] ?? ''),
            'visits' => (int) ($additional['visits'] ?? 0),
        ];
    }

    public function loginForm(Request $request)
    {
        if (Auth::check()) {
            return redirect('/ide/welcome');
        }

        return response()->view('studio.login', [
            'error' => $request->session()->pull('studio_error'),
            'domain' => config('finobe.domain'),
        ])->header('Cache-Control', 'no-store');
    }

    public function login(Request $request)
    {
        $email = trim((string) $request->input('email'));
        $password = (string) $request->input('password');
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '' ? User::where('email', $email)->first() : null;

        if (!$user || !Hash::check($password, $user->password)) {
            $request->session()->put('studio_error', 'That email or password is wrong.');

            return redirect('/ide/login');
        }

        $request->session()->regenerate();

        if (TwoFactor::enabled($user)) {
            $request->session()->put('2fa_login', ['id' => $user->id, 'expires' => time() + 600, 'next' => '/ide/welcome']);

            return redirect('/ide/login/2fa');
        }

        Auth::login($user, true);

        return self::signedIn();
    }

    private static function signedIn()
    {
        return redirect('/ide/welcome')->withCookie(cookie('.ROBLOSECURITY', bin2hex(random_bytes(16)), 60 * 24 * 30, '/', '.' . config('finobe.domain'), null, true, false, 'lax'));
    }

    public function twoFactorForm(Request $request)
    {
        if (!$this->pendingUser($request)) {
            return redirect('/ide/login');
        }

        return response()->view('studio.twofactor', [
            'error' => $request->session()->pull('studio_error'),
            'domain' => config('finobe.domain'),
        ])->header('Cache-Control', 'no-store');
    }

    public function twoFactor(Request $request)
    {
        $user = $this->pendingUser($request);

        if (!$user) {
            return redirect('/ide/login');
        }

        if (!TwoFactor::verify($user, (string) $request->input('code'))) {
            $request->session()->put('studio_error', "That code isn't right.");

            return redirect('/ide/login/2fa');
        }

        $request->session()->forget('2fa_login');
        Auth::login($user, true);
        $request->session()->regenerate();

        return self::signedIn();
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get('2fa_login');

        if (!$pending || $pending['expires'] < time()) {
            $request->session()->forget('2fa_login');

            return null;
        }

        return User::find($pending['id']);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/ide/welcome')->withCookie(cookie()->forget('.ROBLOSECURITY', '/', '.' . config('finobe.domain')));
    }

    public function toolbox(Request $request)
    {
        $user = Auth::user();

        return response()->view('studio.toolbox', [
            'user' => $user,
            'categories' => StudioToolbox::categories($user !== null),
            'domain' => config('finobe.domain'),
        ])->header('Cache-Control', 'no-store');
    }

    public function toolboxItems(Request $request)
    {
        return response()->json(StudioToolbox::search(
            Auth::user(),
            (string) $request->query('category', 'models'),
            trim((string) $request->query('q', '')),
            max(1, min(500, (int) $request->query('page', 1)))
        ))->header('Cache-Control', 'no-store');
    }

    public function uploadModel(Request $request)
    {
        $user = Auth::user();

        return response()->view('studio.upload', [
            'user' => $user,
            'token' => $user && !StudioAuth::banned($user) ? StudioAuth::token($user) : null,
            'domain' => config('finobe.domain'),
        ])->header('Cache-Control', 'no-store');
    }

    public function publish(Request $request)
    {
        $user = Auth::user();
        $places = [];

        if ($user) {
            $places = Asset::where('author', $user->id)->where('asset_type', 9)
                ->orderByDesc('updated')->limit(200)->get()
                ->map(fn ($place) => self::placeCard($place))->all();
        }

        return response()->view('studio.publish', [
            'user' => $user,
            'places' => $places,
            'token' => $user && !StudioAuth::banned($user) ? StudioAuth::token($user) : null,
            'canCreate' => $user && config('finobe.features.create_places') && count($places) < (int) $user->slots,
            'versions' => \App\Services\ClientVersions::names(),
            'domain' => config('finobe.domain'),
        ])->header('Cache-Control', 'no-store');
    }

    public function projects(Request $request)
    {
        $user = Auth::user();
        $places = $user ? Asset::where('author', $user->id)->where('asset_type', 9)
            ->orderByDesc('updated')->limit(200)->get()
            ->map(fn ($place) => self::placeCard($place))->all() : [];

        return response()->view('studio.projects', [
            'user' => $user,
            'places' => $places,
            'domain' => config('finobe.domain'),
        ])->header('Cache-Control', 'no-store');
    }
}
