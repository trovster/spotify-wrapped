<?php

namespace App\Models;

use App\Services\Spotify;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class Artist extends Model
{
    public $timestamps = false;

    public static function enrich(string $name): self
    {
        $artist = self::query()->firstOrCreate(['name' => $name]);
        $spotify = app(Spotify::class);

        if ($artist->fetched_at !== null || ! $spotify->connected()) {
            return $artist;
        }

        try {
            $uri = Play::query()->where('artist_name', $name)->where('track_uri', 'like', 'spotify:track:%')->value('track_uri');
            if (! $uri) {
                return $artist;
            }

            $track = Track::enrich($uri);
            $artists = $track->artists ?? [];
            $match = collect($artists)->first(fn (array $candidate): bool => strcasecmp($candidate['name'] ?? '', $name) === 0) ?? ($artists[0] ?? null);
            $id = $match['id'] ?? null;
            if (! $id || ! $spotify->consumeLookup()) {
                return $artist;
            }

            $details = $spotify->artist($id);
            $artist->update([
                'spotify_id' => $id,
                'image_url' => $details['images'][0]['url'] ?? null,
                'genres' => $details['genres'] ?? [],
                'fetched_at' => now()->utc(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $artist->refresh();
    }

    protected function casts(): array
    {
        return ['genres' => 'array', 'fetched_at' => 'immutable_datetime'];
    }
}
