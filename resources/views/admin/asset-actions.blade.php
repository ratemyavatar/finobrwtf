<div class="d-flex justify-content-center flex-wrap mt-3" style="gap:8px">
    <form method="post" action="/admin/assets/{{ $asset->id }}/approve">
        @csrf
        <input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
        <button type="submit" class="btn btn-success"><i class="fas fa-check mr-1" aria-hidden="true"></i> Approve</button>
    </form>
    <form method="post" action="/admin/assets/{{ $asset->id }}/deny" onsubmit="return confirm('Deny asset #{{ $asset->id }}?');">
        @csrf
        <input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::uuid() }}">
        @if(!empty($detailed))
            <label class="sr-only" for="denial-reason-{{ $asset->id }}">Reason for denial</label>
            <input class="form-control mb-2" name="reason" id="denial-reason-{{ $asset->id }}" placeholder="Denial reason (optional)" maxlength="500">
        @endif
        <button type="submit" class="btn btn-danger"><i class="fas fa-times mr-1" aria-hidden="true"></i> Deny</button>
    </form>
</div>
