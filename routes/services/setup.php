<?php

use App\Http\Controllers\Client\CharacterAppearanceController;
use App\Http\Controllers\Client\GameJoinControlller;
use App\Http\Controllers\Client\SetupController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\rbxAPIs;

Route::group(['as' => 'prod-setup.'], function(){
    Route::get('/version', [SetupController::class, 'getCurrentVersion'])->name('get-current-version');

    Route::get('/version-{hash}-BootstrapperVersion.txt', [SetupController::class, 'getCurrentBootstrapperVersion'])->name('get-bootstrapper-version');

    Route::get('/launcher-build', [SetupController::class, 'getLauncherBuild'])->name('get-launcher-build');

    Route::get('/cdn.txt', [SetupController::class,'getCurrentActiveCDN'])->name('get-cdn');

    Route::get('/{file}', [SetupController::class,'getFile'])->name('get-file');


    Route::fallback(function () {
        return response ()->json([ 'code' => 0, 'message' => 'Not found.'], 404);
    });
});

?>