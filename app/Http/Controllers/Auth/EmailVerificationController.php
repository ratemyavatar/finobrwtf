<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailVerify;
use App\Models\User;
use App\Services\Mailer;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function send(Request $request)
    {
        $user = $request->user();

        EmailVerify::where('username', $user->username)->where('used', false)->update(['used' => true]);

        $token = bin2hex(random_bytes(32));
        $verify = EmailVerify::create([
            'username' => $user->username,
            'uid' => hash('sha256', $token),
            'date' => now(),
            'expire' => now()->addDay(),
            'used' => false,
        ]);

        $html = Mailer::template('verify_email_template.php', [
            'UUID' => $user->id,
            'RESETID' => $token,
        ]);

        if (!Mailer::send($user->email, $user->username, 'Verify Email Address', $html)) {
            $verify->delete();
            $request->session()->put('error', 'There was an error while sending the email, please try again.');

            return redirect('/');
        }

        $request->session()->put('success', 'Sent.');

        return redirect('/');
    }

    public function verify(Request $request, int $id, string $token)
    {
        $user = User::find($id);
        $verify = $user && ctype_xdigit($token)
            ? EmailVerify::where('username', $user->username)->where('uid', hash('sha256', $token))
                ->where('used', false)->where('expire', '>', now())->first()
            : null;

        if (!$verify) {
            $request->session()->put('error', 'That verification link is invalid or has expired.');

            return redirect('/');
        }

        $verify->update(['used' => true]);
        $user->forceFill(['verified' => 'y'])->save();
        $request->session()->put('success', 'Verified email');

        return redirect('/');
    }
}
