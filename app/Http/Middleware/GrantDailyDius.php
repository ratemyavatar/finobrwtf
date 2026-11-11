<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class GrantDailyDius
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && ($user->diubanned ?? 'n') === 'n' && (!$user->lastdiu || strtotime($user->lastdiu) <= time())) {
            $user->forceFill(['Dius' => $user->Dius + 25, 'lastdiu' => now()->addDay()])->save();
        }

        return $next($request);
    }
}
