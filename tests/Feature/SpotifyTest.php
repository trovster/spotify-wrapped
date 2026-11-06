<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Play;
use App\Models\Setting;
use App\Models\Track;
use App\Services\Spotify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SpotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.spotify', [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect' => 'https://spotify-wrapped.test/spotify/callback',
        ]);
    }

    public function test_connect_redirect_has_scope_redirect_uri_and_random_state(): void
    {
        $response = $this->get('/spotify/connect');
        $response->assertRedirect();

        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('test-client', $parameters['client_id']);
        $this->assertSame('user-read-recently-played playlist-read-private playlist-read-collaborative', $parameters['scope']);
        $this->assertSame(config('services.spotify.redirect'), $parameters['redirect_uri']);
        $this->assertSame(40, strlen($parameters['state']));
    }

    public function test_missing_keys_and_denied_permission_return_readable_warnings(): void
    {
        Http::fake();
        config()->set('services.spotify.client_id', null);
        $this->get('/spotify/connect')->assertRedirect(route('configure'))->assertSessionHas('warning');
        config()->set('services.spotify.client_id', 'test-client');

        $this->withSession(['spotify_oauth_state' => 'correct'])
            ->get('/spotify/callback?error=access_denied&state=correct')
            ->assertRedirect(route('configure'))
            ->assertSessionHas('warning');
        Http::assertNothingSent();
    }

    public function test_bad_oauth_state_never_exchanges_tokens(): void
    {
        Http::fake();
        $this->withSession(['spotify_oauth_state' => 'correct'])
            ->get('/spotify/callback?code=code&state=wrong')
            ->assertRedirect(route('configure'))
            ->assertSessionHas('warning');
        Http::assertNothingSent();
    }

    public function test_successful_callback_stores_encrypted_tokens_and_profile(): void
    {
        Http::fake([
            'accounts.spotify.com/api/token' => Http::response(['access_token' => 'secret-access', 'refresh_token' => 'secret-refresh', 'expires_in' => 3600, 'scope' => 'user-read-recently-played playlist-read-private playlist-read-collaborative'], 200),
            'api.spotify.com/v1/me' => Http::response(['id' => 'spotify-id', 'display_name' => 'Taylor', 'images' => [['url' => 'https://example.com/avatar.jpg']]], 200),
        ]);

        $this->withSession(['spotify_oauth_state' => 'correct'])
            ->get('/spotify/callback?code=code&state=correct')
            ->assertRedirect(route('configure'))
            ->assertSessionHas('status');

        $this->assertSame('secret-access', Setting::read('spotify_access_token'));
        $this->assertSame('secret-refresh', Setting::read('spotify_refresh_token'));
        $this->assertSame('spotify-id', Setting::read('spotify_user_id'));
        $this->assertTrue(app(Spotify::class)->hasPlaylistScopes());
        $this->assertNotSame('secret-access', DB::table('settings')->where('key', 'spotify_access_token')->value('value'));
    }

    public function test_expired_access_token_refreshes_and_keeps_old_refresh_token(): void
    {
        Setting::write('spotify_access_token', 'old-access');
        Setting::write('spotify_refresh_token', 'old-refresh');
        Setting::write('spotify_expires_at', now()->subMinute()->toIso8601String());
        Setting::write('spotify_scopes', 'user-read-recently-played playlist-read-private playlist-read-collaborative');
        Http::fake([
            'accounts.spotify.com/api/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600], 200),
            'api.spotify.com/v1/me' => Http::response(['id' => 'spotify-id'], 200),
        ]);

        $this->assertSame('spotify-id', app(Spotify::class)->me()['id']);
        $this->assertSame('new-access', Setting::read('spotify_access_token'));
        $this->assertSame('old-refresh', Setting::read('spotify_refresh_token'));
        $this->assertTrue(app(Spotify::class)->hasPlaylistScopes());
        Http::assertSentCount(2);
    }

    public function test_a_401_refreshes_and_retries_once(): void
    {
        $this->connected();
        Http::fake([
            'api.spotify.com/v1/me' => Http::sequence()->push(['error' => ['message' => 'expired']], 401)->push(['id' => 'retry-ok'], 200),
            'accounts.spotify.com/api/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3600], 200),
        ]);

        $this->assertSame('retry-ok', app(Spotify::class)->me()['id']);
        Http::assertSentCount(3);
    }

    public function test_failed_refresh_disconnects(): void
    {
        $this->connected(expired: true);
        Http::fake(['accounts.spotify.com/api/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            app(Spotify::class)->me();
            $this->fail('Expected token refresh to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Connect Spotify', $exception->getMessage());
        }

        $this->assertFalse(app(Spotify::class)->connected());
    }

    public function test_sync_dedupes_and_caches_track_metadata_without_more_requests(): void
    {
        $this->connected();
        $item = [
            'played_at' => '2026-11-04T10:00:00.123Z',
            'track' => [
                'type' => 'track', 'id' => 'track123', 'uri' => 'spotify:track:track123', 'name' => 'Mother', 'duration_ms' => 205000,
                'artists' => [['id' => 'artist123', 'name' => 'Danzig']],
                'album' => ['name' => 'Danzig', 'release_date' => '1988-08-30', 'images' => [['url' => 'https://example.com/large.jpg'], ['url' => 'https://example.com/medium.jpg']]],
            ],
        ];
        Http::fake(['api.spotify.com/v1/me/player/recently-played*' => Http::response(['items' => [$item]], 200)]);

        $spotify = app(Spotify::class);
        $this->assertSame(1, $spotify->sync());
        $this->assertSame(0, $spotify->sync());
        $this->assertSame(205000, Play::first()->ms_played);
        $this->assertSame('2026-11-04 10:00:00', Play::first()->played_at->format('Y-m-d H:i:s'));
        $this->assertSame('https://example.com/medium.jpg', Track::first()->album_image_url);
        $this->assertSame(1988, Track::first()->release_year);
        $this->assertSame([['id' => 'artist123', 'name' => 'Danzig']], Track::first()->artists);
        Http::assertSentCount(2);
    }

    public function test_sync_skips_plays_before_latest_export(): void
    {
        $this->connected();
        Play::create(['played_at' => '2026-11-04 11:00:00', 'ms_played' => 180000, 'track_name' => 'Already imported', 'artist_name' => 'Clutch', 'source' => 'export']);
        Http::fake(['api.spotify.com/v1/me/player/recently-played*' => Http::response(['items' => [[
            'played_at' => '2026-11-04T10:00:00Z',
            'track' => ['id' => 'old', 'name' => 'Old', 'duration_ms' => 120000, 'artists' => [['name' => 'Clutch']]],
        ]]], 200)]);

        $this->assertSame(0, app(Spotify::class)->sync());
        $this->assertSame(1, Play::count());
    }

    public function test_403_error_mentions_premium_and_user_management(): void
    {
        $this->connected();
        Http::fake(['api.spotify.com/v1/me' => Http::response(['error' => ['message' => 'Forbidden']], 403)]);

        try {
            app(Spotify::class)->me();
            $this->fail('Expected Spotify to refuse access.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Premium', $exception->getMessage());
            $this->assertStringContainsString('User Management', $exception->getMessage());
        }
    }

    public function test_rate_limit_message_includes_retry_after(): void
    {
        $this->connected();
        Http::fake(['api.spotify.com/v1/me' => Http::response(['error' => ['message' => 'Too many requests']], 429, ['Retry-After' => '12'])]);

        try {
            app(Spotify::class)->me();
            $this->fail('Expected a rate limit error.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('12 seconds', $exception->getMessage());
        }
    }

    public function test_enrichment_does_not_call_spotify_when_disconnected(): void
    {
        Http::fake();
        $this->assertNull(Track::enrich('spotify:track:track123')->fetched_at);
        $this->assertNull(Artist::enrich('Danzig')->fetched_at);
        Http::assertNothingSent();
    }

    public function test_enrichment_caches_details_when_connected(): void
    {
        $this->connected();
        Play::create(['played_at' => '2026-11-04 10:00:00', 'ms_played' => 180000, 'track_name' => 'Mother', 'artist_name' => 'Danzig', 'track_uri' => 'spotify:track:track123', 'source' => 'export']);
        Http::fake([
            'api.spotify.com/v1/tracks/track123' => Http::response(['id' => 'track123', 'name' => 'Mother', 'artists' => [['id' => 'artist123', 'name' => 'Danzig']], 'album' => ['images' => [], 'release_date' => '1988'], 'duration_ms' => 205000], 200),
            'api.spotify.com/v1/artists/artist123' => Http::response(['id' => 'artist123', 'genres' => ['doom metal'], 'images' => [['url' => 'https://example.com/artist.jpg']]], 200),
        ]);

        $this->assertSame(205000, Track::enrich('spotify:track:track123')->duration_ms);
        $this->assertSame(['doom metal'], Artist::enrich('Danzig')->genres);
        $this->assertSame('https://example.com/artist.jpg', Artist::enrich('Danzig')->image_url);
        Http::assertSentCount(2);
    }

    public function test_undecryptable_setting_reads_as_default(): void
    {
        DB::table('settings')->insert(['key' => 'broken', 'value' => 'not encrypted']);
        $this->assertSame('default', Setting::read('broken', 'default'));
    }

    public function test_enrichment_lookup_budget_stops_uncached_calls(): void
    {
        $this->connected();
        Http::fake();
        $spotify = app(Spotify::class);
        $spotify->budget = 0;

        $this->assertNull(Track::enrich('spotify:track:track123')->fetched_at);
        $this->assertSame(0, $spotify->budget);
        Http::assertNothingSent();
    }

    private function connected(bool $expired = false): void
    {
        Setting::write('spotify_access_token', 'old-access');
        Setting::write('spotify_refresh_token', 'old-refresh');
        Setting::write('spotify_expires_at', now()->addSeconds($expired ? -60 : 3600)->toIso8601String());
    }
}
