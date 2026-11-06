<?php

namespace App\Services;

use App\Models\Play;
use App\Models\Setting;
use App\Models\Track;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Spotify
{
    private const PLAYLIST_SCOPES = ['playlist-read-private', 'playlist-read-collaborative'];

    public ?int $budget = null;

    public function configured(): bool
    {
        return filled(config('services.spotify.client_id')) && filled(config('services.spotify.client_secret')) && filled(config('services.spotify.redirect'));
    }

    public function connected(): bool
    {
        return filled(Setting::read('spotify_refresh_token'));
    }

    public function consumeLookup(): bool
    {
        if ($this->budget === null) {
            return true;
        }

        if ($this->budget <= 0) {
            return false;
        }

        $this->budget--;

        return true;
    }

    public function authorizeUrl(string $state): string
    {
        return 'https://accounts.spotify.com/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.spotify.client_id'),
            'scope' => implode(' ', ['user-read-recently-played', ...self::PLAYLIST_SCOPES]),
            'redirect_uri' => config('services.spotify.redirect'),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(string $code): array
    {
        $response = Http::asForm()->withBasicAuth(config('services.spotify.client_id'), config('services.spotify.client_secret'))
            ->post('https://accounts.spotify.com/api/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => config('services.spotify.redirect'),
            ]);
        $this->check($response);
        $this->storeTokens($response->json());

        $profile = $this->me();
        Setting::write('spotify_name', $profile['display_name'] ?? $profile['id'] ?? 'Spotify listener');
        Setting::write('spotify_image_url', $profile['images'][0]['url'] ?? null);
        Setting::write('spotify_user_id', $profile['id'] ?? null);

        return $profile;
    }

    public function disconnect(): void
    {
        foreach (['spotify_access_token', 'spotify_refresh_token', 'spotify_expires_at', 'spotify_scopes', 'spotify_name', 'spotify_image_url', 'spotify_user_id'] as $key) {
            Setting::write($key, null);
        }
    }

    public function me(): array
    {
        return $this->get('me');
    }

    public function recentlyPlayed(): array
    {
        return $this->get('me/player/recently-played', ['limit' => 50]);
    }

    public function hasPlaylistScopes(): bool
    {
        $scopes = explode(' ', (string) Setting::read('spotify_scopes'));

        return count(array_intersect(self::PLAYLIST_SCOPES, $scopes)) === count(self::PLAYLIST_SCOPES);
    }

    public function playlists(int $offset = 0): array
    {
        return $this->get('me/playlists', ['limit' => 50, 'offset' => $offset]);
    }

    public function track(string $id): array
    {
        return $this->get('tracks/'.rawurlencode($id));
    }

    public function artist(string $id): array
    {
        return $this->get('artists/'.rawurlencode($id));
    }

    public function sync(): int
    {
        $items = $this->recentlyPlayed()['items'] ?? [];
        $lastExport = Play::query()->where('source', 'export')->max('played_at');
        $rows = [];

        foreach ($items as $item) {
            $track = $item['track'] ?? [];
            if (($track['type'] ?? 'track') !== 'track' || ! is_array($track) || empty($track['name']) || empty($track['artists'][0]['name']) || empty($item['played_at'])) {
                continue;
            }

            $playedAt = CarbonImmutable::parse($item['played_at'])->utc()->toDateTimeString();
            if ($lastExport !== null && $playedAt <= $lastExport) {
                continue;
            }

            if (! empty($track['uri']) || ! empty($track['id'])) {
                Track::fromApi($track);
            }

            $rows[] = [
                'played_at' => $playedAt,
                'ms_played' => isset($track['duration_ms']) ? (int) $track['duration_ms'] : null,
                'track_name' => $track['name'],
                'artist_name' => $track['artists'][0]['name'],
                'album_name' => $track['album']['name'] ?? null,
                'track_uri' => $track['uri'] ?? (isset($track['id']) ? 'spotify:track:'.$track['id'] : null),
                'source' => 'api',
            ];
        }

        $inserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $inserted += DB::table('plays')->insertOrIgnore($chunk);
        }

        Setting::write('last_synced_at', now()->utc()->toIso8601String());

        return $inserted;
    }

    private function get(string $path, array $query = []): array
    {
        $token = $this->accessToken();
        $url = 'https://api.spotify.com/v1/'.$path;
        $response = Http::withToken($token)->get($url, $query);
        if ($response->status() === 401) {
            $this->refresh();
            $response = Http::withToken((string) Setting::read('spotify_access_token'))->get($url, $query);
        }

        $this->check($response);

        return $response->json() ?? [];
    }

    private function accessToken(): string
    {
        if (! $this->connected()) {
            throw new RuntimeException('Spotify is not connected. Click Connect Spotify.');
        }

        $expires = Setting::read('spotify_expires_at');
        if (! Setting::read('spotify_access_token') || ! $expires || CarbonImmutable::parse($expires)->lessThan(now()->addMinute())) {
            $this->refresh();
        }

        return (string) Setting::read('spotify_access_token');
    }

    private function refresh(): void
    {
        $refreshToken = Setting::read('spotify_refresh_token');
        if (! $refreshToken) {
            throw new RuntimeException('Spotify sign-in has expired. Click Connect Spotify.');
        }

        $response = Http::asForm()->withBasicAuth(config('services.spotify.client_id'), config('services.spotify.client_secret'))
            ->post('https://accounts.spotify.com/api/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);
        if ($response->clientError()) {
            $this->disconnect();
            throw new RuntimeException('Spotify sign-in has expired. Click Connect Spotify.');
        }

        $this->check($response);
        $this->storeTokens($response->json(), $refreshToken);
    }

    private function storeTokens(array $payload, ?string $oldRefreshToken = null): void
    {
        if (empty($payload['access_token']) || (empty($payload['refresh_token']) && ! $oldRefreshToken)) {
            throw new RuntimeException('Spotify did not return usable sign-in tokens. Click Connect Spotify.');
        }

        Setting::write('spotify_access_token', $payload['access_token']);
        Setting::write('spotify_refresh_token', $payload['refresh_token'] ?? $oldRefreshToken);
        Setting::write('spotify_expires_at', now()->utc()->addSeconds((int) ($payload['expires_in'] ?? 3600))->toIso8601String());
        if (array_key_exists('scope', $payload) || $oldRefreshToken === null) {
            Setting::write('spotify_scopes', $payload['scope'] ?? null);
        }
    }

    private function check(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $error = $response->json('error.message') ?? $response->json('error_description') ?? $response->json('error') ?? 'Spotify could not complete the request.';
        if (! is_string($error)) {
            $error = 'Spotify could not complete the request.';
        }

        if ($response->status() === 403) {
            throw new RuntimeException("Spotify refused access: {$error}. The account that owns the developer app needs Premium, and your account must be listed under User Management.");
        }

        if ($response->status() === 429) {
            throw new RuntimeException('Spotify rate limited this request. Try again after '.($response->header('Retry-After') ?? 'a while').' seconds.');
        }

        throw new RuntimeException("Spotify error ({$response->status()}): {$error}");
    }
}
