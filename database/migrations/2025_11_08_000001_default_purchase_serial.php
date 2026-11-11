<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('finobe')->hasColumn('purchases', 'serial')) {
            Schema::connection('finobe')->table('purchases', function (Blueprint $table) {
                $table->unsignedBigInteger('serial')->default(0)->change();
            });
        }
    }

    public function down(): void
    {
    }
};
