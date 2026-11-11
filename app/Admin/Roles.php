<?php

namespace App\Admin;

use Illuminate\Support\Facades\DB;

/*
    Named staff roles. A role is a preset of scope groups; granting one replaces the
    user's delegated scopes with that preset. `admin`/`owner` are account status, not
    delegated scopes, so they are handled by the rank tool instead.
*/
final class Roles
{
    public const ROLES = [
        'Trial Moderator' => ['Moderation', 'Catalog'],
        'Moderator'       => ['Moderation', 'Access restrictions', 'Catalog'],
        'Asset Manager'   => ['Catalog'],
        'Catalog Manager' => ['Catalog', 'Items'],
    ];

    public static function names(): array
    {
        return array_keys(self::ROLES);
    }

    public static function scopes(string $role): ?array
    {
        return self::ROLES[$role] ?? null;
    }

    /* Replace the user's delegated scopes with the role's preset. */
    public static function apply(int $userId, string $role): array
    {
        $scopes = self::scopes($role);
        abort_if($scopes === null, 422, 'Unknown role.');
        DB::connection('finobe')->table('admin_staff')
            ->updateOrInsert(['user_id' => $userId], ['scopes' => json_encode(array_values($scopes))]);
        return $scopes;
    }
}
