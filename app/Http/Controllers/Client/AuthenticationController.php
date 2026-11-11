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
        $authToken = is_string($valid['suggest']) && $valid['suggest'] !== ''
            ? User::where('token', $valid['suggest'])->first()
            : null;

        if (!$authToken)
        {
            return response()->json(['code'=> 0,'message'=> 'Invalid authentication token'], 403);
        }

        // NOTE: no Auth::login() here. Auth::login() migrates (migrate(true)) and destroys the
        // shared .finobe.wtf session, so a game client carrying a web session cookie would log the
        // browser out. The client-only identity lives in the finobe_client cookie below.

        // a game-only login the client also sends over http:// (the session cookie is https-only).
        // PlaceLauncher accepts it; nothing else on the site does.
        $clientSession = bin2hex(random_bytes(24));
        Cache::put("client_session_{$clientSession}", $authToken->id, self::CLIENT_COOKIE_MINUTES * 60);

        return response()->json(['code' => 1, 'message' => 'Successfully authenticated client'], 200)
            ->withCookie(cookie(self::CLIENT_COOKIE, $clientSession, self::CLIENT_COOKIE_MINUTES, '/', config('session.domain'), false, true, false, 'lax'));
    }

    public function requestAuth(Request $request)
    {
        if (!Auth::check())
            return response()->json(['code'=> 0,'error'=> 'not authenticated'], 403);
        return response(route('client-routes.negotiate', [ 'suggest' => Auth::user()->token ]));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        return response('');
    }

    public function getCurrentUser(Request $request)
    {
        $id = Auth::id();

        if (!$id) {
            $clientSession = $request->cookie(self::CLIENT_COOKIE);
            if (is_string($clientSession) && $clientSession !== '') {
                $id = Cache::get("client_session_{$clientSession}");
            }
        }

        return response($id ?? -1, 200);
    }
}
