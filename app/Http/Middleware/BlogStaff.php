<?php
namespace App\Http\Middleware;
use App\Admin\Access;
use Closure;
use Illuminate\Http\Request;
final class BlogStaff {
    public function handle(Request $request,Closure $next){Access::authorize($request->user());abort_unless(in_array('Blog',Access::scopes($request->user()),true),403,'Blog editing permission required.');return $next($request);}
}
