<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class LimitRoute
{
    public function handle(Request $request, Closure $next, int|string $max, int|string $minutes = 1): Response
    {
        $route = $request->route();
        $key = 'limit:' . sha1(implode('|', [
            $request->method(),
            $route?->uri() ?? $request->path(),
            $request->user()?->getAuthIdentifier() ?? $request->ip(),
        ]));

        if (RateLimiter::tooManyAttempts($key, (int) $max)) {
            throw new ThrottleRequestsException('Too many requests.', null, [
                'Retry-After' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, (int) $minutes * 60);

        return $next($request);
    }
}
