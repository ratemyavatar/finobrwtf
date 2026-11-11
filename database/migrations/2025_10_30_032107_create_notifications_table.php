<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('touser');
            $table->text('message');
            $table->unsignedBigInteger('owner');
            $table->timestamp('date')->useCurrent();
            $table->boolean('readed')->default(false);
            $table->unsignedBigInteger('forum_id')->nullable();
            $table->unsignedBigInteger('reply_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pms');
    }
};