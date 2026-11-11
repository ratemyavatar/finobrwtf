<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elections', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('author');
            $table->text('css')->nullable();
            $table->longText('options');
            $table->longText('votes');
            $table->timestamp('date')->useCurrent();
            $table->timestamp('expire')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elections');
    }
};