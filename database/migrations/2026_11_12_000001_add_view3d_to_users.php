<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finobe';

    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasColumn('users', 'view3d')) {
            Schema::connection($this->connection)->table('users', function (Blueprint $table) {
                $table->tinyInteger('view3d')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('users', 'view3d')) {
            Schema::connection($this->connection)->table('users', function (Blueprint $table) {
                $table->dropColumn('view3d');
            });
        }
    }
};
