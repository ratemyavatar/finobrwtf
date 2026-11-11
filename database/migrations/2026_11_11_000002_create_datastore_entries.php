<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('finobe')->hasTable('datastore_entries')) {
            return;
        }

        Schema::connection('finobe')->create('datastore_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('place_id');
            $table->string('type', 16);
            $table->string('scope', 64);
            $table->string('store', 64);
            $table->string('target', 64);
            $table->longText('value');
            $table->bigInteger('int_value')->nullable();
            $table->timestamps();
            $table->unique(['place_id', 'type', 'scope', 'store', 'target'], 'datastore_entries_key');
            $table->index(['place_id', 'type', 'scope', 'store', 'int_value'], 'datastore_entries_sorted');
        });
    }

    public function down(): void
    {
        Schema::connection('finobe')->dropIfExists('datastore_entries');
    }
};
