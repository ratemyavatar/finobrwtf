<?php

namespace App\View;

use App\Models\Announcement;
use App\Models\Asset;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Services\Trading;
use App\Support\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

final class PageData
{
    private ?array $data = null;

    public function __construct(private Request $request)
    {
    }

    public function all(): array
    {
        return $this->data ??= $this->build();
    }

    public function with(array $extra, ?string $title = null): array
    {
        $data = array_merge($this->all(), $extra);

        if ($title !== null) {
            $data['embeds']['title'] = $title . ' - Finobe';
        }

        return $data;
    }

    public function adminNavbar(): string
    {
        $data = $this->all();
        $data['admin_panel'] = true;
        $data['user']['branding'] = 'finobe';
        $data['notifications']['ads'] = false;

        return view('v2/Modules/nav', ['data' => $data])->render();
    }

    private function build(): array
    {
        $user = $this->request->user();
        $userCount = Cache::remember('user_count', 3600, fn () => User::count());

        $data = [
            'embeds' => [
                'title' => 'Finobe',
                'description' => 'Finobe, it is website. He is for old brick-builder. Good for use.',
                'url' => config('app.url'),
                'image' => config('app.url') . '/s/img/' . $this->logoFile($user),
            ],
            'csrf_token' => csrf_token(),
            'siteusername' => $user !== null,
            'user' => ['version' => 'v2'],
            'page' => strtok($this->request->getRequestUri(), '?'),
            'dir' => '/' . explode('/', trim($this->request->path(), '/'))[0] . '/',
            'alerts' => $this->alerts(),
            'lucky_number' => random_int(0, max(0, $userCount)) . '/' . $userCount,
            'social' => config('finobe.social'),
            'server_name' => config('finobe.server_name'),
        ];

        if ($user !== null) {
            $data['user'] = $this->viewer($user);
            $data['notifications'] = $this->notifications($user);
        }

        return $data;
    }

    private function viewer(User $user): array
    {
        $viewer = $user->makeVisible(['email', 'token'])->toArray();
        $user->makeHidden(['email', 'token']);
        $viewer['version'] = 'v2';
        $viewer['formattedDius'] = Format::number($user->Dius);
        $viewer['places'] = Asset::where('author', $user->id)->where('asset_type', 9)->count();

        return $viewer;
    }

    private function notifications(User $user): array
    {
        $items = Notification::where('touser', $user->id)->orderByDesc('date')->limit(50)->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'message' => $n->message,
                'date' => date('M j Y g:i:s A', strtotime($n->date)),
            ])->all();

        $incomingFriends = collect($user->friends ?? [])->where('status', 'pending')->count();

        return [
            'data' => $items,
            'ads' => (bool) config('finobe.ads'),
            'info' => [
                'number' => Notification::where('touser', $user->id)->where('readed', false)->count(),
                'inbox' => Message::where('touser', $user->id)->where('readed', false)->where('archived', false)->count(),
                'trades' => Trading::pendingIncoming((int) $user->id),
                'incomingFriends' => $incomingFriends,
            ],
        ];
    }

    private function alerts(): array
    {
        $tooBig = $this->request->query('upload') === 'too_big'
            ? "That file's too big (the server takes up to " . ini_get('post_max_size') . ' per upload).'
            : false;

        return [
            'successv2' => Session::pull('successv2', false),
            'success' => Session::pull('success', false),
            'error' => Session::pull('error', false) ?: $tooBig,
            'announcements' => Announcement::select('message', 'expire', 'author', 'color')
                ->where('expire', '>', now())->orderByDesc('id')->get()->toArray(),
        ];
    }

    private function logoFile(?User $user): string
    {
        return match ($user?->logo) {
            'v1' => 'BUSY.png',
            'v2' => 'finnobe3.png',
            default => 'finnobe3logo.png',
        };
    }
}
