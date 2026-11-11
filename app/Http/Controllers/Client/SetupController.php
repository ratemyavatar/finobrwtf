<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

class SetupController extends Controller
{
    public function getCurrentVersion (Request $request) {
        $year = (string) $request->query('year', '2016');
        if ($year === '2016') {
            return (string) config('finobe.client_version');
        }
        $version = preg_match('/^\d{4}$/', $year) && \App\Services\ClientVersions::isValid($year)
            ? (string) config("finobe.client_versions.{$year}", '') : '';

        return $version !== '' ? $version : response("No {$year} client is published yet.", 404);
    }

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

    public function getFile (Request $request, string $file) {
        $file = basename($file);

        foreach ([Storage::path('setup/' . $file), storage_path('setup/' . $file)] as $filePath) {
            if (is_file($filePath)) {
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
