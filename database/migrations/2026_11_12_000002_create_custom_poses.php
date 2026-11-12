<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finobe';

    public function up(): void
    {
        if (!Schema::connection($this->connection)->hasTable('custom_poses')) {
            Schema::connection($this->connection)->create('custom_poses', function (Blueprint $table) {
                $table->id();
                $table->string('name', 50);
                $table->text('joints');
                $table->string('source', 120)->default('');
                $table->unsignedBigInteger('created_by')->default(0);
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('custom_poses');
    }
};
