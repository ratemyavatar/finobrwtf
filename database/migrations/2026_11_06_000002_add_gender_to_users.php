<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('finobe')->hasColumn('users', 'gender')) {
            Schema::connection('finobe')->table('users', function (Blueprint $table) {
                $table->string('gender', 1)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('finobe')->table('users', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
