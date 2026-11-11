<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ForumController rate-limits posting by reading users.post_cooldown, but
     * the source schema dump never created the column. Its sibling
     * render_cooldown is added by the admin workbench migration; this one was
     * missed, so every new post/reply threw "Undefined array key post_cooldown".
     */
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
        // intentionally left empty: live data may depend on the column
    }
};
