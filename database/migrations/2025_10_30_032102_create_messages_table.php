<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author');
            $table->unsignedBigInteger('touser');
            $table->string('subject');
            $table->text('message');
            $table->timestamp('date')->useCurrent();
            $table->boolean('readed')->default(false);
            $table->boolean('archived')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};