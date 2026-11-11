<?php
namespace App\Http\Middleware;

use App\Admin\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\RateLimiter;

final class AdminSitePolicy
{
    public function handle(Request $request, Closure $next) {
        if ($request->routeIs('blog.*') || $request->is('admin','admin/*') || !Schema::connection('finobe')->hasTable('admin_settings')) return $next($request);
        $user=$request->user();
        $db=DB::connection('finobe');
        if ($user && !$request->is('logout','moderation/acknowledge')) {
            $banned=$db->table('bans')->where('username',$user->username)
                ->where('reactivated',\App\Admin\Workbench::flag('bans','reactivated',false))
                ->where('undone',\App\Admin\Workbench::flag('bans','undone',false))
                ->where(fn($q)=>$q->where('perm',\App\Admin\Workbench::flag('bans','perm',true))->orWhere('expire','>',now()))->orderByDesc('id')->first();
            $warning=$db->table('warning')->where('username',$user->username)
                ->where('reactivated',\App\Admin\Workbench::flag('warning','reactivated',false))
                ->where('undone',\App\Admin\Workbench::flag('warning','undone',false))->orderByDesc('id')->first();
            if ($banned || $warning) {
                if ($request->expectsJson()) return response()->json(['message'=>$banned?'This account is suspended.':'Please acknowledge the account warning.'],403);
                return response()->view('admin.account-notice',['record'=>$banned??$warning,'isWarning'=>!$banned],403);
            }
            // Expired bans must no longer trip the old frontend's unreactivated-ban guard.
            $db->table('bans')->where('username',$user->username)->where('perm',\App\Admin\Workbench::flag('bans','perm',false))->where('expire','<=',now())
                ->update(['reactivated'=>\App\Admin\Workbench::flag('bans','reactivated',true)]);
        }
        if ($user && $user->status === 'admin') return $next($request);
        $rateKeys=[];
        $features=json_decode(file_get_contents(config_path('admin_features.json')),true);
        foreach($features as $key=>$feature) {
            $isWrite=!in_array($request->method(),['GET','HEAD','OPTIONS']);
            // Friends and invitations in the legacy app also mutate on GET.
            $legacyWrite=$request->is('user/*/add','user/*/accept','user/*/remove','invites/new','forum/subscribe');
            if (!$isWrite && !$legacyWrite && empty($feature['all_methods'])) continue;
            if (!$request->is(...$feature['paths'])) continue;
            $paused=$db->table('admin_settings')->where('key','pause.'.$key)->value('value');
            abort_if($paused==='1',503,$feature['label'].' is temporarily paused.');
            if ($user) {
                $blocked=$db->table('admin_restrictions')->where('user_id',$user->id)->where('feature',$key)->value('blocked');
                abort_if($blocked,403,$feature['label'].' is restricted on your account.');
                $seconds=(int)$db->table('admin_settings')->where('key','cooldown.'.$key)->value('value');
                if ($seconds>0) {
                    $limit='admin-policy:'.$key.':'.$user->id;
                    abort_if(RateLimiter::tooManyAttempts($limit,1),429,'Please wait before trying this action again.');
                    $rateKeys[$limit]=$seconds;
                }
            }
        }
        $response=$next($request);
        if ($response->getStatusCode()<400) foreach($rateKeys as $limit=>$seconds) RateLimiter::hit($limit,$seconds);
        return $response;
    }
}
