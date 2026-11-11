<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Warning;
use App\Services\Restrictions;
use App\Services\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class SecurityController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $pending = !TwoFactor::enabled($user) && $user->two_factor_secret ? TwoFactor::secret($user) : null;

        $restrictions = [];
        foreach (Restrictions::TYPES as $type => $label) {
            $restrictions[$type] = ['label' => $label, 'message' => Restrictions::message($user->id, $type)];
        }

        $others = Warning::where('username', $user->username)->orderByDesc('id')->limit(10)->get()
            ->map(fn ($w) => 'Warning on ' . date('m/d/Y', strtotime($w->date)) . ': ' . $w->reason)->all();

        return $this->page('Settings/Security', [
            'security' => [
                'enabled' => TwoFactor::enabled($user),
                'pending' => $pending ? ['secret' => trim(chunk_split($pending, 4, ' ')), 'url' => TwoFactor::otpauthUrl($user, $pending)] : null,
                'backupLeft' => TwoFactor::backupCodesLeft($user),
                'curseFilter' => (bool) $user->curse_filter,
                'restrictions' => $restrictions,
                'otherRestrictions' => $others,
            ],
        ], 'Security');
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $action = (string) $request->input('action');
        $enabled = TwoFactor::enabled($user);

        if ($action === 'curse-filter') {
            $user->forceFill(['curse_filter' => !$user->curse_filter])->save();
            $request->session()->put('success', $user->curse_filter ? 'Curse filter on.' : 'Curse filter off.');
        } elseif ($action === '2fa-start' && !$enabled) {
            $user->forceFill(['two_factor_secret' => Crypt::encryptString(TwoFactor::newSecret()), 'two_factor_confirmed_at' => null])->save();
        } elseif ($action === '2fa-confirm' && !$enabled) {
            $secret = TwoFactor::secret($user);

            if (!$secret || !TwoFactor::verifyCode($user, $secret, (string) $request->input('code'))) {
                $request->session()->put('error', "That code isn't right. Check your phone's clock and try the newest code.");

                return redirect('/app/security');
            }

            $user->forceFill(['two_factor_confirmed_at' => now()])->save();
            $request->session()->flash('backup_codes', ['codes' => TwoFactor::regenerateBackupCodes($user), 'replaced' => false]);
            $request->session()->put('success', 'Two-factor authentication is on.');

            return redirect('/app/security/backup-codes');
        } elseif ($action === '2fa-cancel' && !$enabled) {
            $user->forceFill(['two_factor_secret' => null])->save();
        } elseif (in_array($action, ['2fa-disable', '2fa-backup'], true) && $enabled) {
            if (!Hash::check((string) $request->input('password'), $user->password) || !TwoFactor::verify($user, (string) $request->input('code'))) {
                $request->session()->put('error', 'Your password or code is wrong.');

                return redirect('/app/security');
            }

            if ($action === '2fa-backup') {
                $request->session()->flash('backup_codes', ['codes' => TwoFactor::regenerateBackupCodes($user), 'replaced' => true]);

                return redirect('/app/security/backup-codes');
            }

            $user->forceFill(['two_factor_secret' => null, 'two_factor_backup_codes' => null, 'two_factor_confirmed_at' => null])->save();
            $request->session()->put('success', 'Two-factor authentication is off.');
        }

        return redirect('/app/security');
    }

    public function backupCodes(Request $request)
    {
        $codes = $request->session()->get('backup_codes');

        if (!$codes) {
            return redirect('/app/security');
        }

        return $this->page('Settings/BackupCodes', ['backup' => $codes], '2FA Backup Codes');
    }
}
