<?php

use App\Http\Controllers\Client\VersionCompatibilityController;
use Illuminate\Support\Facades\Route;

Route::get('GetAllowedMD5Hashes', [VersionCompatibilityController::class, 'getMD5Hashes'])->name("version-compatibility.hashes");
Route::get('GetAllowedSecurityVersions', [VersionCompatibilityController::class, 'getVersions'])->name("version-compatibility.versions");

Route::fallback(function () {
    return response()->json(['code' => 0, 'error' => 'Not found'], 404);
});
?>