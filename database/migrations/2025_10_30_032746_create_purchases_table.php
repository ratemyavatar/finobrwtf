<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->unsignedBigInteger('assetid');
            $table->unsignedBigInteger('serial');
            $table->timestamp('date')->useCurrent();
            $table->timestamp('lastchanged')->useCurrent()->useCurrentOnUpdate();
            $table->unsignedBigInteger('author');
            $table->decimal('amount', 10, 2);
            $table->string('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};