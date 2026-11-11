<?php
namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\RobloxUtilities;
use Illuminate\Support\Facades\Storage;

class ClientSettingsController extends Controller
{
    public function getBucket(Request $request, string $bucket)
    {
        if ($bucket == "ClientSharedSettings")
            $bucket = "ClientAppSettings";

        $settings = "{}";
        switch ($bucket) {
            case "ClientAppSettings":
                $settings = file_get_contents(Storage::path('penelope/fflags/ClientAppSettings.json'));
                break;
            case "RCCService":
                if (RobloxUtilities::IsFinobeCloudAuthorized()) {
                    $settings = file_get_contents(Storage::path('penelope/fflags/RCCService.json'));
                }
                break;
        }

        return response($settings)->header('Content-Type', 'application/json; charset=utf-8');
    }
}
