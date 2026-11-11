<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('finobe')->hasTable('place_badge_awards')) {
            return;
        }

        Schema::connection('finobe')->create('place_badge_awards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('badge_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('awarded_at')->useCurrent();
            $table->unique(['badge_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('finobe')->dropIfExists('place_badge_awards');
    }
};
