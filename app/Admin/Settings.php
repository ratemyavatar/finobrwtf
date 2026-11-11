<?php

namespace App\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    public static function invitesRequired(): bool
    {
        return (bool) config('admin.invites_enabled') && self::get(self::REQUIRE_INVITES, true);
    }
}
