<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('finobe')->hasTable('asset_package')) {
            return;
        }

        Schema::connection('finobe')->create('asset_package', function (Blueprint $table) {
            $table->unsignedBigInteger('package_asset_id')->index();
            $table->unsignedBigInteger('asset_id')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('finobe')->dropIfExists('asset_package');
    }
};
