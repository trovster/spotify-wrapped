<?php

namespace Tests\Feature;

use App\Ai\Agents\WrapSentencePolisher;
use App\Enums\Period;
use App\Wrapped\WrappedBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class WrappedCardsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC');
    }

    public function test_rising_artist_is_chosen_from_established_artists_only(): void
    {
        $this->plays('Clutch', 'Electric Worry', '2026-10-25 10:00:00', 2);
        $this->plays('Clutch', 'Electric Worry', '2026-11-02 10:00:00', 8);
        $this->plays('Danzig', 'Mother', '2026-11-03 10:00:00', 20);

        $builder = app(WrappedBuilder::class);
        $change = $builder->biggestChange(Period::Week, $this->now);
        $discovery = $builder->newDiscovery(Period::Week, $this->now);

        $this->assertSame('Clutch was your fastest-rising artist this week.', $change['headline']);
        $this->assertSame('8 plays, up from 2 last week.', $change['body']);
        $this->assertSame('+6', $change['stat']);
        $this->assertSame('You discovered Danzig this week.', $discovery['headline']);
    }

    public function test_drop_and_noise_threshold(): void
    {
        $this->plays('Clutch', 'Electric Worry', '2026-10-25 10:00:00', 10);
        $this->plays('Clutch', 'Electric Worry', '2026-11-02 10:00:00', 2);

        $card = app(WrappedBuilder::class)->biggestChange(Period::Week, $this->now);
        $this->assertSame('You took a break from Clutch this week.', $card['headline']);
        $this->assertSame('-8', $card['stat']);

        DB::table('plays')->delete();
        $this->plays('Clutch', 'Electric Worry', '2026-10-25 10:00:00', 2);
        $this->plays('Clutch', 'Electric Worry', '2026-11-02 10:00:00', 3);

        $card = app(WrappedBuilder::class)->biggestChange(Period::Week, $this->now);
        $this->assertStringStartsWith('You listened', $card['headline']);
    }

    public function test_large_total_change_beats_an_artist_move(): void
    {
        $this->plays('Clutch', 'Electric Worry', '2026-10-25 10:00:00', 20, 60000);
        $this->plays('Clutch', 'Electric Worry', '2026-11-02 10:00:00', 40, 180000);

        $card = app(WrappedBuilder::class)->biggestChange(Period::Week, $this->now);
        $this->assertSame('You listened 500% more this week.', $card['headline']);
        $this->assertSame('+500%', $card['stat']);
    }

    public function test_equal_artist_moves_prefer_a_rise_and_then_name(): void
    {
        $this->plays('B Rise', 'Old', '2026-10-25 10:00:00', 1);
        $this->plays('A Rise', 'Old', '2026-10-25 11:00:00', 1);
        $this->plays('C Drop', 'Old', '2026-10-25 12:00:00', 7);
        $this->plays('B Rise', 'New', '2026-11-02 10:00:00', 7);
        $this->plays('A Rise', 'New', '2026-11-02 11:00:00', 7);
        $this->plays('C Drop', 'New', '2026-11-02 12:00:00', 1);

        $card = app(WrappedBuilder::class)->biggestChange(Period::Week, $this->now);
        $this->assertSame('A Rise was your fastest-rising artist this week.', $card['headline']);
    }

    public function test_discovery_names_first_day_and_top_song_in_display_timezone(): void
    {
        $this->plays('Clutch', 'Old', '2026-09-01 12:00:00', 1);
        $this->plays('Danzig', 'Mother', '2026-11-03 10:00:00', 7);
        $this->plays('Danzig', 'Other', '2026-11-04 10:00:00', 2);
        $this->plays('Kyuss', 'Green Machine', '2026-11-04 11:00:00', 2);

        $card = app(WrappedBuilder::class)->newDiscovery(Period::Week, $this->now);
        $this->assertSame('You discovered Danzig this week.', $card['headline']);
        $this->assertSame("9 plays since you first heard them on Tuesday 3 November, mostly 'Mother'. One of 2 new artists this week.", $card['body']);
    }

    public function test_first_week_and_no_discoveries_have_honest_copy(): void
    {
        $this->plays('Clutch', 'Spacegrass', '2026-11-02 10:00:00', 2);
        $card = app(WrappedBuilder::class)->newDiscovery(Period::Week, $this->now);
        $this->assertSame('Clutch kicked off your listening history.', $card['headline']);
        $this->assertStringContainsString('Import your full Spotify data', $card['body']);

        DB::table('plays')->delete();
        $this->plays('Clutch', 'Old', '2026-09-01 12:00:00', 1);
        $this->plays('Clutch', 'Spacegrass', '2026-11-02 10:00:00', 2);
        $card = app(WrappedBuilder::class)->newDiscovery(Period::Week, $this->now);
        $this->assertSame('No new artists this week.', $card['headline']);
    }

    public function test_summary_uses_genres_and_classic_history_when_available(): void
    {
        $this->plays('Clutch', 'Old', '2024-01-01 12:00:00', 1);
        $this->plays('Other', 'Previous', '2026-10-25 12:00:00', 1);
        $this->plays('Clutch', 'Spacegrass', '2026-11-02 10:00:00', 6);
        DB::table('artists')->insert([
            ['name' => 'Clutch', 'genres' => json_encode(['doom metal']), 'fetched_at' => '2026-11-01 00:00:00'],
            ['name' => 'Other', 'genres' => json_encode(['rock']), 'fetched_at' => '2026-11-01 00:00:00'],
        ]);

        $card = app(WrappedBuilder::class)->summary(Period::Week, $this->now);
        $this->assertSame('This week you explored darker metal while revisiting classic favourites.', $card['headline']);
        $this->assertSame('18 minutes, 6 plays and 1 artist. Clutch led the way with 6 plays.', $card['body']);
    }

    public function test_summary_without_genres_is_deterministic_and_network_free(): void
    {
        WrapSentencePolisher::fake()->preventStrayPrompts();
        $this->plays('Clutch', 'Old', '2026-09-01 12:00:00', 1);
        $this->plays('Clutch', 'Spacegrass', '2026-11-02 10:00:00', 3);
        $card = app(WrappedBuilder::class)->summary(Period::Week, $this->now);

        $this->assertSame('This week you kept coming back to Clutch.', $card['headline']);
        $this->assertSame('9 minutes, 3 plays and 1 artist. Clutch led the way with 3 plays.', $card['body']);
        WrapSentencePolisher::assertNeverPrompted();
    }

    public function test_summary_uses_laravel_ai_to_polish_a_sentence_when_configured(): void
    {
        config()->set('ai.providers.anthropic.key', 'test-key');
        $this->plays('Clutch', 'Spacegrass', '2026-11-02 10:00:00', 3);
        WrapSentencePolisher::fake(['This week you played Clutch on repeat.'])->preventStrayPrompts();

        $card = app(WrappedBuilder::class)->summary(Period::Week, $this->now);

        $this->assertSame('This week you played Clutch on repeat.', $card['headline']);
        WrapSentencePolisher::assertPrompted(function (AgentPrompt $prompt): bool {
            $input = json_decode($prompt->prompt, true, flags: JSON_THROW_ON_ERROR);

            return $prompt->provider->driver() === 'anthropic'
                && $prompt->model === config('ai.wrap_model')
                && $prompt->timeout === 20
                && $input['draft'] === 'This week you kept coming back to Clutch.'
                && $input['facts']['plays'] === 3
                && $input['facts']['name'] === 'Clutch';
        });
    }

    public function test_summary_falls_back_when_laravel_ai_returns_invalid_text(): void
    {
        config()->set('ai.providers.anthropic.key', 'test-key');
        $this->plays('Clutch', 'Spacegrass', '2026-11-02 10:00:00', 3);
        WrapSentencePolisher::fake(["Two lines\nare not a sentence.", str_repeat('word ', 31)])->preventStrayPrompts();

        $builder = app(WrappedBuilder::class);
        $this->assertSame('This week you kept coming back to Clutch.', $builder->summary(Period::Week, $this->now)['headline']);
        $this->assertSame('This week you kept coming back to Clutch.', $builder->summary(Period::Week, $this->now)['headline']);
        WrapSentencePolisher::assertPromptedTimes(2);
    }

    public function test_summary_rejects_truncated_anthropic_responses_through_laravel_ai(): void
    {
        config()->set('ai.providers.anthropic.key', 'test-key');
        $this->plays('Clutch', 'Spacegrass', '2026-11-02 10:00:00', 3);
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'type' => 'message',
                'role' => 'assistant',
                'model' => config('ai.wrap_model'),
                'content' => [['type' => 'text', 'text' => 'This week you played Clutch on repeat.']],
                'stop_reason' => 'max_tokens',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
            ]),
        ]);

        $card = app(WrappedBuilder::class)->summary(Period::Week, $this->now);

        $this->assertSame('This week you kept coming back to Clutch.', $card['headline']);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $payload['model'] === config('ai.wrap_model')
                && $payload['max_tokens'] === 16000
                && $payload['output_config']['effort'] === 'low'
                && str_contains($payload['system'], 'Use only the supplied facts');
        });
        Http::assertSentCount(1);
    }

    public function test_empty_window_still_produces_three_friendly_cards(): void
    {
        $cards = app(WrappedBuilder::class)->build(Period::Week, $this->now)->cards;

        $this->assertSame('No plays this week.', $cards[0]['headline']);
        $this->assertSame('No new artists this week.', $cards[1]['headline']);
        $this->assertSame('Nothing played this week.', $cards[2]['headline']);
    }

    public function test_large_quantities_have_grouping_separators_and_spaces_in_generated_cards(): void
    {
        $this->plays('Old favourite', 'Old track', '2026-09-01 12:00:00', 1);
        $this->plays('New artist', 'New track', '2026-11-02 10:00:00', 1250);

        $builder = app(WrappedBuilder::class);
        $change = $builder->biggestChange(Period::Week, $this->now);
        $discovery = $builder->newDiscovery(Period::Week, $this->now);
        $summary = $builder->summary(Period::Week, $this->now);

        $this->assertSame('You played 3,750 minutes of music this week.', $change['headline']);
        $this->assertSame('1,250 plays across 1 artist – nothing earlier to compare with yet.', $change['body']);
        $this->assertSame('3,750', $change['stat']);
        $this->assertStringStartsWith('1,250 plays since you first heard them', $discovery['body']);
        $this->assertSame('1,250', $discovery['stat']);
        $this->assertStringStartsWith('3,750 minutes, 1,250 plays and 1 artist.', $summary['body']);
    }

    public function test_saved_cards_format_large_numbers_and_keep_label_text_in_normal_case(): void
    {
        $wrap = app(WrappedBuilder::class)->build(Period::Week, $this->now);
        $cards = $wrap->cards;
        $cards[0]['headline'] = 'You played 1234 minutes in 2026 and found 1234 new artists.';
        $cards[0]['body'] = '1234 plays across 2 artists.';
        $cards[0]['stat'] = '+1234';
        $cards[0]['stat_label'] = 'minutes';
        $wrap->cards = $cards;
        $wrap->save();

        $this->get(route('wraps.show', $wrap))
            ->assertOk()
            ->assertSee('You played 1,234 minutes in 2026 and found 1,234 new artists.')
            ->assertSee('1,234 plays across 2 artists.')
            ->assertSee('+1,234</strong> <span>minutes</span>', false)
            ->assertSee('Biggest change')
            ->assertDontSee('BIGGEST CHANGE')
            ->assertSee('Download');
    }

    private function plays(string $artist, string $track, string $first, int $count, int $ms = 180000): void
    {
        $at = CarbonImmutable::parse($first, 'UTC');
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'played_at' => $at->addSeconds($i)->toDateTimeString(),
                'ms_played' => $ms,
                'track_name' => $track,
                'artist_name' => $artist,
                'source' => 'export',
            ];
        }

        DB::table('plays')->insert($rows);
    }
}
