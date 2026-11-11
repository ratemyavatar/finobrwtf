<?php
namespace App\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Access
{
    public static function scopes($user): array
    {
        if (!$user) return [];
        if ($user->status === 'admin') return array_values(array_unique([...array_column(Catalog::all(), 'group'), 'Blog']));
        if (!Schema::connection('finobe')->hasTable('admin_staff')) return [];
        $row = DB::connection('finobe')->table('admin_staff')->where('user_id', $user->id)->first();
        return $row ? json_decode($row->scopes, true) : [];
    }

    public static function owner($user): bool
    {
        $owners = config('admin.owner_ids');
        return $user && ($owners ? in_array((int) $user->id, $owners, true) : $user->status === 'admin');
    }

    public static function authorize($user, ?array $action = null): void
    {
        abort_unless($user && self::scopes($user), 403, 'Staff access required.');
        if (Schema::connection('finobe')->hasTable('admin_audit')) {
            $suspended=DB::connection('finobe')->table('bans')->where('username',$user->username)
                ->where('reactivated',Workbench::flag('bans','reactivated',false))
                ->where('undone',Workbench::flag('bans','undone',false))
                ->where(fn($q)=>$q->where('perm',Workbench::flag('bans','perm',true))->orWhere('expire','>',now()))->exists();
            abort_if($suspended,403,'Staff access is suspended for this account.');
        }
        if ($action) {
            abort_unless(in_array($action['group'], self::scopes($user), true), 403, 'This control is outside your staff permissions.');
            if ($action['group'] === 'Staff') abort_unless(self::owner($user), 403, 'Only an owner can change staff permissions.');
        }
    }
}
