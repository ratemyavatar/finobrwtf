<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Restrictions
{
    const TYPES = ['chat' => 'Chat', 'forum' => 'Forum', 'play' => 'Playing'];

    const FEATURES = ['chat' => ['messages'], 'forum' => ['forum_posts'], 'play' => ['play']];

    const BLOCK_ACTIONS = [
        'messages' => 'access-restrictions-block-private-messages',
        'forum_posts' => 'access-restrictions-block-forum-posting',
        'play' => 'access-restrictions-block-playing',
    ];

    private static function table()
    {
        return DB::connection('finobe')->table('admin_restrictions');
    }

    public static function available(): bool
    {
        static $has = null;
        return $has ??= Schema::connection('finobe')->hasTable('admin_restrictions');
    }

    public static function active(int $userId, string $type): ?object
    {
        $features = self::FEATURES[$type] ?? [];
        if (!$features || !self::available()) {
            return null;
        }
        $row = self::table()->where('user_id', $userId)->whereIn('feature', $features)->where('blocked', true)->first();
        if (!$row) {
            return null;
        }
        return (object) [
            'type' => $type,
            'feature' => $row->feature,
            'reason' => self::reasonFor($userId, $row->feature),
            'expires_at' => null,
        ];
    }

    private static function reasonFor(int $userId, string $feature): ?string
    {
        $action = self::BLOCK_ACTIONS[$feature] ?? null;
        if (!$action || !Schema::connection('finobe')->hasTable('admin_audit')) {
            return null;
        }
        return DB::connection('finobe')->table('admin_audit')->where('target_id', (string) $userId)
            ->where('action', $action)->orderByDesc('id')->value('reason');
    }

    public static function message(int $userId, string $type): ?string
    {
        $r = self::active($userId, $type);
        if (!$r) {
            return null;
        }
        $what = ['chat' => 'sending messages', 'forum' => 'posting on the forum', 'play' => 'playing games'][$type];
        $until = $r->expires_at ? ' until ' . \Carbon\Carbon::parse($r->expires_at)->format('m/d/Y h:i A') : '';
        $reason = $r->reason ?: 'Restricted by Finobe staff.';
        return "You're restricted from {$what}{$until}. Reason: {$reason}";
    }

    public static function add(int $userId, string $type, string $reason, ?int $days, ?int $moderatorId): void
    {
        foreach (self::FEATURES[$type] ?? [] as $feature) {
            self::table()->updateOrInsert(['user_id' => $userId, 'feature' => $feature], ['blocked' => true]);
        }
    }

    public static function lift(int $userId, string $type): int
    {
        $features = self::FEATURES[$type] ?? [];
        if (!$features) {
            return 0;
        }
        return self::table()->where('user_id', $userId)->whereIn('feature', $features)->update(['blocked' => false]);
    }
}
