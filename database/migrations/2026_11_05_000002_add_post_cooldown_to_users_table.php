<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('finobe');

        if (!$schema->hasColumn('users', 'post_cooldown')) {
            $schema->table('users', function (Blueprint $table) {
                $table->timestamp('post_cooldown')->nullable();
            });
        }
    }

    public function down(): void
    {
    }
};
