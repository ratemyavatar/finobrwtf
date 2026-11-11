<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_configuration', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_configuration');
    }
};