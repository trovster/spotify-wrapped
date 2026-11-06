@extends('layout')

@section('title')
Your {{ ucfirst($wrap->period->value) }} Wrapped · Spotify Wrapped
@endsection
@section('bare', true)
@section('body-class', 'deck-page')
@push('head')
    <script>document.documentElement.classList.add('js')</script>
    <script src="/js/wrapped.js?v={{ filemtime(public_path('js/wrapped.js')) }}" defer></script>
    <script src="/js/story-export.js?v={{ filemtime(public_path('js/story-export.js')) }}" defer></script>
@endpush

@section('content')
@php($total = count($wrap->cards))
<main class="deck-shell">
    <section class="deck" aria-label="Your {{ $wrap->period->label() }} Wrapped" aria-roledescription="carousel" data-wrap-id="{{ $wrap->getKey() }}" data-date-range="{{ $wrap->starts_at->timezone(config('app.display_timezone'))->format('j M') }} — {{ $wrap->ends_at->timezone(config('app.display_timezone'))->format('j M Y') }}">
        <div class="deck-header">
            <div class="deck-progress" aria-hidden="true" data-progress>@foreach($wrap->cards as $card)<span @if($loop->first) class="is-active" @endif></span>@endforeach</div>
            <div class="deck-header__row">
                <a class="deck-close" href="{{ route('wraps.index') }}" data-close aria-label="Close Wrapped and return to your Wraps">×</a>
                <span class="deck-brand">Listen<span>.</span><span class="sr-only">{{ $wrap->period->label() }}, {{ $wrap->starts_at->format('j M Y') }} to {{ $wrap->ends_at->format('j M Y') }}</span></span>
                <div class="deck-header__actions">
                    <button class="deck-playback" type="button" data-playback data-js aria-label="Pause story" aria-pressed="true">Pause</button>
                    <span class="deck-count" data-counter>1 / {{ $total }}</span>
                </div>
            </div>
        </div>
        <div class="wrap-track" tabindex="0" data-track>
            @foreach($wrap->cards as $card)
                @php($stat = \App\Wrapped\CardNumberFormatter::stat($card['stat'] ?? null))
                <div id="card-{{ $loop->iteration }}" class="card" role="group" aria-roledescription="slide" aria-label="{{ $loop->iteration }} of {{ $total }}: {{ \App\Wrapped\CardNumberFormatter::text($card['headline']) }}" data-theme="{{ $card['theme'] }}" data-wrap-color="{{ $wrap->accent ?? 'violet' }}" data-type="{{ $card['type'] }}">
                    <div class="card__inner">
                        <p class="card__eyebrow">{{ match($card['type']) { 'biggest_change' => 'Biggest change', 'new_discovery' => 'New discovery', default => 'Your summary' } }} <span>·</span> {{ $wrap->period->label() }}</p>
                        <div @class(['card__visual', 'card__visual--image-stat' => !empty($card['image_url']) && $stat !== null && $stat !== ''])>
                            @if(!empty($card['image_url']))<img class="card__image" src="{{ $card['image_url'] }}" data-export-src="{{ route('wraps.cards.artwork', ['wrap' => $wrap, 'card' => $loop->index]) }}" alt="">@endif
                            @if($stat !== null && $stat !== '')<div class="card__stat" style="--len: {{ max(1, mb_strlen($stat)) }}"><strong>{{ $stat }}</strong>@if(!empty($card['stat_label'])) <span>{{ $card['stat_label'] }}</span>@endif</div>@endif
                        </div>
                        <div class="card__copy"><span class="card__index">0{{ $loop->iteration }} / 0{{ $total }}</span><h2>{{ \App\Wrapped\CardNumberFormatter::text($card['headline']) }}</h2><p>{{ \App\Wrapped\CardNumberFormatter::text($card['body']) }}</p></div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="deck-controls" data-js><button type="button" data-prev aria-label="Previous card" aria-disabled="true">←</button><button type="button" class="deck-download" data-story-download aria-label="Download current story as PNG"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 17v3h16v-3"/></svg><span data-download-label>Download</span></button><button type="button" data-next aria-label="Next card">→</button></div>
        <p class="sr-only" aria-live="polite" data-live>1 of {{ $total }}: {{ \App\Wrapped\CardNumberFormatter::text($wrap->cards[0]['headline'] ?? '') }}</p>
        <p class="sr-only" aria-live="polite" data-download-status></p>
    </section>
    <p class="deck-hint" data-js>Stories play automatically · Use ← → to move, or Esc to close</p>
</main>
@endsection
