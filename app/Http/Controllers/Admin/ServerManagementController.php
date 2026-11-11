<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Access;
use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ServerManagementController extends Controller
{
    private function authorizeStaff(Request $request): void
    {
        Access::authorize($request->user());

        abort_unless($request->user()->status === 'admin', 403, 'Administrator access required.');
    }

    private function navbar(Request $request): string
    {
        return app(\App\View\PageData::class)->adminNavbar();
    }

    private function audit(Request $request, string $action, string $label, ?string $id, string $reason, ?array $before = null): void
    {
        if (!Schema::connection('finobe')->hasTable('admin_audit')) return;

        DB::connection('finobe')->table('admin_audit')->insert([
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->username,
            'action' => $action,
            'label' => $label,
            'target_type' => 'servers',
            'target_id' => $id,
            'reason' => $reason,
            'before' => $before === null ? null : json_encode($before),
            'after' => null,
            'request_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeStaff($request);
        $request->validate(['page' => 'nullable|integer|min:1|max:10000']);
        $servers = Server::orderByDesc('id')->paginate(25);
        $all = DB::connection('finobe')->table('servers')->get(['placeid','players']);
        $players = 0;
        foreach ($all as $row) {
            $list = json_decode((string) $row->players, true);
            if (is_array($list)) $players += count($list);
        }

        return view('admin.servers', [
            'actor' => $request->user(),
            'navbarHtml' => $this->navbar($request),
            'servers' => $servers,
            'serverCount' => $all->count(),
            'playerCount' => $players,
            'placeCount' => $all->pluck('placeid')->unique()->count(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeStaff($request);
        $input = $request->validate([
            'ip' => ['required','string','max:255','regex:/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/'],
            'port' => 'required|integer|min:1|max:65535',
            'placeid' => 'required|integer|min:1',
            'jobId' => 'required|string|max:255',
            'status' => ['required','string','max:40','regex:/^[a-zA-Z0-9_.-]+$/'],
        ]);
        if (Server::where('jobId', $input['jobId'])->exists()) {
            return back()->withInput()->withErrors(['jobId' => 'A server with this Job ID already exists.']);
        }

        DB::connection('finobe')->transaction(function () use ($request, $input) {
            $server = Server::create([
                'ip' => $input['ip'],
                'port' => $input['port'],
                'placeid' => $input['placeid'],
                'jobId' => $input['jobId'],
                'status' => $input['status'],
                'players' => [],
            ]);
            $this->audit($request, 'operations-server-create', 'Create server', (string) $server->id, 'Server registered by administrator');
        });

        return redirect('/admin/servers')->with('admin_success', 'Server added successfully.');
    }

    public function remove(Request $request, string $id)
    {
        $this->authorizeStaff($request);
        abort_unless(ctype_digit($id), 404);
        DB::connection('finobe')->transaction(function () use ($request, $id) {
            $server = Server::findOrFail($id);
            $before = ['jobId' => $server->jobId, 'placeid' => $server->placeid, 'status' => $server->status];
            $server->delete();
            $this->audit($request, 'operations-server-delete', 'Delete server', $id, 'Server removed by administrator', $before);
        });
        return redirect('/admin/servers')->with('admin_success', 'Server removed.');
    }

    public function clear(Request $request)
    {
        $this->authorizeStaff($request);
        $request->validate(['confirm' => 'required|in:DELETE ALL']);
        $count = DB::connection('finobe')->transaction(function () use ($request) {
            $deleted = Server::query()->delete();
            $this->audit($request, 'operations-servers-clear', 'Delete all servers', null, 'Removed ' . $deleted . ' server records');
            return $deleted;
        });
        return redirect('/admin/servers')->with('admin_success', $count . ' server records removed.');
    }
}
