<?php

use App\Http\Controllers\Client\AuthenticationController;
use App\Http\Controllers\Studio\StudioController;
use App\Http\Controllers\Studio\StudioDataController;
use Illuminate\Support\Facades\Route;

Route::group(['as' => 'studio.'], function () {
    Route::get('/ide/welcome', [StudioController::class, 'welcome'])->name('welcome');
    Route::get('/ide/login', [StudioController::class, 'loginForm']);
    Route::post('/ide/login', [StudioController::class, 'login'])->middleware('limit:10,1');
    Route::get('/ide/login/2fa', [StudioController::class, 'twoFactorForm']);
    Route::post('/ide/login/2fa', [StudioController::class, 'twoFactor'])->middleware('limit:10,1');
    Route::post('/ide/logout', [StudioController::class, 'logout']);
    Route::match(['get', 'post'], '/game/logout.aspx', [AuthenticationController::class, 'clientLogout']);

    Route::get('/ide/clienttoolbox.aspx', [StudioController::class, 'toolbox']);
    Route::get('/ide/toolbox/items', [StudioController::class, 'toolboxItems'])->middleware('limit:120,1');

    Route::get('/ide/upload.aspx', [StudioController::class, 'publish']);
    Route::get('/ui/save.aspx', [StudioController::class, 'uploadModel']);
    Route::get('/ide/publishas', [StudioController::class, 'publish']);
    Route::get('/ide/publishgameas', [StudioController::class, 'publish']);
    Route::get('/ide/publish', [StudioController::class, 'projects']);
    Route::post('/ide/places/new', [StudioDataController::class, 'createPlace'])->middleware('limit:10,1');

    Route::get('/game/edit.ashx', [StudioDataController::class, 'edit'])->middleware('limit:60,1');
    Route::post('/data/upload.ashx', [StudioDataController::class, 'uploadPlace'])->middleware('limit:30,1');
    Route::post('/data/newasset.ashx', [StudioDataController::class, 'newModel'])->middleware('limit:20,1');

    Route::get('/places/{placeId}/settings', [StudioDataController::class, 'placeSettings'])->whereNumber('placeId');
    Route::get('/my/settings/json', [StudioDataController::class, 'mySettings']);
    Route::post('/error/dmp.ashx', [StudioDataController::class, 'crash']);
});
