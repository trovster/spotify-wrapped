@extends('layout')

@section('title', 'Your listening stats · Spotify Wrapped')

@section('content')
@php($tabs = ['overview' => 'Overview', 'artists' => 'Artists', 'songs' => 'Songs', 'albums' => 'Albums', 'genres' => 'Genres', 'insights' => 'Insights', 'recent' => 'Recent plays', 'playlists' => 'Playlists'])
<main class="stats-page">
    <div class="stats-profile-wrap">
        <div class="container stats-profile">
            @if(!empty($profile['image_url']))<img class="stats-profile__avatar" src="{{ $profile['image_url'] }}" alt="">@else<span class="stats-profile__avatar stats-profile__avatar--fallback" aria-hidden="true">{{ mb_substr($profile['name'] ?? 'Y', 0, 1) }}</span>@endif
            <div class="stats-profile__name"><p class="eyebrow">Your listening profile</p><h1>{{ $profile['name'] ?? 'Your stats' }}</h1>@if(!empty($profile['id']))<span>{{ '@'.$profile['id'] }}</span>@endif</div>
            @if(!empty($profile['id']))<a class="stats-profile__spotify" href="https://open.spotify.com/user/{{ rawurlencode($profile['id']) }}" target="_blank" rel="noopener noreferrer"><x-spotify-mark /> Open in Spotify <span aria-hidden="true">↗</span></a>@endif
        </div>
        <div class="container"><nav class="stats-tabs" aria-label="Stats">@foreach($tabs as $tabKey => $tabLabel)<a href="/stats/{{ $tabKey === 'overview' ? '' : $tabKey }}?period={{ $period->value }}" @if($tab === $tabKey) aria-current="page" @endif>{{ $tabLabel }}</a>@endforeach</nav></div>
    </div>

    <div class="container stats-content">
        <div class="stats-intro"><div><p class="eyebrow">Explore the patterns</p><h2>{{ $tabs[$tab] ?? 'Stats' }}</h2></div></div>
        @if($tab !== 'playlists')
            <nav class="period-links" aria-label="Time period">@foreach($periods as $choice)<a href="/stats/{{ $tab === 'overview' ? '' : $tab }}?period={{ $choice->value }}" @if($period === $choice) aria-current="page" @endif>{{ match($choice->value) { 'week' => '1 week', 'month' => '1 month', 'half-year' => '6 months', 'year' => '1 year', default => 'All time' } }}</a>@endforeach</nav>
        @endif

        @if($playCount === 0 && $tab !== 'playlists')
            <div class="stats-empty"><span class="stats-empty__disc" aria-hidden="true"></span><h2>Your stats start with a play.</h2><p>Connect Spotify or import your listening history to see your charts.</p><a class="button button--ember" href="{{ route('configure') }}">Go to getting started <span aria-hidden="true">↗</span></a></div>
        @else
            @if($tab !== 'playlists')
            <dl class="stats-strip">
                <div><dt>Minutes</dt><dd>{{ number_format($totals['minutes'] ?? 0) }}</dd></div>
                <div><dt>Plays</dt><dd>{{ number_format($totals['plays'] ?? 0) }}</dd></div>
                <div><dt>Artists</dt><dd>{{ number_format($totals['artists'] ?? 0) }}</dd></div>
                <div><dt>Songs</dt><dd>{{ number_format($totals['songs'] ?? 0) }}</dd></div>
            </dl>
            @endif

            @switch($tab)
                @case('overview')
                    <div class="stats-grid">
                        @include('stats.list', ['id' => 'artists', 'title' => 'Top artists', 'rows' => $artists ?? [], 'seeAll' => true])
                        @include('stats.list', ['id' => 'songs', 'title' => 'Top songs', 'rows' => $songs ?? [], 'seeAll' => true])
                        @include('stats.list', ['id' => 'albums', 'title' => 'Top albums', 'rows' => $albums ?? [], 'seeAll' => true])
                        @include('stats.list', ['id' => 'genres', 'title' => 'Top genres', 'rows' => $genres ?? [], 'seeAll' => true])
                    </div>
                    @break
                @case('artists')
                @case('songs')
                @case('albums')
                @case('genres')
                    <div class="stats-single">@include('stats.list', ['id' => $tab, 'title' => 'Top '. $tab, 'rows' => ${$tab} ?? [], 'seeAll' => false])</div>
                    @break
                @case('insights')
                    <div class="insights-grid">
                        <figure class="stats-panel insight-panel insight-panel--wide">
                            <figcaption><p class="eyebrow">Your rhythm</p><h2>When you listen</h2><span>Plays by local hour</span></figcaption>
                            @if(empty($hours))<p class="list-empty">Not enough plays in this period.</p>@else
                                <ol class="hour-chart">@foreach($hours as $point)<li><span class="sr-only">{{ $point['label'] }}, {{ $point['count'] }} plays</span><span class="hour-chart__bar" style="--v: {{ $point['v'] }}" aria-hidden="true"></span><span class="hour-chart__label" aria-hidden="true">{{ $loop->index % 6 === 0 ? $point['label'] : '' }}</span></li>@endforeach</ol>
                            @endif
                        </figure>
                        <figure class="stats-panel insight-panel">
                            <figcaption><p class="eyebrow">Your routine</p><h2>Days of the week</h2></figcaption>
                            @if(empty($days))<p class="list-empty">Not enough plays in this period.</p>@else<ol class="bar-chart">@foreach($days as $point)<li><span>{{ $point['label'] }}</span><span class="bar-chart__track"><span style="--v: {{ $point['v'] }}" aria-hidden="true"></span></span><strong>{{ number_format($point['count']) }}</strong></li>@endforeach</ol>@endif
                        </figure>
                        <figure class="stats-panel insight-panel">
                            <figcaption><p class="eyebrow">Your circle</p><h2>New vs familiar</h2></figcaption>
                            @if(empty($familiar))<p class="list-empty">Not enough plays in this period.</p>@else<ol class="bar-chart">@foreach($familiar as $point)<li><span>{{ $point['label'] }}</span><span class="bar-chart__track"><span style="--v: {{ $point['v'] }}" aria-hidden="true"></span></span><strong>{{ number_format($point['count']) }}</strong></li>@endforeach</ol>@endif
                        </figure>
                        <figure class="stats-panel insight-panel">
                            <figcaption><p class="eyebrow">The eras</p><h2>By decade</h2></figcaption>
                            @if(empty($decades))<p class="list-empty">{{ $connected ? 'Release years are filling in as Spotify provides them.' : 'Connect Spotify to see decades.' }}</p>@else<ol class="bar-chart">@foreach($decades as $point)<li><span>{{ $point['label'] }}</span><span class="bar-chart__track"><span style="--v: {{ $point['v'] }}" aria-hidden="true"></span></span><strong>{{ number_format($point['count']) }}</strong></li>@endforeach</ol>@endif
                            <p class="chart-note">Based on your top 50 songs; {{ number_format($unknownYears ?? 0) }} without a release year.</p>
                        </figure>
                        <figure class="stats-panel insight-panel">
                            <figcaption><p class="eyebrow">The duration</p><h2>By length</h2></figcaption>
                            @if(empty($lengths))<p class="list-empty">Not enough plays in this period.</p>@else<ol class="bar-chart">@foreach($lengths as $point)<li><span>{{ $point['label'] }}</span><span class="bar-chart__track"><span style="--v: {{ $point['v'] }}" aria-hidden="true"></span></span><strong>{{ number_format($point['count']) }}</strong></li>@endforeach</ol>@endif
                            <p class="chart-note">Based on your top 50 songs and their longest recorded play.</p>
                        </figure>
                    </div>
                    @break
                @case('recent')
                    <section class="stats-panel recent-panel"><div class="stats-panel__heading"><div><p class="eyebrow">The latest</p><h2>Recent plays</h2></div><span>Last 50</span></div>
                        @if(empty($recent))<p class="list-empty">No recent plays yet.</p>@else<ol class="ranked-list">@foreach($recent as $play)<li><span class="ranked-list__rank">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><span class="ranked-list__image ranked-list__placeholder" data-theme="violet" aria-hidden="true">♫</span><span class="ranked-list__copy"><strong>{{ $play['track'] }}</strong><small>{{ $play['artist'] }}</small></span><span class="ranked-list__time">{{ $play['played_at']->diffForHumans() }}</span></li>@endforeach</ol>@endif
                    </section>
                    @break
                @case('playlists')
                    @include('stats.playlists')
                    @break
            @endswitch
        @endif
    </div>
</main>
<script>
    const statsTabs = document.querySelector('.stats-tabs');
    const activeStatsTab = statsTabs?.querySelector('[aria-current="page"]');

    if (activeStatsTab && statsTabs.scrollWidth > statsTabs.clientWidth) {
        const tabsBounds = statsTabs.getBoundingClientRect();
        const activeBounds = activeStatsTab.getBoundingClientRect();

        statsTabs.scrollLeft += activeBounds.left - tabsBounds.left - (tabsBounds.width - activeBounds.width) / 2;
    }
</script>
@endsection
