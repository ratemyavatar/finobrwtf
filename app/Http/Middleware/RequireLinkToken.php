<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireLinkToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            $token = (string) $request->query('_token', '');

            if ($token === '' || !hash_equals((string) $request->session()->token(), $token)) {
                abort(419);
            }
        }

        return $next($request);
    }
}
