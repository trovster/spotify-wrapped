<?php

namespace Tests\Feature;

use App\Enums\Period;
use App\Models\Wrap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WrapArtworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_serves_saved_spotify_artwork_from_the_same_origin(): void
    {
        $imageUrl = 'https://i.scdn.co/image/ab67616d0000b2731234567890abcdef';
        $wrap = $this->createWrap($imageUrl);
        Http::fake([$imageUrl => Http::response('image bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $this->get(route('wraps.cards.artwork', [$wrap, 0]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('image bytes');

        Http::assertSentCount(1);
    }

    public function test_it_rejects_non_spotify_artwork_urls_without_requesting_them(): void
    {
        $wrap = $this->createWrap(null);
        Http::fake();

        foreach ([
            'https://example.com/image/abc123',
            'https://evil.i.scdn.co/image/abc123',
            'http://i.scdn.co/image/abc123',
            'https://user@i.scdn.co/image/abc123',
            'https://i.scdn.co:444/image/abc123',
            'https://i.scdn.co/image/abc123?next=1',
            'https://i.scdn.co/not-image/abc123',
        ] as $imageUrl) {
            $wrap->update(['cards' => [['image_url' => $imageUrl]]]);

            $this->get(route('wraps.cards.artwork', [$wrap, 0]))->assertNotFound();
        }

        $this->get(route('wraps.cards.artwork', [$wrap, 1]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_it_rejects_redirects_and_non_image_responses(): void
    {
        $imageUrl = 'https://i.scdn.co/image/abc123';
        $wrap = $this->createWrap($imageUrl);

        Http::fake([$imageUrl => Http::response('', 302, ['Location' => 'https://example.com/private'])]);
        $this->get(route('wraps.cards.artwork', [$wrap, 0]))->assertNotFound();

        Http::fake([$imageUrl => Http::response('<html>Oops</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->get(route('wraps.cards.artwork', [$wrap, 0]))->assertNotFound();
    }

    private function createWrap(?string $imageUrl): Wrap
    {
        return Wrap::query()->create([
            'period' => Period::Week,
            'starts_at' => CarbonImmutable::parse('2026-10-29 12:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-11-05 12:00:00', 'UTC'),
            'cards' => [['image_url' => $imageUrl]],
        ]);
    }
}
