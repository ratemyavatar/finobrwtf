<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // every name change (Settings > User Details), for the cooldown and for admins
    public function up(): void
    {
        if (Schema::connection('finobe')->hasTable('username_history')) {
            return;
        }
        Schema::connection('finobe')->create('username_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('old_username', 20);
            $table->string('new_username', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('finobe')->dropIfExists('username_history');
    }
};
