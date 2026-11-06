<?php

namespace App\Models;

use App\Services\Spotify;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class Track extends Model
{
    public $timestamps = false;

    public static function enrich(string $uri): self
    {
        $track = self::query()->firstOrCreate(['uri' => $uri]);
        $spotify = app(Spotify::class);

        if ($track->fetched_at !== null || ! preg_match('/^spotify:track:([A-Za-z0-9]+)$/', $uri, $matches) || ! $spotify->connected() || ! $spotify->consumeLookup()) {
            return $track;
        }

        try {
            return self::fromApi($spotify->track($matches[1]));
        } catch (Throwable $exception) {
            report($exception);

            return $track;
        }
    }

    /** @param array<string, mixed> $track */
    public static function fromApi(array $track): self
    {
        $uri = $track['uri'] ?? (isset($track['id']) ? 'spotify:track:'.$track['id'] : null);
        if (! is_string($uri) || $uri === '') {
            throw new \InvalidArgumentException('The Spotify track has no URI.');
        }

        $images = $track['album']['images'] ?? [];
        $image = $images[1]['url'] ?? $images[0]['url'] ?? null;
        $releaseDate = $track['album']['release_date'] ?? null;
        $year = is_string($releaseDate) && preg_match('/^\d{4}/', $releaseDate) ? (int) substr($releaseDate, 0, 4) : null;
        $artists = array_values(array_filter(array_map(
            fn (array $artist): array => ['id' => (string) ($artist['id'] ?? ''), 'name' => (string) ($artist['name'] ?? '')],
            array_filter($track['artists'] ?? [], 'is_array')
        ), fn (array $artist): bool => $artist['name'] !== ''));

        return self::query()->updateOrCreate(['uri' => $uri], [
            'artists' => $artists,
            'album_image_url' => $image,
            'release_year' => $year,
            'duration_ms' => isset($track['duration_ms']) ? (int) $track['duration_ms'] : null,
            'fetched_at' => now()->utc(),
        ]);
    }

    protected function casts(): array
    {
        return ['artists' => 'array', 'release_year' => 'integer', 'duration_ms' => 'integer', 'fetched_at' => 'immutable_datetime'];
    }
}
