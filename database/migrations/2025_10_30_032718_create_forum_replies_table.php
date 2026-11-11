<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_replies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('toid');
            $table->unsignedBigInteger('author');
            $table->text('comment');
            $table->timestamp('date')->useCurrent();
            $table->unsignedBigInteger('replyTo')->nullable();
            $table->boolean('sticked')->default(false);
            $table->boolean('edited')->default(false);
            $table->timestamp('edited_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_replies');
    }
};