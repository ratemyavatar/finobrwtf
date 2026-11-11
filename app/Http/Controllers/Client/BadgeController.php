<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\RobloxUtilities;
use App\Models\Asset;
use App\Models\PlaceBadgeAward;
use App\Models\User;
use Illuminate\Http\Request;

class BadgeController extends Controller
{
    private function badge(int $badgeId): ?Asset
    {
        return Asset::where('id', $badgeId)->where('asset_type', 21)->where('visibility', 'n')->first();
    }

    private function belongsToPlace(?Asset $badge, int $placeId): bool
    {
        return $badge && (int) ($badge->additional['placeId'] ?? 0) === $placeId;
    }

    public function hasBadge(Request $request)
    {
        $has = PlaceBadgeAward::where('user_id', (int) $request->query('UserID'))
            ->where('badge_id', (int) $request->query('BadgeID'))
            ->exists();

        return response($has ? 'Success' : 'Failure');
    }

    public function isDisabled(Request $request)
    {
        $badge = $this->badge((int) $request->query('BadgeID'));
        return response($badge ? '0' : '1');
    }

    public function isLegal(Request $request)
    {
        $badge = $this->badge((int) $request->query('BadgeID'));
        return response($this->belongsToPlace($badge, (int) $request->query('PlaceID')) ? '1' : '0');
    }

    public function award(Request $request)
    {
        if (!RobloxUtilities::IsFinobeCloudAuthorized()) {
            return response('0', 403);
        }

        $userId = (int) $request->query('UserID');
        $placeId = (int) $request->query('PlaceID');
        $badge = $this->badge((int) $request->query('BadgeID'));
        $user = User::find($userId);

        if (!$user || !$this->belongsToPlace($badge, $placeId)) {
            return response('0');
        }

        $award = PlaceBadgeAward::firstOrCreate(['badge_id' => $badge->id, 'user_id' => $userId]);

        if (!$award->wasRecentlyCreated) {
            return response('0');
        }

        $creator = User::where('id', $badge->author)->value('username') ?? 'someone';

        return response("{$user->username} won {$creator}'s \"{$badge->title}\" award!");
    }
}
