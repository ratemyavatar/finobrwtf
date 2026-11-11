@extends('admin.layout')
@section('title', 'Review Asset')
@section('content')
<p><a href="/admin/assets">&larr; Asset Approval</a></p>
<h3 class="font-weight-bold mb-3">Review Asset #{{ $asset->id }}</h3>
@if(session('admin_error'))<div class="alert alert-danger" role="alert">{{ session('admin_error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
<div class="card">
    <div class="card-header"><strong>{{ $asset->title ?: 'Untitled asset' }}</strong></div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                @include('admin.asset-preview', ['asset' => $asset])
            </div>
            <div class="col-md-6">
                <dl class="row">
                    <dt class="col-sm-4">Asset ID</dt><dd class="col-sm-8">{{ $asset->id }}</dd>
                    <dt class="col-sm-4">Type</dt><dd class="col-sm-8">{{ $asset->type_name }} (#{{ $asset->asset_type }})</dd>
                    <dt class="col-sm-4">Uploader</dt><dd class="col-sm-8">{{ $asset->uploader }} ({{ $asset->author }})</dd>
                    <dt class="col-sm-4">Submitted</dt><dd class="col-sm-8">{{ $asset->created ?? 'Unknown' }}</dd>
                    <dt class="col-sm-4">Status</dt><dd class="col-sm-8">{{ $asset->visibility === 'r' ? 'Pending review' : 'Already reviewed' }}</dd>
                    <dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $asset->description ?: 'None' }}</dd>
                </dl>
                @if($asset->visibility === 'r')
                    @include('admin.asset-actions', ['asset' => $asset, 'detailed' => true])
                @else
                    <p class="text-muted">This asset is no longer pending. No action can be taken from this page.</p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
