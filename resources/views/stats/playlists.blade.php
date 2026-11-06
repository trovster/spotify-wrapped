@if(! $connected)
    <section class="stats-playlists-state stats-panel">
        <p class="eyebrow">Your library</p>
        <h2>Connect Spotify to see your playlists.</h2>
        <p>Follow the setup steps, then come back to browse the playlists you own and follow.</p>
        <a class="button button--ember" href="{{ route('configure') }}">Configure Spotify <span aria-hidden="true">↗</span></a>
    </section>
@elseif($playlistNeedsReconnect)
    <section class="stats-playlists-state stats-panel">
        <p class="eyebrow">One more step</p>
        <h2>Reconnect to show your playlists.</h2>
        <p>Your current Spotify connection does not include playlist access. Reconnect once to grant read-only access to private and collaborative playlists.</p>
        <a class="button button--green" href="{{ route('spotify.connect') }}">Reconnect Spotify <span aria-hidden="true">↗</span></a>
    </section>
@elseif($playlistError)
    <section class="stats-playlists-state stats-panel" role="alert">
        <p class="eyebrow">Spotify is unavailable</p>
        <h2>We could not load your playlists.</h2>
        <p>{{ $playlistError }}</p>
        <a class="button button--ember" href="{{ route('stats', ['tab' => 'playlists', 'page' => $playlistPage]) }}">Try again <span aria-hidden="true">↗</span></a>
    </section>
@elseif(empty($playlists))
    <section class="stats-playlists-state stats-panel">
        <p class="eyebrow">Your library</p>
        <h2>{{ $playlistPage > 1 ? 'No playlists on this page.' : 'No playlists to show yet.' }}</h2>
        <p>{{ $playlistPage > 1 ? 'Head back to the previous page.' : 'Create or follow a playlist on Spotify and it will appear here.' }}</p>
        @if($playlistPage > 1)<a class="button button--ember" href="{{ route('stats', ['tab' => 'playlists', 'page' => $playlistPage - 1]) }}">Previous page</a>@endif
    </section>
@else
    <div class="playlist-grid">
        @foreach($playlists as $playlist)
            <article class="playlist-card">
                @if($playlist['image'])
                    <img class="playlist-card__art" src="{{ $playlist['image'] }}" alt="" loading="lazy">
                @else
                    <span class="playlist-card__art playlist-card__art--fallback" aria-hidden="true">♫</span>
                @endif
                <div class="playlist-card__body">
                    <h2>{{ $playlist['name'] }}</h2>
                    <p class="playlist-card__meta">By {{ $playlist['owner'] }} · {{ number_format($playlist['items']) }} {{ $playlist['items'] === 1 ? 'item' : 'items' }}</p>
                    @if($playlist['url'])<a class="playlist-card__link" href="{{ $playlist['url'] }}" target="_blank" rel="noopener noreferrer">Open in Spotify <span aria-hidden="true">↗</span></a>@endif
                </div>
            </article>
        @endforeach
    </div>

    @if($playlistTotal > 50)
        <nav class="playlist-pagination" aria-label="Playlist pages">
            @if($playlistPage > 1)<a href="{{ route('stats', ['tab' => 'playlists', 'page' => $playlistPage - 1]) }}">← Previous</a>@endif
            <span>Page {{ number_format($playlistPage) }} of {{ number_format((int) ceil($playlistTotal / 50)) }}</span>
            @if($playlistPage * 50 < $playlistTotal && $playlistPage < 2000)<a href="{{ route('stats', ['tab' => 'playlists', 'page' => $playlistPage + 1]) }}">Next →</a>@endif
        </nav>
    @endif
@endif
