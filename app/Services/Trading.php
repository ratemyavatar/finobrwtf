<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Purchases;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
    Trading
     - Limited and Unique items can be traded; a trade lists the exact copies (purchases rows, so
       serials travel with them) each side gives
     - both people need two-factor authentication on, like the real site ("Enable 2FA to trade.")
     - accepting moves every copy in one locked transaction: the old owner's purchases row becomes
       type 6 (traded, kept for their transaction log) and the new owner gets a type 1 row (owned)
     - any other pending trade with one of those copies in it can't happen any more: it's marked inactive
*/
class Trading
{
    const MAX_ITEMS = 6;          // per side
    const TYPE_OWNED = 1;
    const TYPE_TRADED = 6;

    const STATUSES = ['pending', 'accepted', 'declined', 'revoked', 'inactive'];

    public static function available(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::connection('finobe')->hasTable('trades');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** "limited", "unique" or null for an asset (row, model or its additional array) */
    public static function kind($additional): ?string
    {
        if ($additional instanceof Asset) {
            $additional = $additional->additional;
        }
        $additional = is_array($additional) ? $additional : (json_decode((string) $additional, true) ?: []);
        if (!empty($additional['isUnique'])) {
            return 'unique';
        }
        return !empty($additional['isLimited']) ? 'limited' : null;
    }

    /** what an item is worth: the value an admin set, or its price */
    public static function value($additional): int
    {
        if ($additional instanceof Asset) {
            $additional = $additional->additional;
        }
        $additional = is_array($additional) ? $additional : (json_decode((string) $additional, true) ?: []);
        if (isset($additional['value']) && $additional['value'] !== '' && $additional['value'] !== null) {
            return max(0, (int) $additional['value']);
        }
        return max(0, (int) ($additional['price'] ?? 0));
    }

    /** the card shown for one copy: title, thumbnail, Limited / Unique #serial, value */
    public static function card(Purchases $row, ?Asset $asset = null): ?array
    {
        $asset = $asset ?: Asset::find($row->assetid);
        if (!$asset || !($kind = self::kind($asset))) {
            return null;
        }
        $additional = $asset->additional ?: [];
        return [
            'id' => (int) $row->id,
            'assetid' => (int) $asset->id,
            'title' => $asset->title,
            'thumbnail' => $additional['media']['thumbnail'] ?? '/s/img/finnobe3logo.png',
            'kind' => $kind,
            // copies of a Unique handed out before it had serials show "N/A", like the real site
            'serial' => (int) $row->serial > 0 ? '#' . (int) $row->serial : 'N/A',
            'value' => self::value($asset),
        ];
    }

    /** the copies of tradable items $user owns right now */
    public static function tradable(User $user): array
    {
        $rows = Purchases::where('username', $user->username)->where('type', self::TYPE_OWNED)->orderByDesc('id')->get();
        $assets = Asset::whereIn('id', $rows->pluck('assetid')->unique()->all())->where('visibility', 'n')->get()->keyBy('id');
        $cards = [];
        foreach ($rows as $row) {
            if (($asset = $assets[$row->assetid] ?? null) && ($card = self::card($row, $asset))) {
                $cards[] = $card;
            }
        }
        return $cards;
    }

    /** why $sender can't send $receiver a trade at all, or null */
    public static function cantTrade(User $sender, User $receiver): ?string
    {
        if ((int) $sender->id === (int) $receiver->id) {
            return "You can't trade with yourself.";
        }
        if (!TwoFactor::enabled($sender)) {
            return 'Enable 2FA to trade.';
        }
        if (!TwoFactor::enabled($receiver)) {
            return $receiver->username . " doesn't have 2FA on, so they can't trade.";
        }
        if (!Privacy::allows($receiver, 'trades', $sender)) {
            return $receiver->username . " isn't accepting trades from you.";
        }
        return null;
    }

    private static function ids($list): array
    {
        $list = is_array($list) ? $list : (json_decode((string) $list, true) ?: []);
        return array_values(array_unique(array_filter(array_map('intval', $list))));
    }

    /** sends a trade; returns the new trade id, or throws \RuntimeException with the reason */
    public static function send(User $sender, User $receiver, array $give, array $receive): int
    {
        if ($problem = self::cantTrade($sender, $receiver)) {
            throw new \RuntimeException($problem);
        }
        $give = self::ids($give);
        $receive = self::ids($receive);
        if (!$give || !$receive) {
            throw new \RuntimeException('Pick at least one item to give and one to receive.');
        }
        if (count($give) > self::MAX_ITEMS || count($receive) > self::MAX_ITEMS) {
            throw new \RuntimeException('You can trade up to ' . self::MAX_ITEMS . ' items on each side.');
        }
        $mine = array_column(self::tradable($sender), 'id');
        $theirs = array_column(self::tradable($receiver), 'id');
        if (array_diff($give, $mine) || array_diff($receive, $theirs)) {
            throw new \RuntimeException("One of those items isn't available to trade any more.");
        }

        $id = DB::connection('finobe')->table('trades')->insertGetId([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'status' => 'pending',
            'sender_items' => json_encode($give),
            'receiver_items' => json_encode($receive),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \App\Models\Notification::create([
            'owner' => $sender->id,
            'touser' => $receiver->id,
            'message' => $sender->username . ' sent you a trade',
        ]);

        return $id;
    }

    public static function find(int $id): ?object
    {
        return DB::connection('finobe')->table('trades')->where('id', $id)->first();
    }

    /** the trade as $viewer sees it: partner, what they give / receive and what they can do */
    public static function view(object $trade, User $viewer): array
    {
        $isSender = (int) $trade->sender_id === (int) $viewer->id;
        $partner = User::find($isSender ? $trade->receiver_id : $trade->sender_id);
        $side = function ($ids) {
            $ids = self::ids($ids);
            $rows = Purchases::whereIn('id', $ids)->get()->keyBy('id');
            $cards = [];
            foreach ($ids as $id) {
                if (isset($rows[$id]) && ($card = self::card($rows[$id]))) {
                    $cards[] = $card;
                }
            }
            return ['items' => $cards, 'value' => array_sum(array_column($cards, 'value'))];
        };
        return [
            'id' => (int) $trade->id,
            'status' => $trade->status,
            'incoming' => !$isSender,
            'partner' => $partner ? ['id' => $partner->id, 'username' => $partner->username, 'pfp' => $partner->pfp] : null,
            'give' => $side($isSender ? $trade->sender_items : $trade->receiver_items),
            'receive' => $side($isSender ? $trade->receiver_items : $trade->sender_items),
        ];
    }

    /** the receiver accepts: every copy changes hands, or nothing does */
    public static function accept(int $tradeId, User $viewer): void
    {
        if (!TwoFactor::enabled($viewer)) {
            throw new \RuntimeException('Enable 2FA to trade.');
        }
        $moved = [];
        $db = DB::connection('finobe');
        $db->transaction(function () use ($db, $tradeId, $viewer, &$moved) {
            $trade = $db->table('trades')->where('id', $tradeId)->lockForUpdate()->first();
            if (!$trade || (int) $trade->receiver_id !== (int) $viewer->id || $trade->status !== 'pending') {
                throw new \RuntimeException("This trade can't be accepted.");
            }
            $sender = User::where('id', $trade->sender_id)->lockForUpdate()->first();
            $receiver = User::where('id', $trade->receiver_id)->lockForUpdate()->first();
            if (!$sender || !$receiver) {
                throw new \RuntimeException("This trade can't be accepted.");
            }

            $sides = [[self::ids($trade->sender_items), $sender, $receiver], [self::ids($trade->receiver_items), $receiver, $sender]];
            $all = array_merge($sides[0][0], $sides[1][0]);
            $rows = Purchases::whereIn('id', $all)->lockForUpdate()->get()->keyBy('id');

            // every copy must still belong to the side giving it and still be tradable
            foreach ($sides as [$ids, $from]) {
                foreach ($ids as $id) {
                    $row = $rows[$id] ?? null;
                    if (!$row || (int) $row->type !== self::TYPE_OWNED || $row->username !== $from->username || !self::kind(Asset::find($row->assetid))) {
                        $db->table('trades')->where('id', $trade->id)->update(['status' => 'inactive', 'updated_at' => now()]);
                        throw new \RuntimeException('Some of the items in this trade have changed hands, so it can no longer happen.');
                    }
                }
            }

            foreach ($sides as [$ids, $from, $to]) {
                foreach ($ids as $id) {
                    $row = $rows[$id];
                    $row->type = self::TYPE_TRADED;
                    $row->save();
                    Purchases::create([
                        'username' => $to->username,
                        'assetid' => $row->assetid,
                        'serial' => $row->serial,
                        'author' => $from->id,
                        'amount' => 0,
                        'type' => self::TYPE_OWNED,
                    ]);
                    $moved[$from->id][] = (int) $row->assetid;
                }
            }

            $db->table('trades')->where('id', $trade->id)->update(['status' => 'accepted', 'completed_at' => now(), 'updated_at' => now()]);

            // other pending trades with any of these copies in them can't happen now
            $others = $db->table('trades')->where('status', 'pending')->where('id', '!=', $trade->id)
                ->where(fn ($q) => $q->whereIn('sender_id', [$sender->id, $receiver->id])->orWhereIn('receiver_id', [$sender->id, $receiver->id]))
                ->get();
            foreach ($others as $other) {
                if (array_intersect($all, array_merge(self::ids($other->sender_items), self::ids($other->receiver_items)))) {
                    $db->table('trades')->where('id', $other->id)->update(['status' => 'inactive', 'updated_at' => now()]);
                }
            }

            \App\Models\Notification::create([
                'owner' => $receiver->id,
                'touser' => $sender->id,
                'message' => $receiver->username . ' accepted your trade',
            ]);
        });

        // take traded-away items off avatars (unless they still own another copy)
        foreach ($moved as $userId => $assetIds) {
            self::unequipUnowned(User::find($userId), $assetIds);
        }
    }

    public static function decline(int $tradeId, User $viewer): void
    {
        self::close($tradeId, $viewer, 'receiver_id', 'declined');
    }

    public static function revoke(int $tradeId, User $viewer): void
    {
        self::close($tradeId, $viewer, 'sender_id', 'revoked');
    }

    private static function close(int $tradeId, User $viewer, string $who, string $status): void
    {
        $n = DB::connection('finobe')->table('trades')->where('id', $tradeId)->where($who, $viewer->id)->where('status', 'pending')
            ->update(['status' => $status, 'completed_at' => now(), 'updated_at' => now()]);
        if (!$n) {
            throw new \RuntimeException("This trade can't be changed any more.");
        }
    }

    public static function unequipUnowned(?User $user, array $assetIds): void
    {
        if (!$user) {
            return;
        }
        $avatar = $user->avatar;
        $changed = false;
        foreach (array_unique($assetIds) as $assetId) {
            if (Purchases::where('username', $user->username)->where('assetid', $assetId)->where('type', self::TYPE_OWNED)->exists()) {
                continue;
            }
            foreach (['equippedGearVersionIds', 'backpackGearVersionIds'] as $key) {
                if (isset($avatar[0][$key]) && in_array((int) $assetId, array_map('intval', $avatar[0][$key]), true)) {
                    $avatar[0][$key] = array_values(array_filter($avatar[0][$key], fn ($x) => (int) $x !== (int) $assetId));
                    $changed = true;
                }
            }
        }
        if ($changed) {
            $user->avatar = $avatar;
            $user->save();
            \App\Jobs\RenderAvatar::dispatchAfterResponse($user->id);
        }
    }

    /** incoming trades waiting on $userId (the red number next to Trades) */
    public static function pendingIncoming(int $userId): int
    {
        try {
            return DB::connection('finobe')->table('trades')->where('receiver_id', $userId)->where('status', 'pending')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
