@extends('layout')

@section('title', 'Your Wrapped · Spotify Wrapped')

@section('content')
<main class="container home-page">
    <section class="hero" aria-labelledby="home-title">
        <div class="hero__copy">
            <p class="eyebrow"><span class="eyebrow__line"></span> Your listening, in motion</p>
            <h1 id="home-title">Your music. <span>Every chapter.</span></h1>
            <p class="hero__lead">A fresh perspective on what you played, who you found, and what changed. Your story updates as you listen.</p>
        </div>
    </section>

    @if(session('status'))
        <div class="notice notice--success" role="status">{{ session('status') }}</div>
    @endif
    @if(session('warning'))
        <div class="notice notice--warning" role="alert">{{ session('warning') }}</div>
    @endif
    @if($errors->any())
        <div class="notice notice--warning" role="alert">
            <strong>Please check this:</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="home-grid">
        <section class="panel new-wrap" aria-labelledby="new-wrap-title">
            <div class="section-heading"><p class="eyebrow">A new story</p><h2 id="new-wrap-title">Create a Wrapped</h2></div>
            <p>See the biggest change, your newest discovery, and the sound of the moment.</p>
            <form action="{{ route('wraps.store') }}" method="post" class="wrap-form">
                @csrf
                <fieldset>
                    <legend>Choose a period</legend>
                    <x-period-picker :selected="old('period', 'week')" />
                </fieldset>
                <button class="button button--ember" type="submit" @disabled($playCount === 0)>Generate Wrapped <span aria-hidden="true">↗</span></button>
            </form>
        </section>

        <section class="panel data-panel" aria-labelledby="data-title">
            @if($playCount > 0)
                <div class="section-heading">
                    <p class="eyebrow">Listening history</p>
                    <div class="data-panel__identity">
                        <h2 id="data-title">Your data</h2>
                        <div class="home-profile">
                            @if($profileImageUrl)
                                <img class="home-profile__avatar" src="{{ $profileImageUrl }}" alt="">
                            @else
                                <span class="home-profile__avatar home-profile__avatar--fallback" aria-hidden="true">{{ mb_substr($profileName, 0, 1) }}</span>
                            @endif
                            <strong class="home-profile__name">{{ $profileName }}</strong>
                        </div>
                    </div>
                </div>
                <dl class="data-metrics">
                    <div><dt>Plays</dt><dd>{{ number_format($playCount) }}</dd></div>
                    <div><dt>History</dt><dd class="data-metrics__small">@if($firstPlayedAt && $lastPlayedAt){{ $firstPlayedAt->timezone(config('app.display_timezone'))->format('M Y') }} — {{ $lastPlayedAt->timezone(config('app.display_timezone'))->format('M Y') }}@else Waiting for data @endif</dd></div>
                    <div><dt>Last sync</dt><dd class="data-metrics__small">{{ $lastSyncedAt ? $lastSyncedAt->diffForHumans() : 'Not yet synced' }}</dd></div>
                </dl>
                <p><a href="{{ route('configure') }}" class="text-link">Manage your data <span aria-hidden="true">→</span></a></p>
                <p><a href="{{ route('stats') }}" class="text-link">See your stats <span aria-hidden="true">→</span></a></p>
            @else
                <div class="section-heading"><p class="eyebrow">Get connected</p><h2 id="data-title">Bring your music in</h2></div>
                <p>Connect Spotify or import your listening history to unlock your stories and stats.</p>
                <a href="{{ route('configure') }}" class="button button--outline">Open Configure <span aria-hidden="true">↗</span></a>
            @endif
        </section>
    </div>

    @if($recentWraps->isNotEmpty())
        <section class="wrap-history home-recent-wraps" aria-labelledby="home-wraps-title">
            <div class="section-heading section-heading--row">
                <div><p class="eyebrow">Your archive</p><h2 id="home-wraps-title">Recent Wraps</h2></div>
                <a href="{{ route('wraps.index') }}" class="text-link">View all Wraps <span aria-hidden="true">→</span></a>
            </div>
            <div class="wrap-tiles">
                @foreach($recentWraps as $wrap)
                    <x-wrap-tile :wrap="$wrap" return-to="home" />
                @endforeach
            </div>
        </section>
    @else
        <section class="home-archive-link" aria-label="Your Wraps">
            <span>Your listening stories, all in one place.</span>
            <a href="{{ route('wraps.index') }}" class="text-link">View your Wraps <span aria-hidden="true">→</span></a>
        </section>
    @endif
</main>
@endsection
