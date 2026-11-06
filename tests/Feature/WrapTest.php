<?php

namespace Tests\Feature;

use App\Enums\Period;
use App\Models\Setting;
use App\Models\Wrap;
use App\Wrapped\WrappedBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_period_windows_baselines_and_all_time_start(): void
    {
        $now = CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC');
        foreach ([[Period::Week, 7], [Period::Month, 30], [Period::HalfYear, 182], [Period::Year, 365]] as [$period, $days]) {
            [$from, $to] = $period->window($now);
            [$baselineFrom, $baselineTo] = $period->baseline($now);
            $this->assertSame($now->subDays($days)->toDateTimeString(), $from->toDateTimeString());
            $this->assertSame($now->toDateTimeString(), $to->toDateTimeString());
            $this->assertSame($now->subDays($days * 2)->toDateTimeString(), $baselineFrom->toDateTimeString());
            $this->assertSame($from->toDateTimeString(), $baselineTo->toDateTimeString());
        }

        $this->play('Old favourite', 'First song', '2018-01-02 09:00:00');
        $this->assertSame('2018-01-02 09:00:00', Period::AllTime->window($now)[0]->toDateTimeString());
        $this->assertSame('2018-01-02 09:00:00', Period::AllTime->baseline($now)[0]->toDateTimeString());
        $this->assertSame('all time', Period::AllTime->label());
    }

    public function test_build_saves_three_cards_and_rebuilds_in_place_on_the_same_local_day(): void
    {
        $builder = app(WrappedBuilder::class);
        $first = $builder->build(Period::Week, CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'));

        $this->assertCount(3, $first->cards);
        $this->assertContains($first->accent, Wrap::ACCENTS);
        $this->assertSame(['biggest_change', 'new_discovery', 'summary'], array_column($first->cards, 'type'));
        $this->assertSame(['ember', 'violet', 'gold'], array_column($first->cards, 'theme'));
        foreach ($first->cards as $card) {
            $this->assertSame(['type', 'headline', 'body', 'stat', 'stat_label', 'image_url', 'theme'], array_keys($card));
        }

        $second = $builder->build(Period::Week, CarbonImmutable::parse('2026-11-05 20:00:00', 'UTC'));
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->accent, $second->fresh()->accent);
        $this->assertSame(1, Wrap::query()->count());

        $third = $builder->build(Period::Week, CarbonImmutable::parse('2026-11-06 12:00:00', 'UTC'));
        $this->assertNotSame($first->id, $third->id);
        $this->assertSame(2, Wrap::query()->count());
    }

    public function test_same_local_day_can_cross_utc_midnight(): void
    {
        $builder = app(WrappedBuilder::class);
        $beforeMidnight = $builder->build(Period::Week, CarbonImmutable::parse('2026-07-01 23:30:00', 'UTC'));
        $afterMidnight = $builder->build(Period::Week, CarbonImmutable::parse('2026-07-02 08:00:00', 'UTC'));

        $this->assertSame($beforeMidnight->id, $afterMidnight->id);
        $this->assertSame(1, Wrap::query()->count());
    }

    public function test_generate_route_validates_the_period_and_redirects_to_the_deck(): void
    {
        $this->post('/wraps', ['period' => 'nonsense'])->assertSessionHasErrors('period');
        $this->post('/wraps', ['period' => 'month'])->assertRedirect('/wraps/1');
        $this->assertSame(Period::Month, Wrap::query()->first()->period);
    }

    public function test_archive_shows_saved_wraps_and_can_delete_one(): void
    {
        $builder = app(WrappedBuilder::class);
        $older = $builder->build(Period::Week, CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC'));
        $newer = $builder->build(Period::Month, CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'));
        $newer->accent = 'mint';
        $newer->save();

        $this->get('/wraps')
            ->assertOk()
            ->assertSeeInOrder([$newer->cards[0]['headline'], $older->cards[0]['headline']])
            ->assertSee('data-wrap-color="mint"', false)
            ->assertSee('<dialog id="wrap-delete-'.$newer->id.'"', false)
            ->assertSee('data-wrap-delete-open aria-haspopup="dialog"', false)
            ->assertDontSee('popover="auto"', false)
            ->assertSee('Confirm');

        $this->delete(route('wraps.destroy', $older))
            ->assertRedirect(route('wraps.index'))
            ->assertSessionHas('status', 'Wrapped story deleted.');

        $this->assertDatabaseMissing('wraps', ['id' => $older->id]);
        $this->assertDatabaseHas('wraps', ['id' => $newer->id, 'accent' => 'mint']);
        $this->get(route('wraps.show', $older))->assertNotFound();
        $this->get('/wraps')
            ->assertSee($newer->cards[0]['headline'])
            ->assertDontSee($older->cards[0]['headline']);
    }

    public function test_deleting_a_home_wrap_returns_home(): void
    {
        $wrap = app(WrappedBuilder::class)->build(Period::Month, CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'));

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('name="return_to" value="home"', false);

        $this->delete(route('wraps.destroy', $wrap), ['return_to' => 'home'])
            ->assertRedirect(route('home'));

        $this->assertDatabaseMissing('wraps', ['id' => $wrap->id]);
    }

    public function test_generate_command_accepts_known_periods_and_rejects_unknown_ones(): void
    {
        $this->artisan('wrapped:generate', ['period' => 'week'])->assertSuccessful();
        $this->assertSame(1, Wrap::query()->count());
        $this->artisan('wrapped:generate', ['period' => 'not-a-period'])->assertFailed();
        $this->assertSame(1, Wrap::query()->count());
    }

    public function test_home_lazily_builds_a_weekly_wrap_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'));
        $this->play('Clutch', 'Spacegrass', '2026-11-04 10:00:00');

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        $this->assertSame(1, Wrap::query()->where('period', Period::Week->value)->count());
        $this->travelBack();
    }

    public function test_deleting_a_recent_weekly_wrap_does_not_recreate_it_on_home(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'));
        $this->play('Clutch', 'Spacegrass', '2026-11-04 10:00:00');

        $this->get('/')->assertOk();
        $wrap = Wrap::query()->firstOrFail();
        $this->delete(route('wraps.destroy', $wrap))->assertRedirect(route('wraps.index'));
        $this->get('/')->assertOk();

        $this->assertSame(0, Wrap::query()->count());

        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'UTC'));
        $this->play('Clutch', 'New song', '2026-11-12 10:00:00');
        $this->get('/')->assertOk();
        $this->assertSame(1, Wrap::query()->count());
        $this->travelBack();
    }

    public function test_failed_token_refresh_shows_a_disconnected_configure_page(): void
    {
        Setting::write('spotify_access_token', 'expired');
        Setting::write('spotify_refresh_token', 'revoked');
        Setting::write('spotify_expires_at', '2020-01-01T00:00:00Z');
        Http::fake(['accounts.spotify.com/api/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->get('/configure')->assertOk()->assertViewHas('connected', false);
        $this->assertNull(Setting::read('spotify_refresh_token'));
    }

    private function play(string $artist, string $track, string $at): void
    {
        DB::table('plays')->insert([
            'played_at' => $at,
            'ms_played' => 180000,
            'track_name' => $track,
            'artist_name' => $artist,
            'source' => 'export',
        ]);
    }
}
