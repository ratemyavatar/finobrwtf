<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Settings > Security (two-factor, curse filter), Privacy and Language, and the restrictions
    // Security > Restrictions shows (set by admins on the user's moderation page)
    public function up(): void
    {
        $columns = [
            'two_factor_secret' => fn (Blueprint $t) => $t->text('two_factor_secret')->nullable(),
            'two_factor_backup_codes' => fn (Blueprint $t) => $t->text('two_factor_backup_codes')->nullable(),
            'two_factor_confirmed_at' => fn (Blueprint $t) => $t->timestamp('two_factor_confirmed_at')->nullable(),
            'curse_filter' => fn (Blueprint $t) => $t->boolean('curse_filter')->default(false),
            'privacy_messages' => fn (Blueprint $t) => $t->string('privacy_messages', 10)->default('everyone'),
            'privacy_friend_requests' => fn (Blueprint $t) => $t->string('privacy_friend_requests', 10)->default('everyone'),
            'privacy_inventory' => fn (Blueprint $t) => $t->string('privacy_inventory', 10)->default('everyone'),
            'locale' => fn (Blueprint $t) => $t->string('locale', 10)->nullable(),
        ];
        foreach ($columns as $name => $add) {
            if (!Schema::connection('finobe')->hasColumn('users', $name)) {
                Schema::connection('finobe')->table('users', $add);
            }
        }

        if (!Schema::connection('finobe')->hasTable('user_restrictions')) {
            Schema::connection('finobe')->create('user_restrictions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('type', 10);                 // chat, forum, play
                $table->string('reason', 1000);
                $table->unsignedBigInteger('moderator_id')->nullable();
                $table->timestamp('expires_at')->nullable(); // null = until lifted
                $table->timestamp('lifted_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('finobe')->dropIfExists('user_restrictions');
        Schema::connection('finobe')->table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_backup_codes', 'two_factor_confirmed_at',
                'curse_filter', 'privacy_messages', 'privacy_friend_requests', 'privacy_inventory', 'locale']);
        });
    }
};
