<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * purchases.serial is NOT NULL with no default, but only some insert sites
     * pass it - every one that does uses 0, and nothing reads it except
     * `where('serial', 0)`. The asset-creation path omits it, so buying or
     * uploading an item failed with "Field 'serial' doesn't have a default
     * value". Give the column the default the code already assumes.
     */
    public function up(): void
    {
        $schema = Schema::connection('finobe');

        if (!$schema->hasTable('purchases') || !$schema->hasColumn('purchases', 'serial')) {
            return;
        }

        $schema->table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('serial')->default(0)->change();
        });
    }

    public function down(): void
    {
        // intentionally left empty: live rows may rely on the default
    }
};
