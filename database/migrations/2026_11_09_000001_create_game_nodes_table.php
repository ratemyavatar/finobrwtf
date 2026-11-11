<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('finobe')->hasTable('game_nodes')) {
            Schema::connection('finobe')->create('game_nodes', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('version', 10)->default('any');
                $table->string('public_ip');
                $table->string('control_url');
                $table->integer('port_min');
                $table->integer('port_max');
                $table->boolean('enabled')->default(true);
                $table->integer('weight')->default(1);
                $table->timestamps();
            });
        }

        if (!Schema::connection('finobe')->hasColumn('servers', 'node_id')) {
            Schema::connection('finobe')->table('servers', function (Blueprint $table) {
                $table->unsignedBigInteger('node_id')->nullable()->after('placeid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('finobe')->hasColumn('servers', 'node_id')) {
            Schema::connection('finobe')->table('servers', function (Blueprint $table) {
                $table->dropColumn('node_id');
            });
        }

        Schema::connection('finobe')->dropIfExists('game_nodes');
    }
};
