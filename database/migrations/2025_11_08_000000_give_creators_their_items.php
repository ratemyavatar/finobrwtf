<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('finobe');

        $db->table('purchases')
            ->where('assetid', '!=', 0)
            ->where('amount', 0)
            ->where(fn($q) => $q->whereNull('type')->orWhere('type', ''))
            ->update(['type' => 1]);

        $items = $db->table('assets')
            ->whereIn('asset_type', [2, 8, 11, 12, 18, 19])
            ->where('visibility', '!=', 'd')
            ->select('id', 'author')
            ->get();

        foreach ($items as $item) {
            $username = $db->table('users')->where('id', $item->author)->value('username');

            if (!$username) {
                continue;
            }

            $owned = $db->table('purchases')
                ->where('username', $username)
                ->where('assetid', $item->id)
                ->where('type', 1)
                ->exists();

            if (!$owned) {
                $db->table('purchases')->insert([
                    'username' => $username,
                    'assetid' => $item->id,
                    'serial' => 0,
                    'author' => $item->author,
                    'amount' => 0,
                    'type' => 1,
                ]);
            }
        }
    }

    public function down(): void
    {
    }
};
