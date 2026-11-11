<?php

namespace App\Http\Middleware;

use App\Admin\Workbench;
use App\Models\User;
use App\Services\AvatarRenderer;
use App\View\PageData;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class Moderation
{
    private const SEEN_EVERY_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->ipBanned($request)) {
            return $this->deny($request, 'IpBanned', 'IP Banned', [], 403);
        }

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $this->markSeen($request, $user);

        if ($this->exempt($request)) {
            return $next($request);
        }

        if ($warning = $this->activeWarning($user)) {
            return $this->deny($request, 'Moderation', 'Moderation', [
                'isCurrentlyBanned' => true,
                'moderationType' => 1,
                'ban_info' => $this->legacyFlags($warning),
            ]);
        }

        if ($ban = $this->activeBan($user)) {
            return $this->deny($request, 'Moderation', 'Moderation', $this->banData($ban));
        }

        if ($this->needsVerification($request, $user)) {
            return $this->deny($request, 'Verify', 'Verify Email');
        }

        return $next($request);
    }

    public static function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, 'ip');
    }

    public static function bannedIps(): array
    {
        return Cache::remember('ip_bans', 60, function () {
            try {
                return DB::connection('finobe')->table('ip_bans')->pluck('ip')->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    private function ipBanned(Request $request): bool
    {
        $ip = (string) $request->ip();

        if (in_array($ip, self::bannedIps(), true)) {
            return true;
        }

        return Cache::remember('ip_ban:' . self::ipHash($ip), 60, fn () => DB::connection('finobe')->table('bans')
            ->where('username', self::ipHash($ip))
            ->where('undone', Workbench::flag('bans', 'undone', false))
            ->exists());
    }

    private function exempt(Request $request): bool
    {
        if ($request->is('logout', 'moderation/acknowledge')) {
            return true;
        }

        return $request->isMethod('post') && $request->path() === '/';
    }

    private function activeWarning(User $user): ?object
    {
        return DB::connection('finobe')->table('warning')
            ->where('username', $user->username)
            ->where('reactivated', Workbench::flag('warning', 'reactivated', false))
            ->where('undone', Workbench::flag('warning', 'undone', false))
            ->orderByDesc('id')
            ->first();
    }

    private function activeBan(User $user): ?object
    {
        return DB::connection('finobe')->table('bans')
            ->where('username', $user->username)
            ->where('reactivated', Workbench::flag('bans', 'reactivated', false))
            ->where('undone', Workbench::flag('bans', 'undone', false))
            ->orderByDesc('id')
            ->first();
    }

    private function banData(object $ban): array
    {
        $info = $this->legacyFlags($ban);
        $permanent = $info['perm'] === 'y';
        $expires = $ban->expire ? Carbon::parse($ban->expire) : null;
        $data = [
            'moderationType' => 2,
            'ban_info' => $info,
            'isCurrentlyBanned' => $permanent || ($expires !== null && $expires->isFuture()),
        ];

        if ($permanent || $expires === null) {
            return $data + ['expiration' => 'Permanent', 'expiration_formatted' => 'Never'];
        }

        $seconds = max(0, (int) now()->diffInSeconds($expires, false));
        $parts = array_filter([
            intdiv($seconds, 86400) ? intdiv($seconds, 86400) . ' day(s)' : '',
            intdiv($seconds % 86400, 3600) ? intdiv($seconds % 86400, 3600) . ' hour(s)' : '',
            intdiv($seconds % 3600, 60) ? intdiv($seconds % 3600, 60) . ' minute(s)' : '',
        ]);

        return $data + [
            'expiration' => $parts ? implode(' ', $parts) : 'Less than a minute',
            'expiration_formatted' => $expires->format('Y-m-d'),
        ];
    }

    private function legacyFlags(object $record): array
    {
        $info = (array) $record;

        foreach (['perm', 'reactivated', 'undone'] as $flag) {
            if (array_key_exists($flag, $info)) {
                $info[$flag] = in_array(strtolower((string) $info[$flag]), ['1', 'y', 'yes', 'true'], true) ? 'y' : 'n';
            }
        }

        return $info;
    }

    private function needsVerification(Request $request, User $user): bool
    {
        if (!$request->isMethodSafe() || $request->is('email/*', 'legal/welcome')) {
            return false;
        }

        return !in_array(strtolower((string) $user->verified), ['1', 'y', 'yes', 'true'], true);
    }

    private function markSeen(Request $request, User $user): void
    {
        if (!Cache::add('seen:' . $user->id, true, self::SEEN_EVERY_SECONDS)) {
            return;
        }

        $user->forceFill(['ip' => self::ipHash((string) $request->ip()), 'lastlogin' => now()])->saveQuietly();
        AvatarRenderer::renderIfMissing($user->id, $user->pfp);
    }

    private function deny(Request $request, string $view, string $title, array $extra = [], int $status = 200): Response
    {
        if ($request->getHost() !== parse_url(config('app.url'), PHP_URL_HOST) || $request->expectsJson()) {
            return response()->json(['code' => 403, 'message' => 'Access denied'], 403);
        }

        return response()->view('v2/' . $view, ['data' => app(PageData::class)->with($extra, $title)], $status);
    }
}
