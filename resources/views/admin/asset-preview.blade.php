<div class="text-center mb-3">
    @if($asset->preview_image)
        <img src="{{ $asset->preview_image }}" class="img-fluid border rounded"
            style="max-height:240px;object-fit:contain" loading="lazy"
            alt="Preview of {{ $asset->title }}">
    @elseif($asset->preview_audio)
        <audio controls preload="none" style="max-width:100%;width:100%">
            <source src="{{ $asset->preview_audio }}">
            Your browser does not support audio previews.
        </audio>
    @else
        <div class="border rounded p-4 text-muted">
            <i class="far fa-fw fa-file fa-3x mb-2" aria-hidden="true"></i>
            <p class="mb-0">Preview not available for this file type.</p>
        </div>
    @endif
    @if($asset->texture_id)
        <small class="d-block mt-2">Associated texture: <a href="/asset/?id={{ $asset->texture_id }}" target="_blank" rel="noopener">#{{ $asset->texture_id }}</a></small>
    @endif
</div>
