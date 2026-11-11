<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class SerializeWrites
{
    private const WAIT_SECONDS = 10;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $owner = $request->user()?->getAuthIdentifier() ?? $request->ip();

        return Cache::lock('write:' . $owner, self::WAIT_SECONDS + 5)
            ->block(self::WAIT_SECONDS, fn () => $next($request));
    }
}
