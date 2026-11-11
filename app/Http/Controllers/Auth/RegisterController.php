<?php

namespace App\Http\Controllers\Auth;

use App\Admin\Settings;
use App\Http\Controllers\Controller;
use App\Jobs\RenderAvatar;
use App\Models\Keys;
use App\Models\User;
use App\Services\Discord;
use App\Services\Turnstile;
use App\Services\Usernames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    public function landing()
    {
        return $this->page('Register', ['invitekeys' => Settings::invitesRequired()], 'Register');
    }

    public function form()
    {
        return $this->page('Form', [
            'invitekeys' => Settings::invitesRequired(),
            'turnstile' => Turnstile::enabled(),
            'turnstile_sitekey' => config('finobe.turnstile.sitekey'),
        ], 'Form');
    }

    public function store(Request $request)
    {
        $invitesRequired = Settings::invitesRequired();
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|min:3|max:20',
            'password' => 'required|string|confirmed|alpha_dash|min:8|max:255',
            'email' => 'required|email|confirmed|unique:finobe.users,email',
            'invite_key' => ($invitesRequired ? 'required' : 'nullable') . '|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->fail($request, $validator->errors()->first());
        }

        if (!config('admin.invites_enabled')) {
            return $this->fail($request, 'Account creation is currently disabled');
        }

        $username = trim((string) $request->input('username'));

        if ($problem = Usernames::problem($username)) {
            return $this->fail($request, $problem);
        }

        if (!Turnstile::passes($request->input('cf-turnstile-response'), $request->ip())) {
            return $this->fail($request, 'CAPTCHA failed.');
        }

        $inviteKey = trim((string) $request->input('invite_key', ''));

        $user = DB::connection('finobe')->transaction(function () use ($request, $username, $inviteKey) {
            if ($inviteKey !== '') {
                $claimed = Keys::where('IID', $inviteKey)->where('used', false)
                    ->update(['used' => true, 'dateUsed' => now(), 'usedBy' => $username]);

                if (!$claimed) {
                    return null;
                }
            }

            return User::create([
                'username' => $username,
                'email' => trim((string) $request->input('email')),
                'password' => Hash::make((string) $request->input('password')),
                'friends' => [],
                'inventory' => [],
                'badges' => [],
                'avatar' => [User::defaultAvatar()],
                'token' => bin2hex(random_bytes(30)),
            ]);
        });

        if (!$user) {
            return $this->fail($request, 'Invalid invite key.');
        }

        Discord::send($username . ' has signed up');
        Auth::login($user);
        $request->session()->regenerate();
        RenderAvatar::dispatchAfterResponse($user->id);

        return redirect('/legal/welcome');
    }

    private function fail(Request $request, string $message)
    {
        $request->session()->put('error', $message);

        return redirect('/register/form');
    }
}
