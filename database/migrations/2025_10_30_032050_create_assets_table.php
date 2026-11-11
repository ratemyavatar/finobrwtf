<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_type');
            $table->string('title');
            $table->unsignedBigInteger('author');
            $table->string('file');
            $table->text('description')->nullable();
            $table->string('visibility')->default('public');
            $table->longText('additional')->nullable();
            $table->timestamp('created')->useCurrent();
            $table->timestamp('updated')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};