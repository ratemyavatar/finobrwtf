<?php

namespace App\Support;

final class Paths
{
    public static function placeEdit(int $placeId, string $tab = ''): string
    {
        return '/publish/place-edit?placeid=' . $placeId . '&universeid=-1' . ($tab !== '' ? '#' . $tab : '');
    }
}
