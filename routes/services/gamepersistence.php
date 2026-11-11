<?php

use App\Http\Controllers\Client\DataStoreController;
use Illuminate\Support\Facades\Route;

Route::group(['prefix' => 'persistence'], function () {
    Route::post('getV2', [DataStoreController::class, 'get']);
    Route::post('set', [DataStoreController::class, 'set']);
    Route::post('increment', [DataStoreController::class, 'increment']);
    Route::post('getSortedValues', [DataStoreController::class, 'sorted']);
});
