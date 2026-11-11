<?php

namespace App\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
    Small key/value store on top of admin_settings for site toggles that are not
    tied to a single user or feature. Values are stored as strings.
*/
final class Settings
{
    public const REQUIRE_INVITES = 'invites.require';

    public static function get(string $key, bool $default = false): bool
    {
        if (!Schema::connection('finobe')->hasTable('admin_settings')) {
            return $default;
        }

        $value = DB::connection('finobe')->table('admin_settings')->where('key', $key)->value('value');

        return $value === null ? $default : (bool) (int) $value;
    }

    public static function put(string $key, bool $value): void
    {
        DB::connection('finobe')->table('admin_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value ? '1' : '0']
        );
    }

    /*
        Whether registration currently demands a valid invite key. Invite keys must be
        switched on site-wide first; the toggle on the Invite Keys panel then decides
        whether they are required or merely accepted.
    */
    public static function invitesRequired(): bool
    {
        return (bool) config('admin.invites_enabled') && self::get(self::REQUIRE_INVITES, true);
    }
}
