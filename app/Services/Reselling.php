<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Purchases;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Reselling
{
    const MAX_PRICE = 499800;
    const TYPE_SOLD = 7;

    public static function available(): bool
    {
        try {
            return \Illuminate\Support\Facades\Schema::connection('finobe')->hasTable('resellers');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function dius(int $amount): string
    {
        return number_format($amount) . ($amount === 1 ? ' Diu' : ' Dius');
    }

    public static function sellable(?Purchases $row, User $user): ?Asset
    {
        if (!$row || (int) $row->type !== Trading::TYPE_OWNED || $row->username !== $user->username || (int) $row->serial <= 0) {
            return null;
        }
        $asset = Asset::find($row->assetid);
        return $asset && Trading::kind($asset) === 'unique' ? $asset : null;
    }

    public static function listingFor(int $purchaseId): ?object
    {
        return DB::connection('finobe')->table('resellers')->where('purchase_id', $purchaseId)->first();
    }

    public static function sell(User $user, int $purchaseId, $price): Asset
    {
        $asset = self::sellable(Purchases::find($purchaseId), $user);
        if (!$asset) {
            throw new \RuntimeException("You can't sell that.");
        }
        if (!is_numeric($price) || (int) $price != $price || (int) $price < 1 || (int) $price > self::MAX_PRICE) {
            throw new \RuntimeException('Pick a price between 1 and ' . number_format(self::MAX_PRICE) . ' Dius.');
        }
        DB::connection('finobe')->table('resellers')->updateOrInsert(
            ['purchase_id' => $purchaseId],
            ['assetid' => (int) $asset->id, 'seller_id' => (int) $user->id, 'price' => (int) $price, 'created_at' => now(), 'updated_at' => now()]
        );
        return $asset;
    }

    public static function remove(User $user, int $listingId): int
    {
        $listing = DB::connection('finobe')->table('resellers')->where('id', $listingId)->first();
        if (!$listing || (int) $listing->seller_id !== (int) $user->id) {
            throw new \RuntimeException("That isn't your listing.");
        }
        DB::connection('finobe')->table('resellers')->where('id', $listingId)->delete();
        return (int) $listing->assetid;
    }

    public static function buy(User $buyer, int $listingId): array
    {
        $db = DB::connection('finobe');
        $result = null;

        $db->transaction(function () use ($db, $buyer, $listingId, &$result) {
            $listing = $db->table('resellers')->where('id', $listingId)->lockForUpdate()->first();
            if (!$listing) {
                throw new \RuntimeException('That copy was already sold.');
            }
            if ((int) $listing->seller_id === (int) $buyer->id) {
                throw new \RuntimeException("You can't buy your own copy.");
            }

            $users = User::whereIn('id', [(int) $listing->seller_id, (int) $buyer->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $seller = $users[(int) $listing->seller_id] ?? null;
            $me = $users[(int) $buyer->id] ?? null;
            $row = Purchases::where('id', $listing->purchase_id)->lockForUpdate()->first();

            if (!$seller || !$me || !self::sellable($row, $seller)) {
                $db->table('resellers')->where('id', $listingId)->delete();
                throw new \RuntimeException('That copy is no longer for sale.');
            }
            $price = (int) $listing->price;
            if ($me->Dius < $price) {
                throw new \RuntimeException('Not enough Dius');
            }

            $row->type = self::TYPE_SOLD;
            $row->save();
            Purchases::create([
                'username' => $me->username,
                'assetid' => $row->assetid,
                'serial' => $row->serial,
                'author' => $seller->id,
                'amount' => -1 * $price,
                'type' => Trading::TYPE_OWNED,
            ]);
            Purchases::create([
                'username' => $seller->username,
                'assetid' => $row->assetid,
                'serial' => 0,
                'author' => $seller->id,
                'amount' => $price,
                'type' => 2,
            ]);

            $me->Dius -= $price;
            $me->save();
            $seller->Dius += $price;
            $seller->save();

            $db->table('resellers')->where('id', $listingId)->delete();
            $db->table('resale_history')->insert(['assetid' => (int) $row->assetid, 'price' => $price, 'created_at' => now()]);

            $result = ['assetid' => (int) $row->assetid, 'price' => $price, 'seller' => $seller];
        });

        Trading::unequipUnowned($result['seller'], [$result['assetid']]);
        return $result;
    }

    public static function listings(int $assetId): array
    {
        if (!self::available()) {
            return [];
        }
        return DB::connection('finobe')->table('resellers as r')
            ->join('purchases as p', 'p.id', '=', 'r.purchase_id')
            ->join('users as u', 'u.id', '=', 'r.seller_id')
            ->where('r.assetid', $assetId)
            ->where('p.type', Trading::TYPE_OWNED)
            ->whereColumn('p.username', 'u.username')
            ->orderBy('r.price')->orderBy('r.id')
            ->get(['r.id', 'r.price', 'r.seller_id', 'u.username', 'p.serial'])
            ->map(fn($l) => [
                'id' => (int) $l->id,
                'price' => (int) $l->price,
                'seller_id' => (int) $l->seller_id,
                'seller' => htmlspecialchars($l->username),
                'serial' => (int) $l->serial,
            ])->all();
    }

    public static function lowestAsks(array $assetIds): array
    {
        if (!$assetIds || !self::available()) {
            return [];
        }
        return DB::connection('finobe')->table('resellers as r')
            ->join('purchases as p', 'p.id', '=', 'r.purchase_id')
            ->join('users as u', 'u.id', '=', 'r.seller_id')
            ->whereIn('r.assetid', $assetIds)
            ->where('p.type', Trading::TYPE_OWNED)
            ->whereColumn('p.username', 'u.username')
            ->groupBy('r.assetid')
            ->selectRaw('r.assetid as assetid, MIN(r.price) as ask')
            ->get()
            ->mapWithKeys(fn($r) => [(int) $r->assetid => (int) $r->ask])->all();
    }

    public static function history(int $assetId): array
    {
        if (!self::available()) {
            return [];
        }
        return DB::connection('finobe')->table('resale_history')->where('assetid', $assetId)
            ->orderByDesc('id')->limit(50)->get(['price', 'created_at'])->reverse()->values()
            ->map(fn($h) => ['price' => (int) $h->price, 'date' => $h->created_at])->all();
    }

    public static function chart(array $history, int $w = 800, int $h = 160): ?array
    {
        if (!$history) {
            return null;
        }
        $prices = array_column($history, 'price');
        $max = max($prices);
        $min = min($prices);
        $top = $max === $min ? $max + 1 : $max;
        $bottom = $max === $min ? max(0, $min - 1) : $min;
        $n = count($prices);
        $points = [];
        foreach ($prices as $i => $p) {
            $x = $n === 1 ? $w / 2 : 40 + ($w - 50) * $i / ($n - 1);
            $y = 10 + ($h - 30) * (1 - ($p - $bottom) / ($top - $bottom));
            $points[] = round($x, 1) . ',' . round($y, 1);
        }
        return ['points' => implode(' ', $points), 'top' => $top, 'bottom' => $bottom, 'width' => $w, 'height' => $h];
    }
}
