<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
    Game nodes: the machines that can host game servers. A node is a 2012 host2012 machine (see
    App\Services\Host2012). Players connect to public_ip; the web server starts/stops servers through
    control_url. servers.node_id records which node a running server is on so it can be stopped there.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('finobe')->hasTable('game_nodes')) {
            Schema::connection('finobe')->create('game_nodes', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('version', 10)->default('any'); // 2012, 2016 or any
                $table->string('public_ip');                   // address players connect to for game UDP
                $table->string('control_url');                 // base URL the web server calls (Host2012)
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
