<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ResetPassword;
use App\Models\User;
use App\Services\Mailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PasswordResetController extends Controller
{
    private const LIFETIME_MINUTES = 60;

    public function request()
    {
        return $this->page('Reset', [], 'Reset Password');
    }

    public function send(Request $request)
    {
        $email = (string) $request->input('email');
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? User::where('email', $email)->first() : null;

        if ($user) {
            ResetPassword::where('username', $user->username)->where('used', false)->update(['used' => true]);

            $token = bin2hex(random_bytes(32));
            $reset = ResetPassword::create([
                'username' => $user->username,
                'uid' => hash('sha256', $token),
                'date' => now(),
                'expire' => now()->addMinutes(self::LIFETIME_MINUTES),
                'used' => false,
            ]);

            $html = Mailer::template('reset_password_template.php', [
                'UUID' => $user->id,
                'RESETID' => $token,
            ]);

            if (!Mailer::send($user->email, $user->username, 'Finobe Password Reset', $html)) {
                $reset->delete();
                $request->session()->put('error', 'There was an error while sending the email, please try again.');

                return redirect('/password/reset');
            }
        }

        $request->session()->put('success', 'If that email belongs to an account, a reset link is on its way.');

        return redirect('/password/reset');
    }

    public function show(int $id, string $token)
    {
        if (!$this->find($id, $token)) {
            session()->put('error', 'That reset link is invalid or has expired.');

            return redirect('/password/reset');
        }

        return $this->page('Reset', ['reset' => true], 'Reset Password');
    }

    public function reset(Request $request, int $id, string $token)
    {
        $found = $this->find($id, $token);

        if (!$found) {
            $request->session()->put('error', 'That reset link is invalid or has expired.');

            return redirect('/password/reset');
        }

        $validator = Validator::make($request->all(), ['password' => 'required|string|alpha_dash|min:8|max:255']);

        if ($validator->fails()) {
            $request->session()->put('error', $validator->errors()->first());

            return redirect('/password/verify/' . $id . '/' . $token);
        }

        [$user, $reset] = $found;

        DB::connection('finobe')->transaction(function () use ($user, $reset, $request) {
            $reset->update(['used' => true]);
            $user->forceFill(['password' => Hash::make((string) $request->input('password')), 'remember_token' => null])->save();
        });

        DB::connection('finobe')->table('sessions')->where('user_id', $user->id)->delete();
        Auth::logout();
        $request->session()->put('success', 'Successfully reset');

        return redirect('/login');
    }

    private function find(int $id, string $token): ?array
    {
        $user = User::find($id);

        if (!$user || !ctype_xdigit($token)) {
            return null;
        }

        $reset = ResetPassword::where('username', $user->username)
            ->where('uid', hash('sha256', $token))
            ->where('used', false)
            ->where('expire', '>', now())
            ->first();

        return $reset ? [$user, $reset] : null;
    }
}
