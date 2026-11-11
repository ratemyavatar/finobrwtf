<?php

use App\Http\Controllers\Client\ClientSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('Setting/QuietGet/{bucket}', [ClientSettingsController::class, 'getBucket'])->name('client-settings.get-bucket');

Route::fallback(function () {
    return response()->json(['code' => 0, 'error' => 'Not found'], 404);
});
?>