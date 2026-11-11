<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LuaWebServiceController extends Controller
{
    public function handleSocialRequest (Request $request)
    {
        $validator = Validator::make($request->all(), [
			'method' => ['required'],
            'groupid' => ['required_if:method,IsInGroup'],
            'playerid' => ['required'],
            'userid' => ['required_if:method,IsFriendsWith,IsBestFriendsWith'],
		]);

        if($validator->fails()) {
            return response()->json(['code' => 0, 'error' => 'Bad Request'], status: 403);
		}

		$valid = $validator->valid();
        switch ($valid['method']) {
            case 'IsInGroup':
                if ($valid['groupid'] == '1200769') {
                    $user = User::where('id', $valid['playerid'])->first();

                    if ($user) {
                        $isAdmin = \App\Services\PlayerBadges::showsAdminIcon((int) $user->id, $user->status);
                        return response('<Value Type="boolean">'.($isAdmin ? 'true' : 'false').'</Value>')
                            ->header('Content-Type', 'text/xml');
                    }
                }
        }

        return response('<Value Type="boolean">false</Value>')
            ->header('Content-Type', 'text/xml');
    }
}
