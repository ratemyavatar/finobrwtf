<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('finobe')->table('servers', function (Blueprint $table) {
            if (!Schema::connection('finobe')->hasColumn('servers', 'started_at')) {
                $table->timestamp('started_at')->nullable();
            }
            if (!Schema::connection('finobe')->hasColumn('servers', 'last_alive')) {
                $table->timestamp('last_alive')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('finobe')->table('servers', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'last_alive']);
        });
    }
};
