<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UsernameChange
{
    const COLUMNS = [
        'purchases' => ['username'],
        'forum_threads' => ['author'],
        'forum_replies' => ['author'],
        'forum_ratings' => ['sender'],
        'subscriptions' => ['username'],
        'bans' => ['username', 'moderator'],
        'warning' => ['username', 'moderator'],
        'invitekeys' => ['author', 'usedBy'],
        'videos' => ['author'],
        'video_ratings' => ['sender'],
        'elections' => ['author'],
        'announcements' => ['author'],
        'verify_email' => ['username'],
        'reset_password' => ['username'],
    ];

    public static function cost(): int
    {
        return max(0, (int) config('finobe.name_change.cost', 150));
    }

    public static function onlyCapitalization(User $user, string $name): bool
    {
        return strtolower($name) === strtolower($user->username);
    }

    public static function costFor(User $user, string $name): int
    {
        return self::onlyCapitalization($user, $name) ? 0 : self::cost();
    }

    public static function cooldownDays(): int
    {
        return max(0, (int) config('finobe.name_change.cooldown_days', 0));
    }

    public static function nextAllowed(User $user): ?\Carbon\Carbon
    {
        if (!Schema::connection('finobe')->hasTable('username_history')) {
            return null;
        }
        $last = DB::connection('finobe')->table('username_history')->where('user_id', $user->id)->max('created_at');
        if (!$last) {
            return null;
        }
        $next = \Carbon\Carbon::parse($last)->addDays(self::cooldownDays());
        return $next->isFuture() ? $next : null;
    }

    public static function problem(User $user, string $name): ?string
    {
        if ($name === $user->username) {
            return "That's already your username.";
        }
        if ($problem = Usernames::problem($name, $user->id)) {
            return $problem;
        }
        if (!self::onlyCapitalization($user, $name) && ($next = self::nextAllowed($user)) !== null) {
            return 'You can change your username again on ' . $next->format('m/d/Y h:i A') . '.';
        }
        if ($user->Dius < self::costFor($user, $name)) {
            return 'It costs ' . self::cost() . " Dius to change your name, and you don't have enough.";
        }
        return null;
    }

    public static function forceProblem(User $user, string $name): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_ \[\]]{3,30}$/', $name)) {
            return 'Use 3 to 30 letters, numbers, spaces, underscores or [ ].';
        }
        if ($name === $user->username) {
            return "That's already their username.";
        }
        if (User::whereRaw('LOWER(username) = ?', [strtolower($name)])->where('id', '!=', $user->id)->exists()) {
            return 'That username is taken.';
        }
        return null;
    }

    public static function force(User $user, string $name): void
    {
        self::apply($user, $name, true);
    }

    public static function apply(User $user, string $name, bool $forced = false): void
    {
        $db = DB::connection('finobe');
        $old = $user->username;
        $cost = $forced ? 0 : self::costFor($user, $name);

        $db->transaction(function () use ($db, $user, $old, $name, $cost) {
            $fresh = $db->table('users')->where('id', $user->id)->lockForUpdate()->first();
            if (User::whereRaw('LOWER(username) = ?', [strtolower($name)])->where('id', '!=', $user->id)->exists()) {
                throw new \RuntimeException('That username is taken.');
            }
            if ($fresh->Dius < $cost) {
                throw new \RuntimeException('It costs ' . $cost . " Dius to change your name, and you don't have enough.");
            }

            foreach (self::COLUMNS as $table => $columns) {
                if (!Schema::connection('finobe')->hasTable($table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    if (Schema::connection('finobe')->hasColumn($table, $column)) {
                        $db->table($table)->where($column, $old)->update([$column => $name]);
                    }
                }
            }

            $db->table('users')->where('id', $user->id)->update([
                'username' => $name,
                'Dius' => $fresh->Dius - $cost,
            ]);

            if ($cost > 0) {
                \App\Models\Purchases::create([
                    'username' => $name, 'assetid' => 0, 'serial' => 0, 'author' => 1,
                    'amount' => -$cost, 'type' => 3,
                ]);
            }

            $db->table('username_history')->insert([
                'user_id' => $user->id,
                'old_username' => $old,
                'new_username' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        \Illuminate\Support\Facades\Cache::forget('username_' . $user->id);
        $user->refresh();
    }
}
