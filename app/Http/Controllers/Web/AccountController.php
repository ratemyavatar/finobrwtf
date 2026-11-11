<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\Keys;
use App\Models\Purchases;
use App\Models\EmailVerify;
use App\Models\ResetPassword;
use App\Http\Controllers\frontEnd;
use App\Http\Controllers\Controller;
use App\Http\Controllers\dataController as DataController;

class AccountController extends Controller
{
    protected $request;

    public function __construct(Request $request) {
        $frontend = new frontEnd($request);
        $this->request = $frontend->getData();
    }

    public function verify_email() {
        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(EmailVerify::where('username', $this->request['data']['user']['username'])->where('used', 'n')->exists()) {
            return redirect('/');
        }

        $verifyid = bin2hex(random_bytes(32));

        $html = file_get_contents(storage_path('verify_email_template.php'));
        $keywords = ['UUID', 'SIGNATURE', 'RESETID'];
	    $replacementValues = [$this->request['data']['user']['id'], bin2hex(random_bytes(16)), $verifyid];
        $html = str_replace($keywords, $replacementValues, $html);

        if((int)env('FINOBE_MAIL_MODE') == 1) {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'authorization' => 'Zoho-enczapikey ' . env('FINOBE_ZOHO_API_KEY'),
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
            ])->post('https://api.zeptomail.com/v1.1/email', [
                "from" => [
                    "address" => "noreply@finobe.wtf"
                ],
                "to" => [
                    [
                        "email_address" => [
                            "address" => $this->request['data']['user']['email'],
                            "name" => $this->request['data']['user']['username']
                        ]
                    ]
                ],
                "subject" => "Verify Email Address",
                "htmlbody" => $html
            ]);

            if(!$response->successful()) {
                Session::put('error', 'There was an error while sending the email, please try again. (this is most likely a issue with our backend system)');
                return redirect('/');
            }
        } elseif((int)env('FINOBE_MAIL_MODE') == 2) {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'X-Smtp2go-Api-Key' => env('FINOBE_SMTP2GO_API_KEY'),
                'content-type' => 'application/json',
            ])->post('https://us-api.smtp2go.com/v3/email/send', [
                "sender" => "Finobe <noreply@finobe.wtf>",
                "to" => $this->request['data']['user']['username'] . " <" . $this->request['data']['user']['email'] . ">",
                "subject" => "Verify Email Address",
                "html_body" => $html
            ]);

            if(!$response->successful() || !$response->json()['data']['succeeded']) {
                Session::put('error', 'There was an error while sending the email, please try again.');
                return redirect('/');
            }
        } elseif((int)env('FINOBE_MAIL_MODE') == 3) {
            try {
                Mail::html($html, function ($message) {
                    $message->to($this->request['data']['user']['email'], $this->request['data']['user']['username'])
                        ->from(config('mail.from.address'), config('mail.from.name'))
                        ->subject('Verify Email Address');
                });
            } catch (\Throwable $e) {
                report($e);
                Session::put('error', 'There was an error while sending the email, please try again.');
                return redirect('/');
            }
        }

        EmailVerify::create([
            'username' => $this->request['data']['user']['username'],
            'uid' => $verifyid
        ]);

        Session::put('success', 'Sent.');
        return redirect('/');
    }

    public function email_verify($id, $verifyid) {
        if(!User::find($id)) {
            Session::put('error', 'Unknown error');
            return redirect('/');
        }

        $user = User::find($id);

        if(!EmailVerify::where('username', $user->username)->where('uid', $verifyid)->where('used', 'n')->exists()) {
            Session::put('error', 'Session not found');
            return redirect('/');
        }

        $email = EmailVerify::where('username', $user->username)
            ->where('uid', $verifyid)
            ->where('used', 'n')
            ->first();
        
        $email->used = 'y';
        $email->save();
        
        $user->verified = 'y';
        $user->save();

        Session::put('success', 'Verified email');
        return redirect('/');
    }

    public function password_reset() {
        $this->request['data']['embeds']['title'] = 'Reset Password' . $this->request['data']['embeds']['title'];

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Reset', $this->request);
    }

    public function password_email(Request $request) {
        $data = $request->all();

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        $validator = Validator::make($data, [
            'email' => 'required|email'
        ]);

        if($validator->fails() || !User::where('email', $data['email'])->exists()) {
            return redirect('/password/reset');
        }

        $user = User::where('email', $data['email'])->first();

        if(ResetPassword::where('username', $user->username)->where('used', 'n')->exists()) {
            return redirect('/password/reset');
        }

        $resetid = bin2hex(random_bytes(32));

        $session = ResetPassword::create([
            'username' => $user->username,
            'uid' => $resetid
        ]);

        $html = file_get_contents(storage_path('reset_password_template.php'));
        $keywords = ['UUID', 'SIGNATURE', 'RESETID'];
	    $replacementValues = [$user->id, bin2hex(random_bytes(16)), $resetid];
        $html = str_replace($keywords, $replacementValues, $html);

        if((int)env('FINOBE_MAIL_MODE') == 1) {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'authorization' => 'Zoho-enczapikey ' . env('FINOBE_ZOHO_API_KEY'),
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
            ])->post('https://api.zeptomail.com/v1.1/email', [
                "from" => [
                    "address" => "noreply@finobe.wtf"
                ],
                "to" => [
                    [
                        "email_address" => [
                            "address" => $data['email'],
                            "name" => $user->username
                        ]
                    ]
                ],
                "subject" => "Finobe Password Reset",
                "htmlbody" => $html
            ]);

            if(!$response->successful()) {
                $session->delete();
                
                Session::put('error', 'There was an error while sending the email, please try again.');
                return redirect('/');
            }
        } elseif((int)env('FINOBE_MAIL_MODE') == 2) {
            $response = Http::withHeaders([
                'accept' => 'application/json',
                'X-Smtp2go-Api-Key' => env('FINOBE_SMTP2GO_API_KEY'),
                'content-type' => 'application/json',
            ])->post('https://api.smtp2go.com/v3/email/send', [
                "sender" => "Finobe <noreply@finobe.wtf>",
                "to" => $user->username . " <" . $data['email'] . ">",
                "subject" => "Finobe Password Reset",
                "html_body" => $html
            ]);

            if(!$response->successful()) {
                $session->delete();
                
                Session::put('error', 'There was an error while sending the email, please try again.');
                return redirect('/');
            }
        } elseif((int)env('FINOBE_MAIL_MODE') == 3) {
            try {
                Mail::html($html, function ($message) use ($data, $user) {
                    $message->to($data['email'], $user->username)
                        ->from(config('mail.from.address'), config('mail.from.name'))
                        ->subject('Finobe Password Reset');
                });
            } catch (\Throwable $e) {
                report($e);
                $session->delete();
                Session::put('error', 'There was an error while sending the email, please try again.');
                return redirect('/');
            }
        }

        return redirect('/password/reset');
    }

    public function password_verify(Request $request, $id, $resetid) {
        $this->request['data']['embeds']['title'] = 'Reset Password' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        if(!User::find($id)) {
            Session::put('error', 'User not found');
            return redirect('/password/reset');
        }

        $user = User::find($id);

        if(!ResetPassword::where('username', $user->username)->where('uid', $resetid)->where('used', 'n')->exists()) {
            Session::put('error', 'Session not found');
            return redirect('/password/reset');
        }

        $session = ResetPassword::where('username', $user->username)
            ->where('uid', $resetid)
            ->where('used', 'n')
            ->first();

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'password' => 'required|string|alpha_dash|min:8|max:255'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/password/verify/' . $id . '/' . $resetid);
            }

            $session->used = 'y';
            $session->save();
            
            $user->password = password_hash($data['password'], PASSWORD_BCRYPT);
            $user->save();

            Session::put('success', 'Successfully reset');
            return redirect('/');
        }

        $this->request['data']['reset'] = true;

        return view($this->request['data']['user']['version'] . '/Reset', $this->request);
    }

    public function settings(Request $request) {
        $this->request['data']['embeds']['title'] = 'Settings' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if(isset($data['new_username']) && !$request->hasFile('file')) {
                $user = Auth::user();
                $name = trim((string) $data['new_username']);

                if($problem = \App\Services\UsernameChange::problem($user, $name)) {
                    Session::put('error', $problem);
                    return redirect('/app/settings');
                }

                try {
                    \App\Services\UsernameChange::apply($user, $name);
                } catch(\RuntimeException $e) {
                    Session::put('error', $e->getMessage());
                    return redirect('/app/settings');
                }

                Session::put('success', 'Your username is now ' . $name . '.');
                return redirect('/app/settings');
            }

            if(isset($data['gender']) && !$request->hasFile('file')) {
                if(!in_array($data['gender'], ['m', 'f', 'n'], true)) {
                    Session::put('error', 'Pick a gender from the list.');
                    return redirect('/app/settings');
                }

                $user = User::find($this->request['data']['user']['id']);
                $user->gender = $data['gender'];
                $user->save();

                Session::put('success', 'Successfully updated.');
                return redirect('/app/settings');
            }

            if(isset($data['blurb']) && !$request->hasFile('file')) {
                $validator = Validator::make($data, [
                    'blurb' => 'required|string|max:8192'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/settings');
                }

                $user = User::find($this->request['data']['user']['id']);
                $user->blurb = $data['blurb'];
                $user->save();

                Session::put('success', 'Successfully updated.');
                return redirect('/app/settings');
            } elseif(isset($data['password']) && !$request->hasFile('file')) {
                $validator = Validator::make($data, [
                    'email' => 'required|email|unique:finobe.users,email',
                    'password' => 'required|string'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/settings');
                }

                if(!Hash::check($data['password'], $this->request['data']['user']['password'])) {
                    Session::put('errorlogin', true);
                    return redirect('/login');
                }

                $user = Auth::user();
                $user->email = $data['email'];
                $user->verified = 'n';
                $user->save();

                return redirect('/app/settings');
            } elseif($this->request['data']['user']['status'] == 'admin' && $request->hasFile('file')) {
                $validator = Validator::make($data, [
                    'file' => 'required|file|mimes:png,jpg,jpeg|max:10240'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/settings');
                }

                $file = $request->file('file');
                list($width, $height) = getimagesize($file->getPathname());
                $filename = uniqid() . '.' . $file->extension();

                if($width != $height) {
                    Session::put('error', 'Image needs to be 1:1 ratio');
                    return redirect('/app/settings');
                }

                $file->move(config('finobe.cdn_path') . '/avatar/', $filename);

                $user = User::find($this->request['data']['user']['id']);
                $user->pfp = $filename;
                $user->save();

                Session::put('success', 'Successfully updated.');
                return redirect('/app/settings');
            }

            return redirect('/app/settings');
        }

        $this->request['data']['nameChange'] = [
            'cost' => \App\Services\UsernameChange::cost(),
            'next' => ($next = \App\Services\UsernameChange::nextAllowed(Auth::user())) ? $next->format('m/d/Y h:i A') : null,
        ];

        return view($this->request['data']['user']['version'] . '/Settings/Index', $this->request);
    }

    /* Settings > Security: two-factor authentication, curse filter, restrictions */
    public function security(Request $request) {
        $this->request['data']['embeds']['title'] = 'Security' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $user = Auth::user();
        $tf = \App\Services\TwoFactor::class;

        if($request->isMethod('post')) {
            $action = (string) $request->input('action');
            $back = redirect('/app/security');

            if($action === 'curse-filter') {
                $user->curse_filter = !$user->curse_filter;
                $user->save();
                Session::put('success', $user->curse_filter ? 'Curse filter on.' : 'Curse filter off.');
                return $back;
            }

            if($action === '2fa-start' && !$tf::enabled($user)) {
                // a new secret, not active until a code from the app confirms it
                $user->two_factor_secret = \Illuminate\Support\Facades\Crypt::encryptString($tf::newSecret());
                $user->two_factor_confirmed_at = null;
                $user->save();
                return $back;
            }

            if($action === '2fa-confirm' && !$tf::enabled($user)) {
                $secret = $tf::secret($user);
                if(!$secret || !$tf::verifyCode($user, $secret, (string) $request->input('code'))) {
                    Session::put('error', "That code isn't right. Check your phone's clock and try the newest code.");
                    return $back;
                }
                $user->two_factor_confirmed_at = now();
                $user->save();
                Session::flash('backup_codes', ['codes' => $tf::regenerateBackupCodes($user), 'replaced' => false]);
                Session::put('success', 'Two-factor authentication is on.');
                return redirect('/app/security/backup-codes');
            }

            if($action === '2fa-cancel' && !$tf::enabled($user)) {
                $user->two_factor_secret = null;
                $user->save();
                return $back;
            }

            // turning it off or making new backup codes needs your password and a current code
            if(in_array($action, ['2fa-disable', '2fa-backup'], true) && $tf::enabled($user)) {
                if(!Hash::check((string) $request->input('password'), $user->password) || !$tf::verify($user, (string) $request->input('code'))) {
                    Session::put('error', 'Your password or code is wrong.');
                    return $back;
                }
                if($action === '2fa-disable') {
                    $user->two_factor_secret = null;
                    $user->two_factor_backup_codes = null;
                    $user->two_factor_confirmed_at = null;
                    $user->save();
                    Session::put('success', 'Two-factor authentication is off.');
                } else {
                    Session::flash('backup_codes', ['codes' => $tf::regenerateBackupCodes($user), 'replaced' => true]);
                    return redirect('/app/security/backup-codes');
                }
                return $back;
            }

            return $back;
        }

        $pending = !$tf::enabled($user) && $user->two_factor_secret ? $tf::secret($user) : null;
        $restrictions = [];
        foreach(\App\Services\Restrictions::TYPES as $type => $label) {
            $restrictions[$type] = ['label' => $label, 'message' => \App\Services\Restrictions::message($user->id, $type)];
        }
        $others = [];
        foreach(\App\Models\Warning::where('username', $user->username)->orderByDesc('id')->limit(10)->get() as $w) {
            $others[] = 'Warning on ' . date('m/d/Y', strtotime($w->date)) . ': ' . $w->reason;
        }

        $this->request['data']['security'] = [
            'enabled' => $tf::enabled($user),
            'pending' => $pending ? ['secret' => trim(chunk_split($pending, 4, ' ')), 'url' => $tf::otpauthUrl($user, $pending)] : null,
            'backupLeft' => $tf::backupCodesLeft($user),
            'curseFilter' => (bool) $user->curse_filter,
            'restrictions' => $restrictions,
            'otherRestrictions' => $others,
        ];

        return view('v2/Settings/Security', $this->request);
    }

    /* Settings > Security > 2FA Backup Codes: shown once, right after they're made */
    public function backupCodes(Request $request) {
        if(!$this->request['data']['siteusername'] || !($codes = Session::get('backup_codes'))) {
            return redirect('/app/security');
        }
        $this->request['data']['embeds']['title'] = '2FA Backup Codes' . $this->request['data']['embeds']['title'];
        $this->request['data']['backup'] = $codes;
        return view('v2/Settings/BackupCodes', $this->request);
    }

    /* the second login step for accounts with two-factor authentication (/2fa/validate) */
    public function twoFactorChallenge(Request $request) {
        $pending = Session::get('2fa_login');
        if(!$pending || $pending['expires'] < time() || !($user = User::find($pending['id']))) {
            Session::forget('2fa_login');
            return redirect('/login');
        }

        if($request->isMethod('post')) {
            if(!\App\Services\TwoFactor::verify($user, (string) $request->input('code'))) {
                Session::put('error', "That token isn't right.");
                return redirect('/2fa/validate');
            }
            Session::forget('2fa_login');
            // like the real site: "Your session will not be remembered."
            Auth::login($user, false);
            $request->session()->regenerate();
            Session::put('success', 'Successfully logged in.');
            return redirect('/');
        }

        $this->request['data']['embeds']['title'] = '2FA Validation' . $this->request['data']['embeds']['title'];
        return view('v2/TwoFactor', $this->request);
    }

    const LOCALES = [
        // the real site's list from mid-2020 on (English (Canada) was replaced by Deutsch)
        'en' => 'English (US)', 'zh-hk' => '中文(繁體)', 'pt-br' => 'Português (Brasil)',
        'es-us' => 'Español (Estados Unidos)', 'de-de' => 'Deutsch', 'pl-pl' => 'Polski (Polska)', 'tl' => 'Tagalog', 'th-th' => 'ไทย',
    ];

    /* Settings > Privacy */
    public function privacy(Request $request) {
        $this->request['data']['embeds']['title'] = 'Privacy' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $user = Auth::user();
        if($request->isMethod('post')) {
            foreach(\App\Services\Privacy::SETTINGS as $key => $setting) {
                $value = (string) $request->input($key, 'everyone');
                if(in_array($value, $setting['levels'], true)) {
                    $user->{'privacy_' . $key} = $value;
                }
            }
            $user->save();
            Session::put('success', 'Successfully updated.');
            return redirect('/app/privacy');
        }

        $settings = [];
        foreach(\App\Services\Privacy::SETTINGS as $key => $setting) {
            $settings[$key] = $setting + ['value' => \App\Services\Privacy::level($user, $key)];
        }
        $this->request['data']['privacy'] = ['settings' => $settings, 'levels' => \App\Services\Privacy::LEVELS];

        return view('v2/Settings/Privacy', $this->request);
    }

    /* Settings > Language (also what the footer's globe menu sets) */
    public function language(Request $request) {
        if($request->isMethod('post')) {
            $locale = (string) $request->input('locale');
            if(isset(self::LOCALES[$locale])) {
                Session::put('locale', $locale);
                if($this->request['data']['siteusername']) {
                    $user = Auth::user();
                    $user->locale = $locale;
                    $user->save();
                }
            }
            return $request->expectsJson() ? response()->json(['code' => 200]) : redirect('/app/language');
        }

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $this->request['data']['embeds']['title'] = 'Language' . $this->request['data']['embeds']['title'];
        $this->request['data']['languages'] = self::LOCALES;
        $this->request['data']['locale'] = Auth::user()->locale ?: Session::get('locale', 'en');

        return view('v2/Settings/Language', $this->request);
    }

    /* Settings > Password */
    public function password(Request $request) {
        $this->request['data']['embeds']['title'] = 'Password' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'password' => 'required|string|confirmed|alpha_dash|min:8|max:255',
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/app/password');
            }

            $user = Auth::user();
            if(!Hash::check($request->input('current_password'), $user->password)) {
                Session::put('error', 'Your current password is wrong.');
                return redirect('/app/password');
            }

            $user->password = Hash::make($request->input('password'));
            $user->save();
            // anyone else logged in as you is logged out
            Auth::logoutOtherDevices($request->input('password'));

            Session::put('success', 'Password changed.');
            return redirect('/app/password');
        }

        return view('v2/Settings/Password', $this->request);
    }

    /* Settings > Character: the pose in your character's picture and the animations it uses in game */
    public function characterSettings(Request $request) {
        $this->request['data']['embeds']['title'] = 'Character Settings' . $this->request['data']['embeds']['title'];

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        $user = \Illuminate\Support\Facades\Auth::user();
        $this->request['data']['poses'] = \App\Services\AvatarRenderer::POSES;
        $this->request['data']['pose'] = \App\Services\AvatarRenderer::poseFor($user);
        $this->request['data']['animationPacks'] = \App\Services\AnimationPacks::PACKS;
        $this->request['data']['animationPack'] = \App\Services\AnimationPacks::forUser($user);

        return view('v2/Settings/Character', $this->request);
    }

    public function theme(Request $request) {
        $this->request['data']['embeds']['title'] = 'Theme' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if(isset($data['logo'])) {
                $validator = Validator::make($data, [
                    'logo' => 'required|string|in:v1,v2,v3'
                ]);

                if($validator->fails()) {
                    Session::put('error', $validator->errors()->first());
                    return redirect('/app/theme');
                }

                $user = User::find($this->request['data']['user']['id']);
                $user->logo = $data['logo'];
                $user->save();

                Session::put('successv2', 'Successfully updated.');
                return redirect('/app/' . ($user->version == 'v1' ? 'settings' : 'theme'));
            } elseif(isset($data['dark'])) {
                User::where('id', $this->request['data']['user']['id'])
                    ->update([
                        'theme' => DB::raw('CASE WHEN theme = 0 THEN 1 ELSE 0 END')
                    ]);
                
                Session::put('successv2', 'Successfully updated.');
                return redirect('/app/' . ($this->request['data']['user']['version'] == 'v1' ? 'settings' : 'theme'));
            } elseif(isset($data['gary'])) {
                User::where('id', $this->request['data']['user']['id'])
                    ->update([
                        'gary' => DB::raw('CASE WHEN gary = 0 THEN 1 ELSE 0 END')
                    ]);
                
                Session::put('successv2', 'Successfully updated.');
                return redirect('/app/' . ($this->request['data']['user']['version'] == 'v1' ? 'settings' : 'theme'));
            } elseif(isset($data['upsidedown'])) {
                User::where('id', $this->request['data']['user']['id'])
                    ->update([
                        'upsidedown' => DB::raw('CASE WHEN upsidedown = 0 THEN 1 ELSE 0 END')
                    ]);
                
                Session::put('successv2', 'Successfully updated.');
                return redirect('/app/' . ($this->request['data']['user']['version'] == 'v1' ? 'settings' : 'theme'));
            }

            return redirect('/app/theme');
        }

        return view($this->request['data']['user']['version'] . '/Settings/Theme', $this->request);
    }

    public function games(Request $request) {
        $this->request['data']['embeds']['title'] = 'Places' . $this->request['data']['embeds']['title'];
        $data = $request->all();

        if(!$this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            if($this->request['data']['user']['Dius'] < 625) {
                Session::put('error', 'You do not have enough Dius to purchase a place slot');
                return redirect('/app/games');
            }

            $user = User::find($this->request['data']['user']['id']);
            $user->Dius -= 625;
            $user->slots += 1;
            $user->save();

            Purchases::create([
                'username' => $user->username,
                'assetid' => 0,
                'serial' => 0,
                'author' => 1,
                'amount' => -625,
                'type' => 3
            ]);

            Session::put('successv2', 'Successfully purchased.');
            return redirect('/app/games');
        }

        return view($this->request['data']['user']['version'] . '/Settings/Places', $this->request);
    }

    public function form() {
        $this->request['data']['embeds']['title'] = 'Form' . $this->request['data']['embeds']['title'];
        $this->request['data']['invitekeys'] = \App\Admin\Settings::invitesRequired();
        $this->request['data']['turnstile'] = (bool) env('TURNSTILE_SECRET');
        $this->request['data']['turnstile_sitekey'] = env('TURNSTILE_SITEKEY');

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Form', $this->request);
    }

    public function register(Request $request) {
        $this->request['data']['embeds']['title'] = 'Register' . $this->request['data']['embeds']['title'];
        $this->request['data']['invitekeys'] = \App\Admin\Settings::invitesRequired();
        $this->request['data']['turnstile'] = (bool) env('TURNSTILE_SECRET');
        $this->request['data']['turnstile_sitekey'] = env('TURNSTILE_SITEKEY');
        $data = $request->all();

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $forbiddenPhrases = [
                'raped', 'dick', 'fuck', 'shit', 'fag', 'f@g', 'd1ck', 'pussy',
                'jew', 'tranny', 'tr@nny', 'goon', 'g@@n', 'g00n', 'gyat', 'gy@t', 'r@ped', 'tities', 't1t1es',
                'nigg', 'n!gger', 'nigga', 'n1gga', 'n1gger', 'pedo', 'fag', 'faggot'
            ];

            $validator = Validator::make($data, [
                'username' => 'required|string|regex:/^(?!_)(?!.*_$)(?!.*_.*_)[A-Za-z0-9_]+$/|unique:finobe.users,username|min:3|max:20',
                'password' => 'required|string|confirmed|alpha_dash|min:8|max:255',
                'email' => 'required|email|confirmed|unique:finobe.users,email',
                'invite_key' => ($this->request['data']['invitekeys'] ? 'required' : 'nullable') . '|string',
                'cf-turnstile-response' => env('TURNSTILE_SECRET') ? 'required' : 'nullable'
            ]);

            if($validator->fails()) {
                Session::put('error', $validator->errors()->first());
                return redirect('/register/form');
            }

            if(!(bool) config('admin.invites_enabled')) {
                Session::put('error', 'Account creation is currently disabled');
                return redirect('/register/form');
            }

            $username = strtolower($data['username']);
            foreach ($forbiddenPhrases as $phrase) {
                if (str_contains($username, strtolower($phrase))) {
                    Session::put('error', 'The username field must not be greater than 20 characters.');
                    return redirect('/register/form');
                }
            }

            if(env('TURNSTILE_SECRET')) {
                $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                                    'secret' => env('TURNSTILE_SECRET'),
                                    'response' => $data['cf-turnstile-response'] ?? null,
                                    'remoteip' => $request->ip(),
                            ])->json();

                if(!($response['success'] ?? false)) {
                    Session::put('error', 'CAPTCHA failed.');
                    return redirect('/register/form');
                }
            }

            // A key is only mandatory when invites are required, but any key that is
            // supplied still has to be a real, unused one.
            $inviteKey = trim((string) ($data['invite_key'] ?? ''));

            if($inviteKey !== '' && !Keys::where('IID', $inviteKey)->where('used', 'n')->exists()) {
                Session::put('error', 'Invalid invite key.');
                return redirect('/register/form');
            }

            if($inviteKey !== '') {
                $claimed = Keys::where('IID', $inviteKey)
                    ->where('used', 'n')
                    ->update([
                        'used' => 'y',
                        'dateUsed' => now(),
                        'usedBy' => trim($data['username'])
                    ]);

                if(!$claimed) {
                    Session::put('error', 'Invalid invite key.');
                    return redirect('/register/form');
                }
            }

            $user = User::create([
                'username' => trim($data['username']),
                'email' => trim($data['email']),
                'password' => password_hash($data['password'], PASSWORD_BCRYPT),
                'friends' => [],
                'inventory' => [],
                'badges' => [],
                'avatar' => [
                    [
                        'resolvedAvatarType' => 'R6',
                        'equippedGearVersionIds' => [],
                        'backpackGearVersionIds' => [],
                        'assetAndAssetTypeIds' => [],
                        'bodyColors' => [
                            'headColorId' => 24,
                            'torsoColorId' => 23,
                            'rightArmColorId' => 24,
                            'leftArmColorId' => 24,
                            'rightLegColorId' => 119,
                            'leftLegColorId' => 119
                        ]
                    ]
                ],
                'token' => bin2hex(random_bytes(30))
            ]);

            DataController::send_discord_message('<@541523977475194880>, ' . $data['username'] . ' has sign up');

            Auth::login($user);
            $request->session()->regenerate();

            // give the new account a real rendered avatar instead of the default
            \App\Jobs\RenderAvatar::dispatchAfterResponse($user->id);

            return redirect('/legal/welcome');
        }

        return view($this->request['data']['user']['version'] . '/Register', $this->request['data']);
    }

    public function login(Request $request) {
        $this->request['data']['embeds']['title'] = 'Login' . $this->request['data']['embeds']['title'];
        $this->request['data']['errorlogin'] = Session::has('errorlogin');
        $data = $request->all();

        Session::forget('errorlogin');

        if($this->request['data']['siteusername']) {
            return redirect('/');
        }

        if($request->isMethod('post')) {
            $validator = Validator::make($data, [
                'email' => 'required|email',
                'password' => 'required|string'
            ]);

            if($validator->fails()) {
                Session::put('errorlogin', true);
                return redirect('/login');
            }

            if(!User::where('email', $data['email'])->exists()) {
                Session::put('errorlogin', true);
                return redirect('/login');
            }

            $user = User::where('email', $data['email'])->first();

            if(!Hash::check($data['password'], $user->password)) {
                Session::put('errorlogin', true);
                return redirect('/login');
            }

            // accounts with two-factor authentication need a code from the app first
            if(\App\Services\TwoFactor::enabled($user)) {
                $request->session()->regenerate();
                Session::put('2fa_login', ['id' => $user->id, 'expires' => time() + 600]);
                return redirect('/2fa/validate');
            }

            Auth::login($user, !empty($data['remember_me']) || !empty($data['remember']));
            $request->session()->regenerate();
            Session::put('success', 'Successfully logged in.');
            return redirect('/');
        }

        return view($this->request['data']['user']['version'] . '/Login', $this->request);
    }

    public function logout(Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}