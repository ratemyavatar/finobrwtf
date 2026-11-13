<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finobe';

    public function up(): void
    {
        if (!Schema::connection('finobe')->hasTable('resellers')) {
            Schema::connection('finobe')->create('resellers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('purchase_id')->unique();
                $table->unsignedBigInteger('assetid')->index();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedInteger('price');
                $table->timestamps();
            });
        }

        if (!Schema::connection('finobe')->hasTable('resale_history')) {
            Schema::connection('finobe')->create('resale_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assetid')->index();
                $table->unsignedInteger('price');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('finobe')->dropIfExists('resellers');
        Schema::connection('finobe')->dropIfExists('resale_history');
    }
};
