<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bans', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->text('reason');
            $table->timestamp('expire')->nullable();
            $table->string('moderator');
            $table->boolean('perm')->default(false);
            $table->boolean('reactivated')->default(false);
            $table->timestamp('date')->useCurrent();
            $table->boolean('undone')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bans');
    }
};