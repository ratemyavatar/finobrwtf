<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('finobe')->table('messages', function (Blueprint $table) {
            $table->mediumText('message')->change();
        });
    }

    public function down(): void
    {
        Schema::connection('finobe')->table('messages', function (Blueprint $table) {
            $table->text('message')->change();
        });
    }
};
