@extends('admin.layout')
@section('title','Manage servers')
@section('content')
<div class="admin-servers">
<p><a href="/admin"><i class="fas fa-chevron-left fa-fw mr-1" aria-hidden="true"></i>Admin Panel</a></p>
<div class="d-flex align-items-center justify-content-between flex-wrap mb-3">
    <h3 class="font-weight-bold mb-0"><i class="fas fa-server mr-2" aria-hidden="true"></i>Manage Servers</h3>
</div>
@if(session('admin_success'))<div class="alert alert-success" role="status"><i class="fas fa-check-circle mr-2" aria-hidden="true"></i>{{ session('admin_success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i>{{ $errors->first() }}</div>@endif

<div class="row mb-3">
    <div class="col-md-4 mb-3"><div class="card"><div class="card-body server-stat"><i class="fas fa-server text-primary" aria-hidden="true"></i><div><strong>{{ number_format($serverCount) }}</strong><span>Registered servers</span></div></div></div></div>
    <div class="col-md-4 mb-3"><div class="card"><div class="card-body server-stat"><i class="fas fa-users text-success" aria-hidden="true"></i><div><strong>{{ number_format($playerCount) }}</strong><span>Players in server records</span></div></div></div></div>
    <div class="col-md-4 mb-3"><div class="card"><div class="card-body server-stat"><i class="fas fa-gamepad text-warning" aria-hidden="true"></i><div><strong>{{ number_format($placeCount) }}</strong><span>Distinct places</span></div></div></div></div>
</div>

<div class="card mb-4">
    <div class="card-header font-weight-bold"><i class="fas fa-plus-circle mr-2" aria-hidden="true"></i>Add a server</div>
    <div class="card-body">
        <form method="post" action="/admin/servers">
            @csrf
            <div class="form-row">
                <div class="form-group col-md-5">
                    <label for="server-ip">Server IP or hostname</label>
                    <input class="form-control" type="text" name="ip" id="server-ip" maxlength="255" value="{{ old('ip') }}" placeholder="127.0.0.1" required>
                </div>
                <div class="form-group col-md-2">
                    <label for="server-port">Port</label>
                    <input class="form-control" type="number" name="port" id="server-port" min="1" max="65535" value="{{ old('port') }}" placeholder="53640" required>
                </div>
                <div class="form-group col-md-2">
                    <label for="server-placeid">Place ID</label>
                    <input class="form-control" type="number" name="placeid" id="server-placeid" min="1" value="{{ old('placeid') }}" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="server-status">Status code</label>
                    <input class="form-control" type="text" name="status" id="server-status" maxlength="40" value="{{ old('status','1') }}" placeholder="1" required>
                </div>
            </div>
            <div class="form-group">
                <label for="server-jobid">Job ID</label>
                <input class="form-control" name="jobId" id="server-jobid" maxlength="255" value="{{ old('jobId') }}" placeholder="Server's unique job identifier" required>
            </div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-plus mr-2" aria-hidden="true"></i>Add Server</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header font-weight-bold"><i class="fas fa-list mr-2" aria-hidden="true"></i>Server list</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover server-table mb-0">
                <thead><tr><th>ID</th><th>Address</th><th>Place</th><th>Job ID</th><th>Players</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($servers as $server)
                    <tr>
                        <td>#{{ $server->id }}</td>
                        <td><code>{{ $server->ip }}:{{ $server->port }}</code></td>
                        <td><a href="/place/{{ $server->placeid }}">#{{ $server->placeid }}</a></td>
                        <td><code>{{ $server->jobId }}</code></td>
                        <td>{{ is_array($server->players) ? count($server->players) : 0 }}</td>
                        <td><span class="badge badge-secondary">{{ $server->status }}</span></td>
                        <td>
                            <form method="post" action="/admin/servers/{{ $server->id }}" onsubmit="return confirm('Remove server #{{ $server->id }}?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete server {{ $server->id }}"><i class="fas fa-trash-alt mr-1" aria-hidden="true"></i>Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4"><i class="far fa-folder-open mr-1" aria-hidden="true"></i>No registered servers.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@include('admin.pagination',['records'=>$servers,'label'=>'Server pages'])

<div class="card server-danger mb-4">
    <div class="card-header text-danger font-weight-bold"><i class="fas fa-exclamation-triangle mr-2" aria-hidden="true"></i>Danger zone</div>
    <div class="card-body">
        <h5>Delete all server records</h5>
        <p class="text-muted">Removes every server entry from the database. This cannot be undone.</p>
        <form method="post" action="/admin/servers/clear" onsubmit="return confirm('Permanently delete ALL server records?');">
            @csrf
            <div class="form-group">
                <label for="clear-servers-confirm">Type <strong>DELETE ALL</strong> to confirm</label>
                <input id="clear-servers-confirm" class="form-control" name="confirm" autocomplete="off" maxlength="10" required style="max-width:300px">
            </div>
            <button class="btn btn-danger" type="submit"><i class="fas fa-trash mr-2" aria-hidden="true"></i>Delete All Servers</button>
        </form>
    </div>
</div>
</div>
@endsection
