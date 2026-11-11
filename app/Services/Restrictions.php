<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
    Restrictions
     - admins block a user from chatting (sending messages), the forum (posting and replying)
       or playing (joining games) from the user's moderation page in the admin panel
     - the panel stores these in admin_restrictions (user_id, feature, blocked)
     - Settings > Security > Restrictions shows the user their own
     - enforcement goes through active()/message() only
*/
class Restrictions
{
    const TYPES = ['chat' => 'Chat', 'forum' => 'Forum', 'play' => 'Playing'];

    /* the admin_restrictions features that enforce each type */
    const FEATURES = ['chat' => ['messages'], 'forum' => ['forum_posts'], 'play' => ['play']];

    /* the panel control that blocks each feature, so the reason can be read back from the audit log */
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

    /** the restriction in force for this user and type, or null */
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

    /** the reason the moderator gave, read back from the audit log, if there is one */
    private static function reasonFor(int $userId, string $feature): ?string
    {
        $action = self::BLOCK_ACTIONS[$feature] ?? null;
        if (!$action || !Schema::connection('finobe')->hasTable('admin_audit')) {
            return null;
        }
        return DB::connection('finobe')->table('admin_audit')->where('target_id', (string) $userId)
            ->where('action', $action)->orderByDesc('id')->value('reason');
    }

    /** "You're restricted from the forum until ...: reason" for showing to the user, or null */
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

    /** block every feature that enforces this type */
    public static function add(int $userId, string $type, string $reason, ?int $days, ?int $moderatorId): void
    {
        foreach (self::FEATURES[$type] ?? [] as $feature) {
            self::table()->updateOrInsert(['user_id' => $userId, 'feature' => $feature], ['blocked' => true]);
        }
    }

    /** lift the block on every feature that enforces this type */
    public static function lift(int $userId, string $type): int
    {
        $features = self::FEATURES[$type] ?? [];
        if (!$features) {
            return 0;
        }
        return self::table()->where('user_id', $userId)->whereIn('feature', $features)->update(['blocked' => false]);
    }
}
