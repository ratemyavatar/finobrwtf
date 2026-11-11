<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('ip');
            $table->integer('port');
            $table->unsignedBigInteger('placeid');
            $table->longText('players');
            $table->string('jobId');
            $table->string('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};