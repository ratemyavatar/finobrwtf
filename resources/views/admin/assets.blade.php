@extends('admin.layout')
@section('title', 'Asset Approval')
@section('content')
<p><a href="/admin">&larr; Admin Panel</a></p>
<h3 class="font-weight-bold">Asset Approval</h3>
<p class="text-muted">Review pending uploads before they appear on Finobe. Select an asset to see more details.</p>
@if(session('admin_success'))<div class="alert alert-success" role="status">{{ session('admin_success') }}</div>@endif
@if(session('admin_error'))<div class="alert alert-danger" role="alert">{{ session('admin_error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
<div class="catalog_container mt-4">
    @if($assets->isEmpty())
        <div class="card"><div class="card-body text-center text-muted">There are no pending assets to review.</div></div>
    @else
        <div class="row">
        @foreach($assets as $asset)
            <div class="col-md-6 col-lg-4 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h5 class="card-title text-center"><a href="/admin/assets/{{ $asset->id }}">{{ $asset->title ?: 'Untitled asset' }}</a></h5>
                        @include('admin.asset-preview', ['asset' => $asset])
                        <p class="mb-1"><strong>Type:</strong> {{ $asset->type_name }} (#{{ $asset->asset_type }})</p>
                        <p class="mb-1"><strong>Uploaded by:</strong> {{ $asset->uploader }}</p>
                        <p class="mb-1"><strong>Asset ID:</strong> {{ $asset->id }}</p>
                        <p class="mb-2"><strong>Created:</strong> {{ $asset->created ?? 'Unknown' }}</p>
                        <p class="text-center mb-2"><a href="/admin/assets/{{ $asset->id }}">View asset details</a></p>
                        @include('admin.asset-actions', ['asset' => $asset, 'detailed' => false])
                    </div>
                </div>
            </div>
        @endforeach
        </div>
        <div class="mt-3">{{ $assets->links() }}</div>
    @endif
</div>
@endsection
