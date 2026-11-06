@extends('layout')

@section('title', 'Your Wraps · Spotify Wrapped')

@section('content')
<main class="container wraps-page">
    <header class="wraps-page__header">
        <div>
            <p class="eyebrow"><span class="eyebrow__line"></span> Your archive</p>
            <h1>Your Wraps</h1>
            <p>Every chapter of your listening, ready to play again.</p>
        </div>
        <a class="button button--outline" href="{{ route('home') }}#new-wrap-title">Create a Wrapped <span aria-hidden="true">↗</span></a>
    </header>

    @if(session('status'))
        <div class="notice notice--success" role="status">{{ session('status') }}</div>
    @endif

    <section class="wrap-history" aria-labelledby="history-title">
        <div class="section-heading section-heading--row">
            <h2 id="history-title">Saved stories</h2>
            <span class="section-count">{{ $wraps->count() }} {{ $wraps->count() === 1 ? 'story' : 'stories' }}</span>
        </div>
        @if($wraps->isEmpty())
            <div class="empty-wraps">
                <span class="empty-wraps__orbit" aria-hidden="true"></span>
                <p>No stories yet.</p>
                <span>Choose a period on the home page to make your first Wrapped.</span>
            </div>
        @else
            <div class="wrap-tiles">
                @foreach($wraps as $wrap)
                    <x-wrap-tile :wrap="$wrap" />
                @endforeach
            </div>
        @endif
    </section>
</main>
@endsection
