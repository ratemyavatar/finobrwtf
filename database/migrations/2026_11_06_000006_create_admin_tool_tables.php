<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finobe';

    public function up(): void
    {
        $s = Schema::connection('finobe');
        if (!$s->hasTable('ip_bans')) {
            $s->create('ip_bans', function (Blueprint $table) {
                $table->id();
                $table->string('ip', 45)->unique();
                $table->string('reason')->nullable();
                $table->unsignedBigInteger('moderator_id')->nullable();
                $table->timestamps();
            });
        }
        if (!$s->hasTable('asset_approval_log')) {
            $s->create('asset_approval_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('asset_id')->index();
                $table->unsignedBigInteger('moderator_id')->nullable();
                $table->string('action', 16);
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('finobe')->dropIfExists('ip_bans');
        Schema::connection('finobe')->dropIfExists('asset_approval_log');
    }
};
