<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AvatarRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

class RenderAvatar
{
    use Dispatchable, Queueable;

    public function __construct(public int $userId)
    {
    }

    public function handle(): void
    {
        $dirtyKey = "avatar_render_dirty_{$this->userId}";
        Cache::put($dirtyKey, true, 300);

        $lock = Cache::lock("avatar_render_{$this->userId}", 120);

        if (!$lock->get()) {
            return;
        }

        try {
            $passes = 0;

            while (Cache::pull($dirtyKey) && $passes++ < 3) {
                $user = User::find($this->userId);

                if (!$user) {
                    return;
                }

                AvatarRenderer::render($user);
            }
        } catch (\Throwable $e) {
            report($e);
        } finally {
            $lock->release();
        }
    }
}
