<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TwoFactorController extends Controller
{
    public function show(Request $request)
    {
        if (!$this->pendingUser($request)) {
            return redirect('/login');
        }

        return $this->page('TwoFactor', [], '2FA Validation');
    }

    public function verify(Request $request)
    {
        $user = $this->pendingUser($request);

        if (!$user) {
            return redirect('/login');
        }

        if (!TwoFactor::verify($user, (string) $request->input('code'))) {
            $request->session()->put('error', "That token isn't right.");

            return redirect('/2fa/validate');
        }

        $next = $request->session()->pull('2fa_login')['next'] ?? null;
        Auth::login($user, false);
        $request->session()->regenerate();
        $request->session()->put('success', 'Successfully logged in.');

        return redirect($next ?? '/');
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
}
