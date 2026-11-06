@extends('layout')

@section('title', 'Configure · Spotify Wrapped')

@section('content')
<main class="container configure-page">
    <header class="configure-header">
        <p class="eyebrow"><span class="eyebrow__line"></span> Make it yours</p>
        <h1>Configure your listening.</h1>
        <p>Connect Spotify for recent plays, then import your history for the full picture. Your listening data stays in this app on your Mac.</p>
    </header>

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

    <section class="panel configure-summary" aria-labelledby="configure-summary-title">
        <div class="section-heading"><p class="eyebrow">Your setup</p><h2 id="configure-summary-title">Current status</h2></div>
        <dl class="data-metrics">
            <div><dt>Spotify</dt><dd class="data-metrics__small">
                @if($connected)
                    <span class="configure-profile">
                        @if($profileImageUrl)
                            <img class="configure-profile__avatar" src="{{ $profileImageUrl }}" alt="">
                        @else
                            <span class="configure-profile__avatar configure-profile__avatar--fallback" aria-hidden="true">{{ mb_substr($profileName, 0, 1) }}</span>
                        @endif
                        <strong>Connected as {{ $profileName }}</strong>
                    </span>
                @else
                    {{ $configured ? 'Ready to connect' : 'Add your app keys' }}
                @endif
            </dd></div>
            <div><dt>Plays</dt><dd>{{ number_format($playCount) }}</dd></div>
            <div><dt>History</dt><dd class="data-metrics__small">@if($firstPlayedAt && $lastPlayedAt){{ $firstPlayedAt->timezone(config('app.display_timezone'))->format('M Y') }} — {{ $lastPlayedAt->timezone(config('app.display_timezone'))->format('M Y') }}@else Waiting for data @endif</dd></div>
            <div><dt>Last sync</dt><dd class="data-metrics__small">{{ $lastSyncedAt ? $lastSyncedAt->diffForHumans() : 'Not yet synced' }}</dd></div>
        </dl>
    </section>

    <ol class="setup-list">
        <li class="panel setup-step">
            <span class="setup-step__number" aria-hidden="true">01</span>
            <div class="setup-step__body">
                <div class="setup-step__heading"><h2>Get the app ready</h2><span class="setup-step__status setup-step__status--ready">Open and running</span></div>
                <p>Install the app in your local PHP environment, run <code>composer run setup</code>, and open its secure <code>.test</code> address. The setup command creates your private key and database. Keep the original <code>.env</code> and database when updating.</p>
                <p>If you are starting from scratch, the <a href="{{ route('guide') }}">full setup guide</a> covers PHP 8.5, local HTTPS, and the install commands.</p>
            </div>
        </li>

        <li class="panel setup-step">
            <span class="setup-step__number" aria-hidden="true">02</span>
            <div class="setup-step__body">
                <div class="setup-step__heading"><h2>Create a Spotify developer app</h2><span class="setup-step__status {{ $configured ? 'setup-step__status--ready' : 'setup-step__status--pending' }}">{{ $configured ? 'Keys added' : 'Needs your keys' }}</span></div>
                <p>Sign in to the <a href="https://developer.spotify.com/dashboard" target="_blank" rel="noopener noreferrer">Spotify Developer Dashboard</a> with a Premium account and create a Web API app. Add this redirect URI to the app settings exactly:</p>
                <code class="setup-step__code">{{ $redirectUri ?: route('spotify.callback') }}</code>
                <p>Copy the Client ID and Client Secret into your local <code>.env</code> as <code>SPOTIFY_CLIENT_ID</code> and <code>SPOTIFY_CLIENT_SECRET</code>. Set <code>SPOTIFY_REDIRECT_URI</code> to the same URI. Save the file, then run <code>php artisan config:clear</code> if the keys do not appear here. If Spotify asks for registered users, add your own account under User Management.</p>
                <a class="text-link" href="{{ route('guide') }}">See the detailed setup guide <span aria-hidden="true">→</span></a>
            </div>
        </li>

        <li class="panel setup-step">
            <span class="setup-step__number" aria-hidden="true">03</span>
            <div class="setup-step__body">
                <div class="setup-step__heading"><h2>Connect your Spotify account</h2><span class="setup-step__status {{ $connected ? 'setup-step__status--ready' : 'setup-step__status--pending' }}">{{ $connected ? 'Connected' : 'Not connected' }}</span></div>
                <p>Authorise the app on Spotify’s sign-in page. It requests read access to your recent plays and playlists; you never enter your Spotify password here.</p>
                @if($connected)
                    <p class="setup-step__detail">Connected as <strong>{{ $profileName }}</strong>.</p>
                    <div class="setup-step__actions">
                        <a class="button button--outline" href="{{ route('spotify.connect') }}">Reconnect Spotify <span aria-hidden="true">↗</span></a>
                    </div>
                @elseif($configured)
                    <div class="setup-step__actions">
                        <a class="button button--ember" href="{{ route('spotify.connect') }}">Connect Spotify <span aria-hidden="true">↗</span></a>
                    </div>
                @else
                    <p class="setup-step__detail">Add your app keys in step 2 to enable connection.</p>
                @endif
            </div>
        </li>

        <li class="panel setup-step">
            <span class="setup-step__number" aria-hidden="true">04</span>
            <div class="setup-step__body">
                <div class="setup-step__heading"><h2>Bring in your listening history</h2><span class="setup-step__status {{ $playCount > 0 ? 'setup-step__status--ready' : 'setup-step__status--pending' }}">{{ $playCount > 0 ? number_format($playCount).' plays' : 'Waiting for files' }}</span></div>
                <p>At <a href="https://www.spotify.com/account/privacy/" target="_blank" rel="noopener noreferrer">Spotify Privacy</a>, request your Account data and Extended streaming history. The extended export may take up to 30 days; you can import Account data first and add the rest later.</p>
                <p>Unzip the download and copy its <code>Streaming_History_Audio_*.json</code> or <code>StreamingHistory_music_*.json</code> files into:</p>
                <code class="setup-step__code">{{ $importPath }}</code>
                <p>The importer searches subfolders and skips duplicate plays, so you can import again when more files arrive. For a large export, run <code>php artisan wrapped:import</code> in Terminal to avoid a browser timeout.</p>
                <div class="setup-step__actions">
                    <form action="{{ route('import') }}" method="post">@csrf<button type="submit" class="button button--ember">Import files <span aria-hidden="true">↗</span></button></form>
                </div>
            </div>
        </li>

        <li class="panel setup-step">
            <span class="setup-step__number" aria-hidden="true">05</span>
            <div class="setup-step__body">
                <div class="setup-step__heading"><h2>Keep recent plays up to date</h2><span class="setup-step__status {{ $lastSyncedAt ? 'setup-step__status--ready' : 'setup-step__status--pending' }}">{{ $lastSyncedAt ? 'Last synced '.$lastSyncedAt->diffForHumans() : 'Not yet synced' }}</span></div>
                <p>Spotify returns only your latest 50 plays, so sync regularly. Opening this page checks for new plays when the previous sync is over 15 minutes old. You can also sync now, or leave <code>php artisan schedule:work</code> running while your Mac is awake for hourly syncs and weekly Wraps.</p>
                @if($connected)
                    <div class="setup-step__actions"><form action="{{ route('spotify.sync') }}" method="post">@csrf<button type="submit" class="button button--outline">Sync now <span aria-hidden="true">↗</span></button></form></div>
                @else
                    <p class="setup-step__detail">Connect Spotify in step 3 to enable syncing.</p>
                @endif
            </div>
        </li>
    </ol>

    <div class="configure-finish">
        <p>Ready to see what your music says?</p>
        <a href="{{ route('home') }}" class="button button--ember">Make a Wrapped <span aria-hidden="true">→</span></a>
    </div>
</main>
@endsection
