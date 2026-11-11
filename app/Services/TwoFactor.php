<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/*
    TwoFactor
     - Settings > Security > Two-Factor Authentication: authenticator app codes (TOTP, RFC 6238:
       6 digits, 30 seconds, SHA-1, which every authenticator app supports) plus 8 one-time backup codes
       (16 hex characters each, like the real site)
     - the secret is stored encrypted with APP_KEY; backup codes are stored hashed
*/
class TwoFactor
{
    const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function enabled(User $user): bool
    {
        return !empty($user->two_factor_confirmed_at) && !empty($user->two_factor_secret);
    }

    public static function newSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function secret(User $user): ?string
    {
        try {
            return $user->two_factor_secret ? Crypt::decryptString($user->two_factor_secret) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function otpauthUrl(User $user, string $secret): string
    {
        $issuer = 'Finobe';
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $user->username)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }

    private static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function codeAt(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0xf;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** a code from the app, allowing 30 seconds of clock drift either way; each code works once */
    public static function verifyCode(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $now = intdiv(time(), 30);
        foreach ([-1, 0, 1] as $drift) {
            if (hash_equals(self::codeAt($secret, $now + $drift), $code)) {
                // stop the same code being replayed within its window
                return \Illuminate\Support\Facades\Cache::add("2fa_used_{$user->id}_" . ($now + $drift), true, 120);
            }
        }
        return false;
    }

    /** a code from the app, or one of the backup codes (which is then used up) */
    public static function verify(User $user, string $code): bool
    {
        $secret = self::secret($user);
        if ($secret && self::verifyCode($user, $secret, $code)) {
            return true;
        }

        $code = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $backup = json_decode($user->two_factor_backup_codes ?? '[]', true) ?: [];
        foreach ($backup as $i => $hash) {
            if ($code !== '' && Hash::check($code, $hash)) {
                unset($backup[$i]);
                $user->two_factor_backup_codes = json_encode(array_values($backup));
                $user->save();
                return true;
            }
        }
        return false;
    }

    /** makes 8 new backup codes, stores their hashes and returns them (shown once) */
    public static function regenerateBackupCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = bin2hex(random_bytes(8));
        }
        $user->two_factor_backup_codes = json_encode(array_map(fn ($c) => Hash::make($c), $codes));
        $user->save();
        return $codes;
    }

    public static function backupCodesLeft(User $user): int
    {
        return count(json_decode($user->two_factor_backup_codes ?? '[]', true) ?: []);
    }
}
