<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('finobe')->hasColumn('users', 'created')) {
            Schema::connection('finobe')->table('users', function (Blueprint $table) {
                $table->timestamp('created')->useCurrent();
            });
        }

        DB::connection('finobe')->table('users')
            ->whereNull('created')
            ->orWhere('created', '<=', '1970-01-02 00:00:00')
            ->update(['created' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
    }
};
