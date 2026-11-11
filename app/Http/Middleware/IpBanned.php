<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/* Admin Panel > IP Bans: a banned address gets the "You have been IP banned from Finobe" page */
class IpBanned
{
    public static function bannedIps(): array
    {
        return Cache::remember('ip_bans', 60, function () {
            try {
                return DB::connection('finobe')->table('ip_bans')->pluck('ip')->all();
            } catch (\Throwable $e) {
                return []; // not migrated yet
            }
        });
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->ip(), self::bannedIps(), true)) {
            if (!$request->acceptsHtml() || $request->expectsJson()) {
                return response('You have been IP banned from Finobe', 403);
            }
            return response()->view('v2/IpBanned', ['data' => [
                'embeds' => ['title' => 'IP Banned - Finobe', 'description' => '', 'url' => '', 'image' => ''],
                'siteusername' => false, 'user' => ['version' => 'v2'], 'alerts' => [], 'lucky_number' => '',
                'csrf_token' => csrf_token(),
            ]], 403);
        }
        return $next($request);
    }
}
