<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\FormatVersion;
use App\Http\Controllers\SecurityNotary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class StudioJoinController extends Controller
{
    public function visit(Request $request)
    {
        $validator = Validator::make($request->all(), [
			'UserID' => ['required'],
            'PlaceID'=> ['required'],
		]);

        if($validator->fails()) {
            return response()->json(['code' => 0, 'error' => 'Bad Request'], status: 403);
		}
        $valid = $validator->valid();

        $visitScript = Storage::get('penelope/scripts/visit.lua');

        $visitScript = str_replace("{placeId}", $valid['PlaceID'], $visitScript);
        $visitScript = str_replace("{userId}", 1, $visitScript);

        $signedScript = SecurityNotary::SignScript($visitScript, FormatVersion::V2, true);
        return response($signedScript, 200);
    }
}
