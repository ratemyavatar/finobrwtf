<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class AuthenticationController extends Controller
{
    const CLIENT_COOKIE = 'finobe_client';
    const CLIENT_COOKIE_MINUTES = 30;

    public function authenticateClient(Request $request)
    {
        $validator = Validator::make($request->all(), [
			'suggest' => ['required'],
		]);

        if($validator->fails()) {
            return response()->json(['code' => 0, 'error' => 'Bad Request'], status: 403);
		}
        $valid = $validator->valid();
        $authToken = \App\Services\ClientTicket::resolve($valid['suggest']);

        if (!$authToken)
        {
            return response()->json(['code'=> 0,'message'=> 'Invalid authentication token'], 403);
        }

        $clientSession = bin2hex(random_bytes(24));
        Cache::put("client_session_{$clientSession}", $authToken->id, self::CLIENT_COOKIE_MINUTES * 60);

        return response()->json(['code' => 1, 'message' => 'Successfully authenticated client'], 200)
            ->withCookie(cookie(self::CLIENT_COOKIE, $clientSession, self::CLIENT_COOKIE_MINUTES, '/', config('session.domain') ?: '.' . config('finobe.domain'), false, true, false, 'lax'));
    }

    public static function clientUserId(Request $request): ?int
    {
        $clientSession = $request->cookie(self::CLIENT_COOKIE);
        if (!is_string($clientSession) || $clientSession === '') {
            return null;
        }

        $id = Cache::get("client_session_{$clientSession}");

        return $id ? (int) $id : null;
    }

    public function requestAuth(Request $request)
    {
        if (!Auth::check())
            return response()->json(['code'=> 0,'error'=> 'not authenticated'], 403);
        return response(route('client-routes.negotiate', [ 'suggest' => \App\Services\ClientTicket::issue(Auth::user()) ]));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        return response('');
    }

    public function clientLogout(Request $request)
    {
        $clientSession = $request->cookie(self::CLIENT_COOKIE);
        if (is_string($clientSession) && $clientSession !== '') {
            Cache::forget("client_session_{$clientSession}");
        }

        return response('')->withCookie(cookie()->forget(self::CLIENT_COOKIE, '/', config('session.domain') ?: '.' . config('finobe.domain')));
    }

    public function getCurrentUser(Request $request)
    {
        $id = Auth::id() ?? self::clientUserId($request);

        return response($id ?? -1, 200);
    }
}
