<?php

namespace App\Http\Controllers;

use App\Enums\Period;
use App\Models\Artist;
use App\Models\Setting;
use App\Models\Track;
use App\Services\Spotify;
use App\Services\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

class StatsController extends Controller
{
    public function __invoke(Request $request, Stats $stats, Spotify $spotify, string $tab = 'overview'): View
    {
        $period = Period::tryFrom((string) $request->query('period')) ?? Period::Year;
        $now = CarbonImmutable::now('UTC');
        [$from, $to] = $period->window($now);
        $spotify->budget = 20;

        $data = [
            'tab' => $tab,
            'period' => $period,
            'periods' => Period::cases(),
            'totals' => $stats->totals($from, $to),
            'playCount' => $stats->totals(null, $to)['plays'],
            'connected' => $spotify->connected(),
            'profile' => [
                'name' => Setting::read('spotify_name', 'Your listening'),
                'image_url' => Setting::read('spotify_image_url'),
                'id' => Setting::read('spotify_user_id'),
            ],
            'artists' => [], 'songs' => [], 'albums' => [], 'genres' => [],
            'hours' => [], 'days' => [], 'familiar' => [], 'decades' => [], 'lengths' => [],
            'unknownYears' => 0, 'recent' => [],
            'playlists' => [], 'playlistPage' => 1, 'playlistTotal' => 0,
            'playlistNeedsReconnect' => false, 'playlistError' => null,
        ];

        if ($tab === 'playlists' && $spotify->connected()) {
            if (! $spotify->hasPlaylistScopes()) {
                $data['playlistNeedsReconnect'] = true;
            } else {
                $page = min(2000, max(1, $request->integer('page', 1)));
                $data['playlistPage'] = $page;

                try {
                    $response = $spotify->playlists(($page - 1) * 50);
                    $data['playlistTotal'] = (int) ($response['total'] ?? 0);
                    $data['playlists'] = array_values(array_map(fn (array $playlist) => [
                        'name' => $playlist['name'] ?? 'Untitled playlist',
                        'image' => $playlist['images'][0]['url'] ?? null,
                        'owner' => $playlist['owner']['display_name'] ?? $playlist['owner']['id'] ?? 'Spotify listener',
                        'items' => (int) ($playlist['items']['total'] ?? $playlist['tracks']['total'] ?? 0),
                        'url' => $playlist['external_urls']['spotify'] ?? null,
                    ], array_filter($response['items'] ?? [], 'is_array')));
                } catch (RuntimeException|ConnectionException $exception) {
                    $data['playlistError'] = $exception->getMessage();
                }
            }
        }

        if (in_array($tab, ['overview', 'artists'], true)) {
            $artists = $stats->artists($from, $to, $tab === 'overview' ? 20 : 50);
            $data['artists'] = $this->artistRows($artists, $tab === 'overview' ? 10 : 50);
        }

        if (in_array($tab, ['overview', 'songs'], true)) {
            $data['songs'] = $this->trackRows($stats->tracks($from, $to, $tab === 'overview' ? 10 : 50));
        }

        if (in_array($tab, ['overview', 'albums'], true)) {
            $data['albums'] = $this->albumRows($stats->albums($from, $to, $tab === 'overview' ? 10 : 50));
        }

        if (in_array($tab, ['overview', 'genres'], true)) {
            $artists ??= $stats->artists($from, $to, 20);
            $genres = $stats->genres($artists, 20);
            $data['genres'] = $this->genreRows($genres, $tab === 'overview' ? 15 : 50);
        }

        if ($tab === 'insights') {
            $clock = $stats->clock($from, $to);
            $data['hours'] = $this->chartRows($clock['hours'], fn (int $key) => sprintf('%02d:00', $key));
            $data['days'] = $this->chartRows($clock['days'], fn (int $key) => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$key]);
            $familiar = $stats->familiar($from, $to, $period->baseline($now)[1]);
            $data['familiar'] = $this->chartRows(['New' => $familiar['new'], 'Familiar' => $familiar['familiar']]);
            $tracks = $stats->tracks($from, $to, 50);
            $decades = $stats->decades($tracks);
            $data['decades'] = $this->chartRows($decades['counts']);
            $data['unknownYears'] = $decades['unknown'];
            $data['lengths'] = $this->chartRows($stats->lengths($tracks));
        }

        if ($tab === 'recent') {
            $data['recent'] = $stats->recent()->map(fn ($play) => [
                'track' => $play->track_name,
                'artist' => $play->artist_name,
                'played_at' => $play->played_at,
            ])->all();
        }

        return view('stats', $data);
    }

    private function artistRows(Collection $artists, int $limit): array
    {
        return $artists->take($limit)->values()->map(fn ($row, int $index) => [
            'rank' => $index + 1,
            'name' => $row->artist_name,
            'sub' => '',
            'plays' => (int) $row->plays,
            'image' => Artist::enrich($row->artist_name)->image_url,
        ])->all();
    }

    private function trackRows(Collection $tracks): array
    {
        return $tracks->values()->map(function ($row, int $index) {
            $track = $row->uri ? Track::enrich($row->uri) : null;

            return [
                'rank' => $index + 1,
                'name' => $row->track_name,
                'sub' => implode(' · ', array_filter([$track?->release_year, $row->artist_name])),
                'plays' => (int) $row->plays,
                'image' => $track?->album_image_url,
            ];
        })->all();
    }

    private function albumRows(Collection $albums): array
    {
        return $albums->values()->map(function ($row, int $index) {
            $track = $row->uri ? Track::enrich($row->uri) : null;

            return [
                'rank' => $index + 1,
                'name' => $row->album_name,
                'sub' => implode(' · ', array_filter([$track?->release_year, $row->artist_name])),
                'plays' => (int) $row->plays,
                'image' => $track?->album_image_url,
            ];
        })->all();
    }

    private function genreRows(array $genres, int $limit): array
    {
        $rows = [];
        $max = max($genres ?: [0]);

        foreach (array_slice($genres, 0, $limit, true) as $name => $plays) {
            $rows[] = [
                'rank' => count($rows) + 1,
                'name' => ucwords($name),
                'sub' => '',
                'plays' => $plays,
                'image' => null,
                'v' => $max ? round($plays / $max * 100) : 0,
            ];
        }

        return $rows;
    }

    private function chartRows(array $counts, ?callable $label = null): array
    {
        $max = max($counts ?: [0]);
        $rows = [];

        foreach ($counts as $key => $count) {
            $rows[] = [
                'label' => $label ? $label($key) : (string) $key,
                'count' => (int) $count,
                'v' => $max ? (int) round($count / $max * 100) : 0,
            ];
        }

        return $rows;
    }
}
