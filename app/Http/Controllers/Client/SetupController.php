<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

class SetupController extends Controller
{
    // what the launcher should download: FINOBE_CLIENT_VERSION in .env (e.g. version-fa01fdc40051450f) for
    // 2016, FINOBE_CLIENT_VERSION_<year> for the other years (?year=2012). Each is a
    // <version>-FinobePlayer.zip in storage/setup or storage/app/private/setup (tools/client/package_client.py).
    public function getCurrentVersion (Request $request) {
        $year = (string) $request->query('year', '2016');
        if ($year === '2016') {
            return (string) config('finobe.client_version');
        }
        $version = preg_match('/^\d{4}$/', $year) && \App\Services\ClientVersions::isValid($year)
            ? (string) config("finobe.client_versions.{$year}", '') : '';

        return $version !== '' ? $version : response("No {$year} client is published yet.", 404);
    }

    // the FinobeLauncher build in storage/setup (kLauncherBuild in tools/bootstrapper/main.cpp):
    // launchers older than this download FinobeLauncher.exe and replace themselves
    public const LAUNCHER_BUILD = 8;

    public function getLauncherBuild (Request $request): string {
        return (string) self::LAUNCHER_BUILD;
    }

    public function getCurrentBootstrapperVersion (Request $request): string {
        return '1, 6, 3, 172';
    }

    public function getCurrentActiveCDN (Request $request): string {
        return 'prod1-setup.' . config('finobe.domain');
    }

    // client builds live in two places: storage/setup (tracked launcher packages) and
    // storage/app/private/setup (builds uploaded from the admin "change versions" page)
    public function getFile (Request $request, string $file) {
        $file = basename($file);

        foreach ([Storage::path('setup/' . $file), storage_path('setup/' . $file)] as $filePath) {
            if (is_file($filePath)) {
                // the launcher downloads its own update through WinINet, which caches responses:
                // a cached old exe would leave a client stuck on an old build forever
                return response()->file($filePath, [
                    'Cache-Control' => 'no-store, no-cache, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
            }
        }

        return response()->json([ 'code' => 0, 'message' => 'Not found.'], 404);
    }
}
