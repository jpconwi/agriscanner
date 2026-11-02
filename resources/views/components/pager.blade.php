@props(['paginator'])
@if ($paginator->hasPages())
    <nav class="mt-4 flex items-center justify-between text-sm text-ink-soft" aria-label="Pagination">
        <span>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }} · {{ $paginator->total() }} total</span>
        <div class="flex gap-2">
            @if ($paginator->onFirstPage())
                <span class="btn btn-secondary btn-sm opacity-50" aria-disabled="true">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="btn btn-secondary btn-sm" rel="prev">Previous</a>
            @endif
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="btn btn-secondary btn-sm" rel="next">Next</a>
            @else
                <span class="btn btn-secondary btn-sm opacity-50" aria-disabled="true">Next</span>
            @endif
        </div>
    </nav>
@endif
