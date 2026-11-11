<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finobe';

    public function up(): void
    {
        if (!Schema::connection('finobe')->hasTable('trades')) {
            Schema::connection('finobe')->create('trades', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sender_id')->index();
                $table->unsignedBigInteger('receiver_id')->index();

                $table->string('status', 16)->default('pending')->index();

                $table->text('sender_items');
                $table->text('receiver_items');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::connection('finobe')->table('users', function (Blueprint $table) {
            if (!Schema::connection('finobe')->hasColumn('users', 'privacy_trades')) {
                $table->string('privacy_trades', 16)->default('everyone');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('finobe')->dropIfExists('trades');
        Schema::connection('finobe')->table('users', function (Blueprint $table) {
            if (Schema::connection('finobe')->hasColumn('users', 'privacy_trades')) {
                $table->dropColumn('privacy_trades');
            }
        });
    }
};
