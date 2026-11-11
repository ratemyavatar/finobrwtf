<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author');
            $table->text('message');
            $table->timestamp('date')->useCurrent();
            $table->timestamp('expire')->nullable();
            $table->string('color')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};