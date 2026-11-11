<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('something', function () {
    $db = DB::connection('finobe');

    $assets = $db->table('assets')
        ->where('asset_type', 3)
        ->get()
        ->map(function ($item) {
            return (array) $item;
        })->toArray();

    foreach($assets as $asset) {
        $asset['additional'] = json_decode($asset['additional'], true);
        $asset['additional']['onSale'] = true;
        $asset['additional'] = json_encode($asset['additional']);

        $db->table('assets')
            ->where('id', $asset['id'])
            ->update([
                'additional' => $asset['additional']
            ]);
    }

    $this->info('Ran successfully');
});

Artisan::command('finobe:reap-servers', function () {
    $reaped = \App\Services\Matchmaking\PlaceLauncher::cleanup();
    $this->info($reaped > 0 ? "Reaped {$reaped} stale server(s)." : 'No stale servers.');
})->purpose('Stop game servers whose process died without shutting down');

Schedule::command('finobe:reap-servers')->everyMinute()->withoutOverlapping(10);
