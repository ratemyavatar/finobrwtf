<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // private messages can be long: TEXT holds 65535 bytes, which is only ~16k
    // characters once the text contains multi-byte (emoji/non-Latin) characters,
    // so widen the body before raising the app's limit
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
