<?php

namespace App\Admin;

use Illuminate\Support\Facades\DB;

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

    public static function apply(int $userId, string $role): array
    {
        $scopes = self::scopes($role);
        abort_if($scopes === null, 422, 'Unknown role.');
        DB::connection('finobe')->table('admin_staff')
            ->updateOrInsert(['user_id' => $userId], ['scopes' => json_encode(array_values($scopes))]);
        return $scopes;
    }
}
