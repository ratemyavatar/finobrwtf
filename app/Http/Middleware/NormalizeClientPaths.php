<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeClientPaths
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = config('finobe.domain');
        $host = strtolower($request->getHost());
        $path = $request->getPathInfo();
        $lower = strtolower($path);

        if ($host === $domain && (in_array($lower, ['/asset', '/asset/'], true)
                || in_array($lower, ['/thumbs/asset.ashx', '/asset-thumbnail/image', '/thumbs/avatar.ashx', '/thumbs/avatar-headshot.ashx'], true))) {
            $query = $request->server->get('QUERY_STRING');
            return redirect()->away($request->getScheme() . "://www.{$domain}{$lower}" . ($query ? '?' . $query : ''));
        }

        $webProxied = $host === $domain
            && (str_starts_with($lower, '/web/www/') || str_starts_with($lower, '/web/assetgame/'));

        if ($lower !== $path
            && ($webProxied || in_array($host, ["www.{$domain}", "assetgame.{$domain}"], true))
            && !str_starts_with($lower, '/api/gameserver/')
            && !preg_match('#^/web/[a-z]+/api/gameserver/#', $lower)) {
            $server = $request->server->all();
            $query = $request->server->get('QUERY_STRING');
            $server['REQUEST_URI'] = $lower . ($query !== null && $query !== '' ? '?' . $query : '');

            $request = $request->duplicate(null, null, null, null, null, $server);
            app()->instance('request', $request);
        }

        return $next($request);
    }
}
