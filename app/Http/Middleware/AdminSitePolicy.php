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
