<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Character > Outfits: saved sets of worn items and body colours
    public function up(): void
    {
        if (Schema::connection('finobe')->hasTable('outfits')) {
            return;
        }

        Schema::connection('finobe')->create('outfits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name', 50);
            $table->text('avatar');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('finobe')->dropIfExists('outfits');
    }
};
