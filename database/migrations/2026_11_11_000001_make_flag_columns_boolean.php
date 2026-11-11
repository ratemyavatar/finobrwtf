<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finobe';

    private const FLAGS = [
        'bans' => ['perm', 'reactivated', 'undone'],
        'warning' => ['reactivated', 'undone'],
        'invitekeys' => ['used'],
        'reset_password' => ['used'],
        'verify_email' => ['used'],
        'forum_threads' => ['edited', 'locked', 'pinned'],
        'forum_replies' => ['edited', 'sticked'],
    ];

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $db = DB::connection($this->connection);

        foreach (self::FLAGS as $table => $columns) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    continue;
                }

                if (!in_array($schema->getColumnType($table, $column), ['string', 'varchar', 'char', 'text', 'enum'], true)) {
                    continue;
                }

                $db->table($table)->update([
                    $column => $db->raw("CASE WHEN LOWER(TRIM(`{$column}`)) IN ('y', 'yes', '1', 'true') THEN '1' ELSE '0' END"),
                ]);

                $schema->table($table, function (Blueprint $t) use ($column) {
                    $t->boolean($column)->default(false)->change();
                });
            }
        }
    }

    public function down(): void
    {
    }
};
