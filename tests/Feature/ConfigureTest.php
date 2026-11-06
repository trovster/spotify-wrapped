<?php

namespace Tests\Feature;

use App\Enums\Period;
use App\Models\Play;
use App\Models\Setting;
use App\Models\Wrap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConfigureTest extends TestCase
{
    use RefreshDatabase;

    public function test_configure_explains_setup_and_home_links_to_it_before_connection(): void
    {
        config()->set('services.spotify', ['client_id' => null, 'client_secret' => null, 'redirect' => null]);

        $this->get(route('configure'))
            ->assertOk()
            ->assertSee('Create a Spotify developer app')
            ->assertSee('SPOTIFY_CLIENT_ID')
            ->assertSee('Bring in your listening history')
            ->assertSee('Streaming_History_Audio_*.json')
            ->assertSee('Keep recent plays up to date')
            ->assertSee('Import files');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Open Configure')
            ->assertSee('View your Wraps')
            ->assertDontSee('THE SOURCE')
            ->assertDontSee('YOUR PERSONAL WRAPPED')
            ->assertDontSee('Sync now')
            ->assertDontSee('Import files');
    }

    public function test_connected_nav_opens_spotify_profile_and_configure_syncs_stale_data(): void
    {
        config()->set('services.spotify', [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect' => 'https://spotify-wrapped.test/spotify/callback',
        ]);
        Setting::write('spotify_access_token', 'access');
        Setting::write('spotify_refresh_token', 'refresh');
        Setting::write('spotify_expires_at', now()->addHour()->toIso8601String());
        Setting::write('spotify_user_id', 'listener-123');
        Setting::write('spotify_name', 'A listener');
        Setting::write('spotify_image_url', 'https://example.com/avatar.jpg');
        Http::preventStrayRequests();
        Http::fake(['api.spotify.com/v1/me/player/recently-played*' => Http::response(['items' => []], 200)]);

        $this->get(route('configure'))
            ->assertOk()
            ->assertSee('Connected as A listener')
            ->assertSee('https://example.com/avatar.jpg')
            ->assertSee('configure-profile__avatar')
            ->assertSee('Sync now')
            ->assertSee('https://open.spotify.com/user/listener-123')
            ->assertSee('spotify-mark');

        $this->assertNotNull(Setting::read('last_synced_at'));
        Http::assertSentCount(1);

        $this->get(route('home'))->assertOk()->assertDontSee('Sync now');
        Http::assertSentCount(1);
    }

    public function test_home_shows_data_summary_after_connection_and_plays(): void
    {
        Setting::write('spotify_refresh_token', 'refresh');
        Setting::write('last_synced_at', now()->toIso8601String());
        Setting::write('spotify_name', 'Alex Morgan');
        Setting::write('spotify_image_url', 'https://example.com/alex.jpg');
        Play::create([
            'played_at' => '2026-11-04 12:00:00',
            'ms_played' => 120000,
            'track_name' => 'Song',
            'artist_name' => 'Artist',
            'source' => 'export',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Listening history')
            ->assertSeeInOrder(['Your data', 'Alex Morgan'])
            ->assertSee('https://example.com/alex.jpg')
            ->assertSee('Manage your data')
            ->assertDontSee('Open Configure');
    }

    public function test_imported_plays_show_the_data_summary_before_spotify_connection(): void
    {
        Play::create([
            'played_at' => '2026-11-04 12:00:00',
            'ms_played' => 120000,
            'track_name' => 'Song',
            'artist_name' => 'Artist',
            'source' => 'export',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Listening history')
            ->assertSee('home-profile__avatar--fallback')
            ->assertSee('Your listening')
            ->assertSee('Manage your data')
            ->assertDontSee('Bring your music in');
    }

    public function test_home_shows_only_the_three_latest_saved_wraps(): void
    {
        foreach (range(1, 4) as $number) {
            Wrap::query()->create([
                'period' => Period::Month,
                'starts_at' => CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC')->subDays(5 - $number),
                'ends_at' => CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC')->subDays(5 - $number),
                'cards' => [['headline' => match ($number) {
                    1 => 'First story', 2 => 'Second story', 3 => 'Third story', 4 => 'Fourth story'
                }]],
            ]);
        }

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Recent Wraps')
            ->assertSeeInOrder(['Fourth story', 'Third story', 'Second story'])
            ->assertDontSee('First story')
            ->assertSee('View all Wraps');
    }
}
