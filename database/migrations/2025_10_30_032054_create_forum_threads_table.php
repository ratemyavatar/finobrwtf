<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_threads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category');
            $table->unsignedBigInteger('author');
            $table->string('title');
            $table->text('comment');
            $table->timestamp('date')->useCurrent();
            $table->timestamp('lastreplied')->useCurrent();
            $table->boolean('pinned')->default(false);
            $table->boolean('locked')->default(false);
            $table->boolean('edited')->default(false);
            $table->timestamp('edited_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_threads');
    }
};