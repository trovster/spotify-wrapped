<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    private function play(string $artist, string $track, string $at, int $milliseconds = 180000, ?string $album = null): void
    {
        DB::table('plays')->insert([
            'artist_name' => $artist,
            'track_name' => $track,
            'album_name' => $album,
            'track_uri' => null,
            'played_at' => $at,
            'ms_played' => $milliseconds,
            'source' => 'export',
        ]);
    }

    public function test_period_lists_and_totals_use_the_same_window(): void
    {
        $this->play('Clutch', 'The Regulator', '2026-11-03 10:00:00', album: 'Blast Tyrant');
        $this->play('Clutch', 'Electric Worry', '2026-11-04 10:00:00', album: 'From Beale Street to Oblivion');
        $this->play('Danzig', 'Mother', '2026-09-01 10:00:00', album: 'Danzig');

        $stats = app(Stats::class);
        $from = CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC');
        $to = CarbonImmutable::parse('2026-11-05 00:00:00', 'UTC');

        $this->assertSame(2, $stats->totals($from, $to)['plays']);
        $this->assertSame(6, $stats->totals($from, $to)['minutes']);
        $this->assertSame(['Clutch'], $stats->artists($from, $to)->keys()->all());
        $this->assertSame(['Electric Worry', 'The Regulator'], $stats->tracks($from, $to, 10)->pluck('track_name')->all());
        $this->assertCount(2, $stats->albums($from, $to, 10));
    }

    public function test_clock_converts_utc_buckets_to_local_hours_and_weekdays(): void
    {
        $this->play('A', 'Summer', '2026-07-01 22:30:00');
        $this->play('A', 'Winter', '2026-01-10 22:30:00');

        $clock = app(Stats::class)->clock(null, CarbonImmutable::parse('2026-11-05 00:00:00', 'UTC'));

        $this->assertSame(1, $clock['hours'][23]);
        $this->assertSame(1, $clock['hours'][22]);
        $this->assertSame(1, $clock['days'][2]);
        $this->assertSame(1, $clock['days'][5]);
    }

    public function test_familiarity_lengths_and_unknown_decades(): void
    {
        $this->play('Old', 'Long', '2025-01-01 10:00:00', 500000);
        $this->play('Old', 'Long', '2026-11-01 10:00:00', 200000);
        $this->play('New', 'Short', '2026-11-02 10:00:00', 120000);

        $stats = app(Stats::class);
        $to = CarbonImmutable::parse('2026-11-05 00:00:00', 'UTC');
        $from = $to->subWeek();
        $tracks = $stats->tracks($from, $to, 10);

        $this->assertSame(['new' => 1, 'familiar' => 1], $stats->familiar($from, $to, $from));
        $this->assertSame(1, $stats->lengths($tracks)['≤3m']);
        $this->assertSame(1, $stats->lengths($tracks)['3–4m']);
        $this->assertSame(2, $stats->decades($tracks)['unknown']);
    }

    public function test_dashboard_period_filter_and_unknown_tab(): void
    {
        CarbonImmutable::setTestNow('2026-11-05 12:00:00 UTC');
        try {
            $this->play('Clutch', 'Recent', '2026-11-04 10:00:00');
            $this->play('Danzig', 'Older', '2026-09-01 10:00:00');

            $this->get('/stats?period=week')->assertOk()->assertSee('Recent')->assertDontSee('Older');
            $this->get('/stats?period=year')->assertOk()->assertSee('Older');
            $this->get('/stats?period=invalid')->assertOk()->assertSee('Older');
            $this->get('/stats/not-a-tab')->assertNotFound();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_dashboard_caps_spotify_detail_lookups_at_twenty(): void
    {
        CarbonImmutable::setTestNow('2026-11-05 12:00:00 UTC');
        try {
            foreach (range(1, 25) as $number) {
                DB::table('plays')->insert([
                    'artist_name' => 'Artist '.$number,
                    'track_name' => 'Track '.$number,
                    'album_name' => null,
                    'track_uri' => 'spotify:track:track'.$number,
                    'played_at' => '2026-11-01 10:00:00',
                    'ms_played' => 180000,
                    'source' => 'export',
                ]);
            }

            Setting::write('spotify_access_token', 'access');
            Setting::write('spotify_refresh_token', 'refresh');
            Setting::write('spotify_expires_at', '2026-11-06T12:00:00+00:00');

            Http::fake(function ($request) {
                if (str_contains($request->url(), '/tracks/')) {
                    return Http::response([
                        'uri' => 'spotify:track:'.basename($request->url()),
                        'artists' => [['id' => 'id', 'name' => 'Artist']],
                        'album' => ['images' => []],
                        'duration_ms' => 180000,
                    ]);
                }

                return Http::response(['images' => [], 'genres' => []]);
            });

            $this->get('/stats/artists')->assertOk();
            Http::assertSentCount(20);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_playlists_are_available_without_imported_plays_and_use_spotify_pagination(): void
    {
        Setting::write('spotify_access_token', 'access');
        Setting::write('spotify_refresh_token', 'refresh');
        Setting::write('spotify_expires_at', now()->addHour()->toIso8601String());
        Setting::write('spotify_scopes', 'user-read-recently-played playlist-read-private playlist-read-collaborative');

        Http::fake([
            'api.spotify.com/v1/me/playlists*' => Http::sequence()
                ->push(['total' => 51, 'items' => [[
                    'name' => 'First mix',
                    'images' => [['url' => 'https://example.com/playlist.jpg']],
                    'owner' => ['display_name' => 'Taylor'],
                    'items' => ['total' => 42],
                    'external_urls' => ['spotify' => 'https://open.spotify.com/playlist/first'],
                ]]], 200)
                ->push(['total' => 51, 'items' => [[
                    'name' => 'Second mix',
                    'images' => [],
                    'owner' => ['id' => 'Taylor'],
                    'items' => ['total' => 1],
                    'external_urls' => ['spotify' => 'https://open.spotify.com/playlist/second'],
                ]]], 200),
        ]);

        $this->get('/stats/playlists')->assertOk()->assertSee('First mix')->assertSee('42 items')->assertSee('Page 1 of 2')->assertSee('page=2');
        $this->get('/stats/playlists?page=2')->assertOk()->assertSee('Second mix')->assertSee('1 item')->assertSee('Page 2 of 2');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/me/playlists?limit=50&offset=0'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/me/playlists?limit=50&offset=50'));
        Http::assertSentCount(2);
    }

    public function test_existing_connection_without_playlist_scopes_asks_for_reconnect(): void
    {
        Setting::write('spotify_refresh_token', 'refresh');
        Setting::write('spotify_scopes', 'user-read-recently-played');
        Http::fake();

        $this->get('/stats/playlists')->assertOk()->assertSee('Reconnect to show your playlists')->assertSee(route('spotify.connect'));
        Http::assertNothingSent();
    }

    public function test_playlist_api_failure_does_not_break_stats(): void
    {
        Setting::write('spotify_access_token', 'access');
        Setting::write('spotify_refresh_token', 'refresh');
        Setting::write('spotify_expires_at', now()->addHour()->toIso8601String());
        Setting::write('spotify_scopes', 'user-read-recently-played playlist-read-private playlist-read-collaborative');
        Http::fake(['api.spotify.com/v1/me/playlists*' => Http::response(['error' => ['message' => 'Too many requests']], 429, ['Retry-After' => '12'])]);

        $this->get('/stats/playlists')->assertOk()->assertSee('We could not load your playlists')->assertSee('12 seconds');
    }
}
