@if($records->hasPages())
<nav aria-label="{{ $label ?? 'History pages' }}" class="d-flex justify-content-between my-3">
@if($records->previousPageUrl())<a href="{{ $records->previousPageUrl() }}">← Previous</a>@else<span></span>@endif
<span>Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</span>
@if($records->nextPageUrl())<a href="{{ $records->nextPageUrl() }}">Next →</a>@else<span></span>@endif
</nav>@endif
