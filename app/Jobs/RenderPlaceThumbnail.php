<?php

namespace App\Jobs;

use App\Services\PlaceThumbnail;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

class RenderPlaceThumbnail
{
    use Dispatchable, Queueable;

    public function __construct(public int $placeId)
    {
    }

    public function handle(): void
    {
        $lock = Cache::lock("place_thumbnail_render_{$this->placeId}", 150);

        if (!$lock->get()) {
            return;
        }

        try {
            PlaceThumbnail::render($this->placeId);
        } catch (\Throwable $e) {
            report($e);
        } finally {
            $lock->release();
        }
    }
}
