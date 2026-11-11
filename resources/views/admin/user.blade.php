@extends('admin.layout')
@section('title','Manage '.$subject->username)
@section('content')
<div class="admin-user">
<p class="mb-2"><a href="/admin/users"><i class="fas fa-chevron-left fa-fw mr-1" aria-hidden="true"></i>Manage users</a></p>
<h3 class="font-weight-bold mb-3">Manage user</h3>
@if(session('admin_success'))<div class="alert alert-success" role="status">{{ session('admin_success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="row">

<div class="col-lg-9 admin-user-main">
<div class="card admin-account-summary mb-4">
    <div class="card-body">
        <div class="admin-summary-top">
            <img class="admin-summary-avatar" src="{{ \App\Support\AvatarImage::url($subject->pfp) }}" alt="{{ $subject->username }}'s avatar">
            <div class="admin-summary-person">
                <h4 class="admin-summary-name">{{ $subject->username }}</h4>
                <div class="admin-summary-identity">
                    <span class="badge {{ $accountState==='Active' ? 'badge-success' : 'badge-danger' }}">{{ $accountState }}</span>
                    <span>{{ ucfirst($subject->status) }}</span>
                    <span class="admin-summary-separator" aria-hidden="true">·</span>
                    <span>User #{{ $subject->id }}</span>
                </div>
                <a class="admin-summary-public-link" href="/user/{{ $subject->id }}"><i class="fas fa-external-link-alt fa-fw mr-1" aria-hidden="true"></i>View public profile</a>
            </div>
            @if(isset($subject->Dius))
                <div class="admin-summary-balance">
                    <span class="admin-summary-label">Dius balance</span>
                    <div class="admin-summary-amount"><img src="/s/img/diu_32.png" width="32" height="32" alt="" class="mr-1">{{ number_format($subject->Dius) }}</div>
                </div>
            @endif
        </div>
        <div class="admin-summary-facts">
            <div><span><i class="far fa-calendar-alt mr-1" aria-hidden="true"></i>Joined</span><strong>{{ $subject->created ?: 'Unknown' }}</strong></div>
            <div><span><i class="far fa-clock mr-1" aria-hidden="true"></i>Last login</span><strong>{{ $subject->lastlogin ?: 'Never' }}</strong></div>
        </div>
    </div>
</div>
@php
    $titleIcons = ['overview'=>'tachometer-alt','account'=>'user-cog','moderation'=>'gavel','notes'=>'sticky-note','restrictions'=>'lock','economy'=>'exchange-alt','avatar'=>'user-circle','messages'=>'envelope','permissions'=>'user-shield','changes'=>'history'];
@endphp
<h4 class="mb-3"><i class="fas fa-fw fa-{{ $titleIcons[$section] }}" aria-hidden="true"></i> {{ $sectionTitle }}</h4>
@if($section==='overview')
@if($activeBan)<div class="card mb-3"><div class="card-header">Current ban</div><div class="card-body"><p>{{ $activeBan->reason }}</p><p class="mb-0">By {{ $activeBan->moderator }} · {{ $activeBan->date }}<br>Expires: {{ in_array($activeBan->perm,[true,1,'1','y'],true) ? 'Permanent' : $activeBan->expire }}</p></div></div>@endif
@if($activeWarning)<div class="card mb-3"><div class="card-header">Current warning</div><div class="card-body"><p>{{ $activeWarning->reason }}</p><p class="mb-0">By {{ $activeWarning->moderator }} · {{ $activeWarning->date }} · Awaiting acknowledgement</p></div></div>@endif
@if($restrictions)
<div class="card mb-3"><div class="card-header"><i class="fas fa-lock mr-2" aria-hidden="true"></i>Active restrictions</div>
    <ul class="list-group list-group-flush">@foreach($restrictions as $feature)<li class="list-group-item">{{ ucfirst(str_replace('_',' ',$feature)) }}</li>@endforeach</ul>
</div>
@endif
<div class="card mb-3 admin-overview-card">
    <div class="card-header font-weight-bold"><i class="fas fa-tools mr-2" aria-hidden="true"></i>Quick actions</div>
    <div class="card-body">
        @php
            $quickActions = [
                'moderation'=>['Warn or ban','Review or change moderation status'],
                'account'=>['Edit account','Change account information'],
                'notes'=>['Moderator notes','Add or review staff notes'],
                'economy'=>['Transactions','Review Dius and purchase history'],
            ];
            $visibleQuickActions = array_filter($quickActions, fn($_action, $key) => isset($sections[$key]), ARRAY_FILTER_USE_BOTH);
        @endphp
        <div class="admin-overview-actions">
            @forelse($visibleQuickActions as $key=>$action)
                <a class="admin-overview-action" href="/admin/users/{{ $subject->id }}/{{ $key }}">
                    <span class="admin-overview-action-icon"><i class="fas fa-fw fa-{{ $titleIcons[$key] }}" aria-hidden="true"></i></span>
                    <span class="admin-overview-action-copy"><strong>{{ $action[0] }}</strong><small>{{ $action[1] }}</small></span>
                    <i class="fas fa-chevron-right admin-overview-chevron" aria-hidden="true"></i>
                </a>
            @empty
                <p class="text-muted mb-0">No additional actions are available for your staff permissions.</p>
            @endforelse
        </div>
    </div>
</div>
@if(trim((string)($subject->blurb ?? '')) !== '')
<div class="card mb-3"><div class="card-header"><i class="far fa-address-card mr-2" aria-hidden="true"></i>Profile blurb</div><div class="card-body user-text">{{ $subject->blurb }}</div></div>
@endif
@else
@if($section==='account')
<div class="card card-body mb-3"><dl class="row mb-0"><dt class="col-sm-3">Username</dt><dd class="col-sm-9">{{ $subject->username }}</dd><dt class="col-sm-3">Email</dt><dd class="col-sm-9">{{ $subject->email }}</dd><dt class="col-sm-3">Email verified</dt><dd class="col-sm-9">{{ in_array($subject->verified,[true,1,'1','y'],true) ? 'Yes' : 'No' }}</dd><dt class="col-sm-3">Place slots</dt><dd class="col-sm-9">{{ $subject->slots }}</dd><dt class="col-sm-3">Daily <img src="/s/img/diu_16.png" width="16" height="16" alt="" class="align-middle"> Dius</dt><dd class="col-sm-9">{{ in_array($subject->diubanned,[true,1,'1','y'],true) ? 'Paused' : 'Enabled' }}</dd></dl></div>
@endif
@if($section==='avatar')
@php
    $avatar=json_decode($subject->avatar ?? '[]',true);
@endphp
@php
    $equipped=is_array($avatar[0]['equippedGearVersionIds'] ?? null) ? array_filter($avatar[0]['equippedGearVersionIds'],fn($id)=>is_numeric($id) && $id>0) : [];
@endphp
<div class="card mb-3"><div class="card-header">Equipped items</div><div class="card-body">@forelse($equipped as $asset)<a class="mr-3" href="/item/{{ (int)$asset }}">Asset #{{ (int)$asset }}</a>@empty No equipped items. @endforelse</div></div>
@endif
@include('admin.user-action-form')
@endif
@if($section==='moderation')
@foreach(['bans'=>['Bans',$bans],'warnings'=>['Warnings',$warnings]] as $kind=>$history)
<h5>{{ $history[0] }}</h5><div class="card table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>Date</th><th>Moderator</th><th>Reason</th>@if($kind==='bans')<th>Expires</th>@endif<th>State</th></tr></thead><tbody>
@forelse($history[1] as $record)
@php
    $undone=in_array($record->undone,[true,1,'1','y'],true);
@endphp
@php
    $acknowledged=in_array($record->reactivated,[true,1,'1','y'],true);
@endphp
<tr><td>{{ $record->date }}</td><td>{{ $record->moderator }}</td><td class="user-text">{{ $record->reason }}</td>@if($kind==='bans')<td>{{ in_array($record->perm,[true,1,'1','y'],true) ? 'Permanent' : $record->expire }}</td>@endif<td>{{ $undone ? 'Overturned' : ($acknowledged ? 'Acknowledged' : ($kind==='bans' && !in_array($record->perm,[true,1,'1','y'],true) && $record->expire && \Carbon\Carbon::parse($record->expire)->isPast() ? 'Expired' : 'Active')) }}</td></tr>
@empty<tr><td colspan="{{ $kind==='bans' ? 5 : 4 }}">No {{ strtolower($history[0]) }}.</td></tr>@endforelse
</tbody></table></div>@include('admin.pagination',['records'=>$history[1],'label'=>$history[0].' pages'])
@endforeach
@endif
@if($section==='notes')
@forelse($records as $note)<article class="card mb-3"><div class="card-header">{{ $note->moderator ?? 'Former staff' }} · {{ $note->created_at }}</div><div class="card-body user-text">{{ $note->body }}</div></article>@empty<p>No moderator notes.</p>@endforelse
@include('admin.pagination')
@endif
@if($section==='economy')
<div class="card table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>Date</th><th>Transaction</th><th>Asset</th><th>Amount</th><th>Type</th></tr></thead><tbody>@forelse($records as $record)<tr><td>{{ $record->date }}</td><td>#{{ $record->id }}</td><td>@if($record->assetid)<a href="/item/{{ $record->assetid }}">{{ $record->assetid }}</a>@else—@endif</td><td class="text-nowrap"><img src="/s/img/diu_16.png" width="16" height="16" alt="Dius" class="align-middle mr-1">{{ number_format($record->amount) }}</td><td>{{ ['1'=>'Purchase','2'=>'Sale','3'=>'Purchase','4'=>'Award','5'=>'Purchase','admin'=>'Admin adjustment'][$record->type] ?? 'Other' }}</td></tr>@empty<tr><td colspan="5">No transactions.</td></tr>@endforelse</tbody></table></div>
@include('admin.pagination')
@endif
@if($section==='changes')
<div class="card table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>Date</th><th>Moderator</th><th>Action</th><th>Reason</th></tr></thead><tbody>@forelse($records as $record)<tr><td>{{ $record->created_at }}</td><td>{{ $record->actor_name }}</td><td>{{ $record->label }}</td><td class="user-text">{{ $record->reason }}</td></tr>@empty<tr><td colspan="4">No admin changes.</td></tr>@endforelse</tbody></table></div>
@include('admin.pagination')
@endif
@if($section==='permissions')<div class="card"><div class="card-header">Current permissions</div><div class="card-body">@if($subject->status==='admin')<p>Full administrator. Delegated permissions do not change this role.</p>@endif @forelse($permissions as $permission)<span class="badge badge-secondary mr-1">{{ $permission }}</span>@empty No delegated permissions. @endforelse</div></div>@endif
@if($section==='restrictions')<div class="card"><div class="card-header">Active restrictions</div><ul class="list-group list-group-flush">@forelse($restrictions as $feature)<li class="list-group-item">{{ ucfirst(str_replace('_',' ',$feature)) }}</li>@empty<li class="list-group-item">No active restrictions.</li>@endforelse</ul></div>@endif
</div>
<aside class="col-lg-3 mb-4 admin-user-side">
<div class="card admin-user-menu mb-3">
    <div class="card-header font-weight-bold"><i class="fas fa-sliders-h fa-fw mr-2" aria-hidden="true"></i>User management</div>
    @php
        $navigationIcons = [
            'overview'=>'tachometer-alt','account'=>'user-cog','moderation'=>'gavel',
            'notes'=>'sticky-note','restrictions'=>'lock','economy'=>'exchange-alt',
            'avatar'=>'user-circle','messages'=>'envelope','permissions'=>'user-shield','changes'=>'history'
        ];
        $navigationGroups = [
            'ACCOUNT'=>['overview','account','avatar','economy'],
            'MODERATION'=>['moderation','notes','restrictions','messages'],
            'ADMINISTRATION'=>['permissions','changes']
        ];
    @endphp
    <nav class="admin-user-navigation" aria-label="User management sections">
        @foreach($navigationGroups as $heading=>$items)
            @if(count(array_intersect($items,array_keys($sections))))
            <div class="admin-nav-heading">{{ $heading }}</div>
            @foreach($items as $key)
                @if(isset($sections[$key]))
                <a class="admin-nav-link {{ $section===$key ? 'is-active' : '' }}"
                    @if($section===$key) aria-current="page" @endif
                    href="/admin/users/{{ $subject->id }}{{ $key==='overview' ? '' : '/'.$key }}">
                    <i class="fas fa-fw fa-{{ $navigationIcons[$key] }}" aria-hidden="true"></i>
                    <span>{{ $sections[$key][0] }}</span>
                    @if($section===$key)<i class="fas fa-chevron-right admin-nav-current" aria-hidden="true"></i>@endif
                </a>
                @endif
            @endforeach
            @endif
        @endforeach
    </nav>
</div>

</aside>
</div></div>
@endsection
@section('scripts')
<script id="user-actions-data" type="application/json">{!! json_encode($actions, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<script src="/s/admin/user-actions.js" defer></script>
@endsection
