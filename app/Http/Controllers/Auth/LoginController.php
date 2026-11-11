<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function show(Request $request)
    {
        return $this->page('Login', [
            'errorlogin' => $request->session()->pull('errorlogin', false),
            'next' => self::safeNext($request->query('next')),
        ], 'Login');
    }

    public function store(Request $request)
    {
        $email = (string) $request->input('email');
        $password = (string) $request->input('password');
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '' ? User::where('email', $email)->first() : null;

        if (!$user || !Hash::check($password, $user->password)) {
            $request->session()->put('errorlogin', true);

            return redirect('/login');
        }

        $request->session()->regenerate();

        if (TwoFactor::enabled($user)) {
            $request->session()->put('2fa_login', ['id' => $user->id, 'expires' => time() + 600, 'next' => self::safeNext($request->input('next'))]);

            return redirect('/2fa/validate');
        }

        Auth::login($user, $request->boolean('remember_me') || $request->boolean('remember'));
        $request->session()->put('success', 'Successfully logged in.');

        return redirect(self::safeNext($request->input('next')) ?? '/');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public static function safeNext(mixed $next): ?string
    {
        if (!is_string($next) || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')) {
            return null;
        }

        return $next;
    }
}
