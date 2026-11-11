@if($records->hasPages())
<nav aria-label="{{ $label ?? 'History pages' }}" class="d-flex justify-content-between my-3">
@if($records->previousPageUrl())<a href="{{ $records->previousPageUrl() }}">← Previous</a>@else<span class="disabled text-muted" aria-disabled="true">← Previous</span>@endif
<span>Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</span>
@if($records->nextPageUrl())<a href="{{ $records->nextPageUrl() }}">Next →</a>@else<span class="disabled text-muted" aria-disabled="true">Next →</span>@endif
</nav>@endif
