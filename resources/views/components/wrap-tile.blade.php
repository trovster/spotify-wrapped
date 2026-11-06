<article {{ $attributes->class(['wrap-entry']) }}>
    <a class="wrap-tile" data-wrap-color="{{ $wrap->accent }}" href="{{ route('wraps.show', $wrap) }}">
        <span class="wrap-tile__top">{{ $periodLabel }}</span>
        <span class="wrap-tile__title">{{ $headline }}</span>
        <span class="wrap-tile__date">{{ $dateRange }}</span>
    </a>
    <button type="button" class="wrap-tile__delete-toggle" data-wrap-delete-open aria-haspopup="dialog" aria-controls="wrap-delete-{{ $wrap->getKey() }}" aria-label="Delete Wrapped story for {{ $periodLabel }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6m4-6v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
    </button>
    <dialog id="wrap-delete-{{ $wrap->getKey() }}" class="wrap-tile__delete-dialog" aria-labelledby="wrap-delete-title-{{ $wrap->getKey() }}">
        <p id="wrap-delete-title-{{ $wrap->getKey() }}">Do you want to delete this Wrapped story?<br />This cannot be undone.</p>
        <div class="wrap-tile__delete-actions">
            <button type="button" class="wrap-tile__delete-cancel" data-wrap-delete-cancel autofocus>Cancel</button>
            <form action="{{ route('wraps.destroy', $wrap) }}" method="post">
                @csrf
                @method('DELETE')
                <input type="hidden" name="return_to" value="{{ $returnTo }}">
                <button type="submit" class="wrap-tile__delete-submit">Confirm</button>
            </form>
        </div>
    </dialog>
</article>
