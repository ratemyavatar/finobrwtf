@extends('admin.layout')
@section('title','Manage users')
@section('content')
<p><a href="/admin">← Admin Panel</a></p>
<h3 class="font-weight-bold">Manage users</h3>
<form method="get" action="/admin/users" class="form-inline my-4">
<label for="user-search" class="mr-2">Username or user ID</label>
<input id="user-search" name="q" class="form-control mr-2" value="{{ $query }}" maxlength="80">
<label for="user-sort" class="sr-only">Sort by</label><select id="user-sort" name="sort" class="form-control mr-2">@foreach(['id'=>'User ID','username'=>'Username','created'=>'Joined','lastlogin'=>'Last login'] as $key=>$label)<option value="{{ $key }}" @selected($sort===$key)>{{ $label }}</option>@endforeach</select>
<label for="user-direction" class="sr-only">Sort direction</label><select id="user-direction" name="direction" class="form-control mr-2"><option value="desc" @selected($direction==='desc')>Descending</option><option value="asc" @selected($direction==='asc')>Ascending</option></select>
<label for="user-state" class="sr-only">Account state</label><select id="user-state" name="state" class="form-control mr-2">@foreach(['all'=>'All accounts','active'=>'Active accounts','banned'=>'Banned accounts'] as $key=>$label)<option value="{{ $key }}" @selected($state===$key)>{{ $label }}</option>@endforeach</select>
<button class="btn btn-primary" type="submit">Search</button> <a href="/admin/users" class="ml-2">Reset</a>
</form>
<div class="card table-responsive"><table class="table table-striped mb-0"><thead><tr><th>ID</th><th>Username</th><th>Role</th><th>Account state</th><th>Joined</th><th>Last login</th><th></th></tr></thead><tbody>
@forelse($users as $user)
<tr><td>{{ $user->id }}</td><td><a href="/admin/users/{{ $user->id }}">{{ $user->username }}</a></td><td>{{ $user->status }}</td><td>{{ $user->is_banned ? 'Banned' : 'Active' }}</td><td>{{ $user->created }}</td><td>{{ $user->lastlogin }}</td><td><a href="/admin/users/{{ $user->id }}">Manage</a></td></tr>
@empty<tr><td colspan="7">No users found.</td></tr>@endforelse
</tbody></table></div>
<nav aria-label="User pages" class="my-4 d-flex justify-content-between">
@if($users->previousPageUrl())<a href="{{ $users->previousPageUrl() }}">← Previous</a>@else<span></span>@endif
<span>Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>
@if($users->nextPageUrl())<a href="{{ $users->nextPageUrl() }}">Next →</a>@else<span></span>@endif
</nav>
@endsection
