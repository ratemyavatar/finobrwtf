<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = [
        'forum_threads' => ['author' => false],
        'forum_replies' => ['author' => false],
        'forum_ratings' => ['sender' => false],
        'video_ratings' => ['sender' => false],
        'invitekeys' => ['author' => false, 'usedBy' => true],
        'elections' => ['author' => false],
        'announcements' => ['author' => false],
        'videos' => ['author' => false],
    ];

    public function up(): void
    {
        $schema = Schema::connection('finobe');

        foreach ($this->columns as $table => $columns) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $nullable) {
                if (!$schema->hasColumn($table, $column)) {
                    continue;
                }

                $type = strtolower($schema->getColumnType($table, $column));

                if (in_array($type, ['varchar', 'string', 'text', 'char', 'longtext', 'mediumtext', 'tinytext'], true)) {
                    continue;
                }

                $schema->table($table, function (Blueprint $t) use ($column, $nullable) {
                    $nullable ? $t->string($column)->nullable()->change() : $t->string($column)->change();
                });
            }
        }
    }

    public function down(): void
    {
    }
};
