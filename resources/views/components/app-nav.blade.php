<a class="brand" href="{{ route('home') }}" aria-label="Spotify Wrapped home">
    <span class="brand__mark" aria-hidden="true">◉</span><span class="brand__word">Listen<span class="brand__dot">.</span></span>
</a>
<nav class="main-nav" aria-label="Main navigation">
    <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Wrapped</a>
    <a href="{{ route('stats') }}" @if(request()->routeIs('stats')) aria-current="page" @endif>Stats</a>
    <a href="{{ route('wraps.index') }}" @if(request()->routeIs('wraps.index', 'wraps.show')) aria-current="page" @endif>Wraps</a>
    <a href="{{ route('configure') }}" @if(request()->routeIs('configure')) aria-current="page" @endif>Configure</a>
    @if($connected)
        <a class="spotify-nav-link" href="{{ $spotifyUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Open Spotify in a new tab"><x-spotify-mark /> <span>Spotify</span><span aria-hidden="true">↗</span></a>
    @endif
</nav>
