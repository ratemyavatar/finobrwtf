<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verify_email', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->timestamp('date')->useCurrent();
            $table->timestamp('expire')->nullable();
            $table->boolean('used')->default(false);
            $table->string('uid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verify_email');
    }
};