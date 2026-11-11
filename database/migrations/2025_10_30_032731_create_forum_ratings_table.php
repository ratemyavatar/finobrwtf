<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender');
            $table->unsignedBigInteger('toid');
            $table->string('type');
            $table->string('rate_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_ratings');
    }
};