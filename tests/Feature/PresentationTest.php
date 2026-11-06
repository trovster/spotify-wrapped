<?php

namespace Tests\Feature;

use App\Enums\Period;
use App\Models\Wrap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_home_links_to_configure_and_wraps(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Open Configure')
            ->assertSee('View your Wraps');
    }

    public function test_wrap_shows_accessible_slides_in_order_and_escapes_text(): void
    {
        $wrap = Wrap::query()->create([
            'period' => Period::Week,
            'starts_at' => CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'),
            'cards' => [
                ['type' => 'biggest_change', 'headline' => '<b>Big change</b>', 'body' => 'Body one', 'stat' => '+20', 'stat_label' => 'plays', 'image_url' => null, 'theme' => 'ember'],
                ['type' => 'new_discovery', 'headline' => 'New artist', 'body' => 'Body two', 'stat' => '9', 'stat_label' => 'plays', 'image_url' => 'https://example.com/image.jpg', 'theme' => 'violet'],
                ['type' => 'summary', 'headline' => 'Your week', 'body' => 'Body three', 'stat' => '120', 'stat_label' => 'minutes', 'image_url' => null, 'theme' => 'gold'],
            ],
        ]);

        $this->get('/wraps/'.$wrap->id)
            ->assertOk()
            ->assertSee('aria-roledescription="carousel"', false)
            ->assertSee('aria-label="1 of 3:', false)
            ->assertSee('aria-label="2 of 3:', false)
            ->assertSee('aria-label="3 of 3:', false)
            ->assertSeeInOrder(['&lt;b&gt;Big change&lt;/b&gt;', 'New artist', 'Your week'], false)
            ->assertDontSee('<b>Big change</b>', false)
            ->assertDontSee('Back to your Wraps')
            ->assertDontSee('Generate another')
            ->assertSee('data-story-download', false)
            ->assertSee('Download')
            ->assertSee('data-playback', false)
            ->assertSee('Pause story')
            ->assertSee('data-progress', false)
            ->assertSee('data-wrap-color="violet"', false)
            ->assertSee('card__visual card__visual--image-stat', false)
            ->assertSee('href="'.route('wraps.index').'" data-close', false);
    }

    public function test_saved_wrap_headline_formats_counts_on_home_and_archive(): void
    {
        Wrap::query()->create([
            'period' => Period::Month,
            'starts_at' => CarbonImmutable::parse('2026-10-05 12:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'),
            'cards' => [['headline' => 'You played 1234 minutes and found 1234 new artists.']],
        ]);

        $this->get(route('home'))->assertOk()->assertSee('1,234 minutes')->assertSee('1,234 new artists');
        $this->get(route('wraps.index'))->assertOk()->assertSee('1,234 minutes')->assertSee('1,234 new artists');
    }
}
