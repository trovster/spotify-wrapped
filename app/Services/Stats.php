<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Play;
use App\Models\Track;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Stats
{
    public function between(?CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('plays')
            ->when($from, fn (Builder $query) => $query->where('played_at', '>=', $from->toDateTimeString()))
            ->where('played_at', '<', $to->toDateTimeString());
    }

    public function artists(?CarbonImmutable $from, CarbonImmutable $to, ?int $limit = null): Collection
    {
        return $this->between($from, $to)
            ->select('artist_name')
            ->selectRaw('COUNT(*) as plays, COALESCE(SUM(ms_played), 0) as ms')
            ->groupBy('artist_name')
            ->orderByDesc('plays')
            ->orderBy('artist_name')
            ->when($limit, fn (Builder $query) => $query->limit($limit))
            ->get()
            ->keyBy('artist_name');
    }

    public function firstPlays(CarbonImmutable $before): Collection
    {
        return DB::table('plays')->where('played_at', '<', $before->toDateTimeString())
            ->select('artist_name')->selectRaw('MIN(played_at) as first_played_at')
            ->groupBy('artist_name')->pluck('first_played_at', 'artist_name');
    }

    public function tracks(?CarbonImmutable $from, CarbonImmutable $to, int $limit): Collection
    {
        return $this->between($from, $to)
            ->select('track_name', 'artist_name')
            ->selectRaw('COUNT(*) as plays, MAX(track_uri) as uri, MAX(ms_played) as longest')
            ->groupBy('track_name', 'artist_name')
            ->orderByDesc('plays')->orderBy('track_name')->orderBy('artist_name')
            ->limit($limit)->get();
    }

    public function albums(?CarbonImmutable $from, CarbonImmutable $to, int $limit): Collection
    {
        return $this->between($from, $to)->whereNotNull('album_name')
            ->select('album_name', 'artist_name')
            ->selectRaw('COUNT(*) as plays, MAX(track_uri) as uri')
            ->groupBy('album_name', 'artist_name')
            ->orderByDesc('plays')->orderBy('album_name')->orderBy('artist_name')
            ->limit($limit)->get();
    }

    /** @return array<string, int> */
    public function genres(Collection $artists, int $take = 10): array
    {
        $weighted = [];

        foreach ($artists->take($take) as $row) {
            foreach (Artist::enrich($row->artist_name)->genres ?? [] as $genre) {
                $weighted[$genre] = ($weighted[$genre] ?? 0) + (int) $row->plays;
            }
        }

        uksort($weighted, fn (string $a, string $b) => ($weighted[$b] <=> $weighted[$a]) ?: strcmp($a, $b));

        return $weighted;
    }

    /** @return array{hours: array<int, int>, days: array<int, int>} */
    public function clock(?CarbonImmutable $from, CarbonImmutable $to): array
    {
        $hours = array_fill(0, 24, 0);
        $days = array_fill(0, 7, 0);
        $timezone = config('app.display_timezone');

        foreach ($this->between($from, $to)
            ->selectRaw("strftime('%Y-%m-%d %H:00:00', played_at) as bucket, COUNT(*) as plays")
            ->groupBy('bucket')->get() as $row) {
            $local = CarbonImmutable::parse($row->bucket, 'UTC')->setTimezone($timezone);
            $hours[$local->hour] += (int) $row->plays;
            $days[$local->dayOfWeekIso - 1] += (int) $row->plays;
        }

        return compact('hours', 'days');
    }

    /** @return array{new: int, familiar: int} */
    public function familiar(?CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $cutoff): array
    {
        $firstPlays = $this->firstPlays($to);
        $new = 0;
        $familiar = 0;

        foreach ($this->artists($from, $to) as $row) {
            $first = $firstPlays->get($row->artist_name);
            if ($first && CarbonImmutable::parse($first, 'UTC') >= $cutoff) {
                $new += (int) $row->plays;
            } else {
                $familiar += (int) $row->plays;
            }
        }

        return compact('new', 'familiar');
    }

    /** @return array{counts: array<string, int>, unknown: int} */
    public function decades(Collection $tracks): array
    {
        $counts = [];
        $unknown = 0;

        foreach ($tracks as $row) {
            $year = $row->uri ? Track::enrich($row->uri)->release_year : null;
            if (! $year) {
                $unknown++;

                continue;
            }

            $decade = floor($year / 10) * 10;
            $label = $decade.'s';
            $counts[$label] = ($counts[$label] ?? 0) + (int) $row->plays;
        }

        ksort($counts);

        return compact('counts', 'unknown');
    }

    /** @return array<string, int> */
    public function lengths(Collection $tracks): array
    {
        $lengths = ['≤3m' => 0, '3–4m' => 0, '4–5m' => 0, '5–9m' => 0, '9m+' => 0];

        foreach ($tracks as $row) {
            $minutes = ((int) $row->longest) / 60000;
            $label = match (true) {
                $minutes <= 3 => '≤3m',
                $minutes <= 4 => '3–4m',
                $minutes <= 5 => '4–5m',
                $minutes <= 9 => '5–9m',
                default => '9m+',
            };
            $lengths[$label] += (int) $row->plays;
        }

        return $lengths;
    }

    /** @return array{plays: int, minutes: int, artists: int, songs: int} */
    public function totals(?CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = $this->between($from, $to);
        $row = $query->selectRaw('COUNT(*) as plays, COALESCE(SUM(ms_played), 0) as ms, COUNT(DISTINCT artist_name) as artists, COUNT(DISTINCT artist_name || char(31) || track_name) as songs')->first();

        return [
            'plays' => (int) $row->plays,
            'minutes' => (int) round($row->ms / 60000),
            'artists' => (int) $row->artists,
            'songs' => (int) $row->songs,
        ];
    }

    public function recent(int $limit = 50): Collection
    {
        return Play::query()->orderByDesc('played_at')->limit($limit)->get();
    }
}
