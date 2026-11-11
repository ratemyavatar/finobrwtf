<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitekeys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author');
            $table->string('IID');
            $table->timestamp('creation')->useCurrent();
            $table->timestamp('dateUsed')->nullable();
            $table->boolean('used')->default(false);
            $table->unsignedBigInteger('usedBy')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitekeys');
    }
};