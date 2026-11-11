<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('finobe')->table('users', function (Blueprint $table) {
            $table->timestamp('created')->useCurrent()->nullable()->change();
        });

        DB::connection('finobe')->statement(
            'UPDATE users SET created = COALESCE('
            . '(SELECT k.dateUsed FROM invitekeys k WHERE k.usedBy = users.username AND k.used = \'y\' LIMIT 1), '
            . 'created_at, CURRENT_TIMESTAMP) '
            . 'WHERE created IS NULL OR created <= \'1970-01-02 00:00:00\''
        );
    }

    public function down(): void
    {
    }
};
