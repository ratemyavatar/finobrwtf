<?php

namespace App\Http\Middleware;

use App\Admin\Access;
use Closure;
use Illuminate\Http\Request;

final class RequireAdmin
{
    public function handle(Request $request, Closure $next, ?string $scope = null)
    {
        Access::authorize($request->user(), $scope ? ['group' => $scope, 'kind' => 'inspect'] : null);

        return $next($request);
    }
}
