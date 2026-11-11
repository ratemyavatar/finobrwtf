<?php

use App\Http\Controllers\Client\AuthenticationController;
use App\Http\Controllers\Client\BadgeController;
use App\Http\Controllers\Client\CharacterAppearanceController;
use App\Http\Controllers\Client\GameJoinControlller;
use App\Http\Controllers\Client\GameServer2012Controller;
use App\Http\Controllers\Client\LuaWebServiceController;
use App\Http\Controllers\Client\MarketplaceController;
use App\Http\Controllers\Client\StudioJoinController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\rbxAPIs;

Route::group(['as' => 'client-routes.'], function(){
    Route::get('/asset', [rbxAPIs::class, 'asset'])->name('asset');

    Route::get('/asset/getscriptstate.ashx', [rbxAPIs::class, 'getScriptStateAshx']);
    Route::get('/game/studio.ashx', [rbxAPIs::class, 'studioAshx']);
    Route::get('/thumbs/asset.ashx', [rbxAPIs::class, 'assetThumbnail']);
    Route::get('/asset-thumbnail/image', [rbxAPIs::class, 'assetThumbnail']);
    Route::get('/thumbs/avatar.ashx', [rbxAPIs::class, 'avatarThumbnail']);
    Route::get('/thumbs/avatar-headshot.ashx', [rbxAPIs::class, 'avatarThumbnail']);
    Route::group(['prefix' => 'asset'], function() {
        Route::get('/characterfetch.ashx', [CharacterAppearanceController::class, 'characterFetch'])->name('character-fetch');
        Route::get('/bodycolors.ashx', [CharacterAppearanceController::class, 'bodyColors'])->name('body-colors');

        Route::get('/outfitcharacterfetch.ashx', [CharacterAppearanceController::class, 'outfitCharacterFetch'])->name('outfit-character-fetch');
        Route::get('/outfitbodycolors.ashx', [CharacterAppearanceController::class, 'outfitBodyColors'])->name('outfit-body-colors');
    });

    Route::group(['prefix'=> 'game', 'as'=> 'game.'], function() {
        Route::match(['get','post'],'placelauncher.ashx', [GameJoinControlller::class, 'placeLauncher'])->name('place-launcher');
        Route::get('join.ashx', [GameJoinControlller::class,'joinScript'])->name('join-script');
        Route::get('gameserver2012.ashx', [GameServer2012Controller::class, 'script']);
        Route::get('visit.ashx', [StudioJoinController::class,'visit'])->name('visit-script');

        Route::get('clientversion.ashx', function (\Illuminate\Http\Request $request) {
            $place = \Illuminate\Support\Facades\DB::connection('finobe')->table('assets')
                ->where('id', (int) $request->query('placeId', $request->query('placeid', 0)))->where('asset_type', 9)->first();
            return response($place ? \App\Services\ClientVersions::of($place) : \App\Services\ClientVersions::DEFAULT, 200)
                ->header('Content-Type', 'text/plain');
        });
        Route::get('getcurrentuser.ashx', [AuthenticationController::class,'getCurrentUser'])->name('get-current-user');

        Route::post('validate-machine', fn() => response()->json(['success' => true, 'message' => '']));
        Route::match(['get', 'post'], 'machineconfiguration.ashx', fn() => response('', 200));
        Route::match(['get', 'post'], 'report-stats', fn() => response('', 200));
        Route::get('loadplaceinfo.ashx', fn() => response('', 200));
        Route::get('luawebservice/handlesocialrequest.ashx', [LuaWebServiceController::class,'handleSocialRequest'])->name('lua-web-service');
    });

    Route::group(['prefix' => 'api/gameserver'], function() {
        Route::get('register/{jobId}', [rbxAPIs::class, 'registerJobId']);
        Route::get('visit/{jobId}', [rbxAPIs::class, 'visitJobId']);
        Route::get('verify/{jobId}/{userId}', [rbxAPIs::class, 'verifyPlayer']);
        Route::get('animations/{userId}', [rbxAPIs::class, 'playerAnimations']);
        Route::get('alive/{jobId}', [rbxAPIs::class, 'aliveJobId']);
        Route::get('shutdown/{jobId}', [rbxAPIs::class, 'shutdownJobId']);
        Route::post('update', [rbxAPIs::class, 'update']);
        Route::any('sysstats-report', fn() => response('', 200));
    });

    Route::group(['prefix' => 'game/badge'], function() {
        Route::get('hasbadge.ashx', [BadgeController::class, 'hasBadge']);
        Route::get('isbadgedisabled.ashx', [BadgeController::class, 'isDisabled']);
        Route::get('isbadgelegal.ashx', [BadgeController::class, 'isLegal']);
        Route::match(['get', 'post'], 'awardbadge.ashx', [BadgeController::class, 'award']);
    });

    Route::get('game/gamepass/gamepasshandler.ashx', [MarketplaceController::class, 'hasPass']);

    Route::get('/penelope/login/negotiate', [AuthenticationController::class,'authenticateClient'])->name('negotiate');

    Route::get('login/negotiate.ashx', [AuthenticationController::class,'authenticateClient']);
    Route::get('login/requestauth.ashx', [AuthenticationController::class,'requestAuth'])->name('request-auth');
    Route::post('mobileapi/login', [\App\Http\Controllers\Client\LoginApiController::class,'mobile'])->middleware('limit:10,1');
});

?>