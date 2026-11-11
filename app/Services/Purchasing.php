<?php

namespace App\Services;

use App\Http\Controllers\Asset as AssetHelper;
use App\Models\Asset;
use App\Models\Purchases;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Purchasing
{
    const BUYABLE = [2, 3, 8, 11, 12, 17, 18, 19, 32, 34];
    const GAME_PASS = 34;

    public static function price(Asset $item): int
    {
        return max(0, (int) ($item->additional['price'] ?? 0));
    }

    public static function forSale(Asset $item): bool
    {
        return in_array((int) $item->asset_type, self::BUYABLE, true)
            && $item->visibility === 'n'
            && (bool) ($item->additional['onSale'] ?? false);
    }

    public static function owns(int $userId, int $assetId): bool
    {
        if ($userId <= 0 || $assetId <= 0) {
            return false;
        }

        $item = Asset::find($assetId);
        if (!$item) {
            return false;
        }

        if ((int) $item->author === $userId) {
            return true;
        }

        $username = User::where('id', $userId)->value('username');

        return $username !== null && Purchases::where('username', $username)
            ->where('assetid', $assetId)
            ->where('type', 1)
            ->exists();
    }

    public static function buy(int $buyerId, Asset $item, ?int $expectedPrice = null): ?string
    {
        if (!in_array((int) $item->asset_type, self::BUYABLE, true) || $item->visibility !== 'n') {
            return 'Invalid item type';
        }

        if (!($item->additional['onSale'] ?? false)) {
            return 'This item not on sale';
        }

        if ((int) $item->author === $buyerId) {
            return 'You made this item, so you already own it';
        }

        $price = self::price($item);
        if ($expectedPrice !== null && $expectedPrice !== $price) {
            return 'The price changed';
        }

        $error = null;

        DB::connection('finobe')->transaction(function () use ($item, $price, $buyerId, &$error) {
            $user = User::where('id', $buyerId)->lockForUpdate()->first();

            if (!$user) {
                $error = 'Bad request';
                return;
            }

            if ($user->Dius < $price) {
                $error = 'Not enough Dius';
                return;
            }

            if (Purchases::where('username', $user->username)->where('assetid', $item->id)->where('type', 1)->exists()) {
                $error = 'This item has already been purchased';
                return;
            }

            $serial = 0;
            if (Trading::kind($item->additional) === 'unique') {
                Asset::where('id', $item->id)->lockForUpdate()->first();
                $serial = (int) Purchases::where('assetid', $item->id)->max('serial') + 1;
                $stock = (int) ($item->additional['stock'] ?? 0);
                if ($stock > 0 && $serial > $stock) {
                    $error = 'This item is sold out';
                    return;
                }
            }

            Purchases::create([
                'username' => $user->username,
                'assetid' => $item->id,
                'serial' => $serial,
                'author' => $item->author,
                'amount' => -1 * $price,
                'type' => 1,
            ]);

            $user->Dius -= $price;
            $user->save();

            $seller = User::where('id', $item->author)->lockForUpdate()->first();
            if ($seller) {
                $seller->Dius += $price;
                $seller->save();

                Purchases::create([
                    'username' => $seller->username,
                    'assetid' => $item->id,
                    'serial' => 0,
                    'author' => $item->author,
                    'amount' => $price,
                    'type' => 2,
                ]);
            }
        });

        if ($error === null && (int) $item->asset_type === 32) {
            self::grantPackageContents($item, $buyerId);
        }

        return $error;
    }

    private static function grantPackageContents(Asset $package, int $buyerId): void
    {
        $buyer = User::find($buyerId);
        if (!$buyer) {
            return;
        }

        foreach (AssetHelper::getPackageAssets((int) $package->id) as $assetId) {
            $contained = Asset::find($assetId);
            if (!$contained || (int) $contained->author === $buyerId) {
                continue;
            }

            $already = Purchases::where('username', $buyer->username)
                ->where('assetid', $assetId)
                ->where('type', 1)
                ->exists();
            if ($already) {
                continue;
            }

            Purchases::create([
                'username' => $buyer->username,
                'assetid' => $assetId,
                'serial' => 0,
                'author' => $contained->author,
                'amount' => 0,
                'type' => 1,
            ]);
        }
    }
}
