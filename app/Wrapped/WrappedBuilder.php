<?php

namespace App\Wrapped;

use App\Ai\Agents\WrapSentencePolisher;
use App\Enums\Period;
use App\Models\Artist;
use App\Models\Wrap;
use App\Services\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\FinishReason;

class WrappedBuilder
{
    private const MIN_CHANGE = 5;

    private const TOTAL_RATIO = 2;

    private const TOTAL_MIN_PLAYS = 20;

    private const TOP_ARTISTS = 10;

    private const CLASSIC_DAYS = 365;

    private const CLASSIC_SHARE = 0.5;

    private const MANY_NEW = 3;

    /** @var array<string, string> */
    private const MOODS = [
        'doom' => 'darker', 'sludge' => 'darker', 'black' => 'darker',
        'death' => 'darker', 'goth' => 'darker', 'drone' => 'darker',
        'thrash' => 'heavier', 'grind' => 'heavier', 'hardcore' => 'heavier',
        'ambient' => 'calmer', 'chill' => 'calmer', 'lo-fi' => 'calmer', 'acoustic' => 'calmer',
        'house' => 'upbeat', 'techno' => 'upbeat', 'disco' => 'upbeat', 'dance' => 'upbeat',
    ];

    public function __construct(private readonly Stats $stats) {}

    public function build(Period $period, CarbonImmutable $now): Wrap
    {
        $now = $now->utc();
        [$from, $to] = $period->window($now);
        $cards = [
            $this->biggestChange($period, $now),
            $this->newDiscovery($period, $now),
            $this->summary($period, $now),
        ];

        $localStart = $now->setTimezone(config('app.display_timezone'))->startOfDay();
        $dayStart = $localStart->utc();
        $dayEnd = $localStart->addDay()->utc();
        $wrap = Wrap::query()
            ->where('period', $period->value)
            ->where('ends_at', '>=', $dayStart)
            ->where('ends_at', '<', $dayEnd)
            ->first() ?? new Wrap;

        if (! $wrap->exists) {
            $wrap->accent = Wrap::randomAccent();
        }

        $wrap->period = $period;
        $wrap->starts_at = $from ?? $now;
        $wrap->ends_at = $to;
        $wrap->cards = $cards;
        $wrap->save();

        return $wrap;
    }

    /** @return array<string, string|null> */
    public function biggestChange(Period $period, CarbonImmutable $now): array
    {
        [$from, $to] = $period->window($now);
        [$baselineFrom, $baselineTo] = $period->baseline($now);
        $current = $this->stats->artists($from, $to);
        $baseline = $this->stats->artists($baselineFrom, $baselineTo);
        [$during, $before] = array_slice($this->phrases($period), 0, 2);
        $currentPlays = (int) $current->sum('plays');
        $baselinePlays = (int) $baseline->sum('plays');
        $currentMinutes = $this->minutes($current);
        $baselineMinutes = $this->minutes($baseline);

        if ($currentPlays === 0) {
            $body = $baselinePlays > 0
                ? 'Down from '.$this->formatNumber($baselinePlays).' '.$this->plural($baselinePlays, 'play').' '.$before.' – your headphones had a rest.'
                : 'Import your history or sync Spotify to see what changed.';

            return $this->card('biggest_change', 'No plays '.$during.'.', $body);
        }

        if ($baselinePlays === 0) {
            return $this->card(
                'biggest_change',
                'You played '.$this->formatNumber($currentMinutes).' minutes of music '.$during.'.',
                $this->formatNumber($currentPlays).' '.$this->plural($currentPlays, 'play').' across '.$this->formatNumber($current->count()).' '.$this->plural($current->count(), 'artist').' – nothing earlier to compare with yet.',
                $this->formatNumber($currentMinutes),
                'minutes',
            );
        }

        if (min($currentPlays, $baselinePlays) >= self::TOTAL_MIN_PLAYS
            && min($currentMinutes, $baselineMinutes) > 0
            && max($currentMinutes, $baselineMinutes) / min($currentMinutes, $baselineMinutes) >= self::TOTAL_RATIO) {
            return $this->totalChange($currentMinutes, $baselineMinutes, $during, $before);
        }

        $moves = [];
        foreach ($baseline as $name => $old) {
            $oldPlays = (int) $old->plays;
            $newPlays = (int) ($current->get($name)?->plays ?? 0);
            $delta = $newPlays - $oldPlays;
            if (abs($delta) >= self::MIN_CHANGE) {
                $moves[] = ['name' => (string) $name, 'old' => $oldPlays, 'new' => $newPlays, 'delta' => $delta];
            }
        }

        usort($moves, static fn (array $a, array $b): int => abs($b['delta']) <=> abs($a['delta'])
            ?: ($b['delta'] > 0) <=> ($a['delta'] > 0)
            ?: strcmp($a['name'], $b['name']));

        if ($moves !== []) {
            $move = $moves[0];
            $name = $move['name'];
            $delta = $move['delta'];
            if ($delta > 0) {
                $headline = $period === Period::AllTime
                    ? $name.' grew fastest in your all-time listening.'
                    : $name.' was your fastest-rising artist '.$during.'.';
                $body = $this->formatNumber($move['new']).' '.$this->plural($move['new'], 'play').', up from '.$this->formatNumber($move['old']).' '.$before.'.';
            } else {
                $headline = 'You took a break from '.$name.' '.$during.'.';
                $body = $this->formatNumber($move['new']).' '.$this->plural($move['new'], 'play').', down from '.$this->formatNumber($move['old']).' '.$before.'.';
            }

            return $this->card('biggest_change', $headline, $body,
                $this->signedNumber($delta), 'plays vs '.$before, Artist::enrich($name)->image_url);
        }

        return $this->totalChange($currentMinutes, $baselineMinutes, $during, $before);
    }

    /** @return array<string, string|null> */
    public function newDiscovery(Period $period, CarbonImmutable $now): array
    {
        [$from, $to] = $period->window($now);
        $cutoff = $period->baseline($now)[1];
        $current = $this->stats->artists($from, $to);
        $firstPlays = $this->stats->firstPlays($to);
        $since = $this->phrases($period)[3];
        $totalPlays = (int) $current->sum('plays');

        if ($totalPlays === 0) {
            return $this->card('new_discovery', 'No new artists '.$since.'.', 'Play some music or import your history to find discoveries.');
        }

        $earliest = $firstPlays->min();
        if ($earliest !== null && CarbonImmutable::parse($earliest)->greaterThanOrEqualTo($cutoff)) {
            $top = (string) $current->keys()->first();

            return $this->card('new_discovery', $top.' kicked off your listening history.',
                'Your history starts on '.$this->day(CarbonImmutable::parse($earliest), $period).', so every artist looks new. Import your full Spotify data for true discoveries.',
                null, null, Artist::enrich($top)->image_url);
        }

        $newArtists = $current->filter(static function (object $artist, string $name) use ($firstPlays, $cutoff): bool {
            $first = $firstPlays->get($name);

            return $first !== null && CarbonImmutable::parse($first)->greaterThanOrEqualTo($cutoff);
        });

        if ($newArtists->isEmpty()) {
            return $this->card('new_discovery', 'No new artists '.$since.'.',
                'You stuck with your favourites – all '.$this->formatNumber($totalPlays).' '.$this->plural($totalPlays, 'play').' came from '.$this->formatNumber($current->count()).' '.$this->plural($current->count(), 'artist').' you already knew.');
        }

        $chosen = $newArtists->sort(static function (object $a, object $b) use ($firstPlays): int {
            return (int) $b->plays <=> (int) $a->plays
                ?: strcmp((string) $firstPlays->get($a->artist_name), (string) $firstPlays->get($b->artist_name))
                ?: strcmp((string) $a->artist_name, (string) $b->artist_name);
        })->first();
        $name = (string) $chosen->artist_name;
        $plays = (int) $chosen->plays;
        $first = CarbonImmutable::parse($firstPlays->get($name));
        $track = $this->stats->between($from, $to)
            ->where('artist_name', $name)
            ->selectRaw('track_name, COUNT(*) as plays')
            ->groupBy('track_name')
            ->orderByDesc('plays')
            ->orderBy('track_name')
            ->first()?->track_name;

        if ($plays === 1) {
            $body = 'You first heard them on '.$this->day($first, $period).' with \''.$track.'\'.';
        } else {
            $otherDiscoveries = $newArtists->count() === 1
                ? 'They were your only new artist '.$since.'.'
                : 'One of '.$this->formatNumber($newArtists->count()).' new artists '.$since.'.';
            $body = $this->formatNumber($plays).' plays since you first heard them on '.$this->day($first, $period).', mostly \''.$track.'\'. '.$otherDiscoveries;
        }

        // ponytail: sync-only history can still over-report discoveries; import the full export first.
        return $this->card('new_discovery', 'You discovered '.$name.' '.$since.'.', $body,
            $this->formatNumber($plays), $this->plural($plays, 'play'), Artist::enrich($name)->image_url);
    }

    /** @return array<string, string|null> */
    public function summary(Period $period, CarbonImmutable $now): array
    {
        [$from, $to] = $period->window($now);
        [$baselineFrom, $cutoff] = $period->baseline($now);
        $artists = $this->stats->artists($from, $to);
        $plays = (int) $artists->sum('plays');
        $during = $this->phrases($period)[0];

        if ($plays === 0) {
            return $this->card('summary', 'Nothing played '.$during.'.', 'Import your history or connect Spotify to bring your story to life.');
        }

        $minutes = $this->minutes($artists);
        $top = $artists->first();
        $name = (string) $top->artist_name;
        $topGenre = array_key_first($this->stats->genres($artists, self::TOP_ARTISTS));
        $baselineGenre = array_key_first($this->stats->genres($this->stats->artists($baselineFrom, $cutoff), self::TOP_ARTISTS));
        $firstPlays = $this->stats->firstPlays($to);
        $firstOverall = $firstPlays->min();
        $hasHistory = $firstOverall !== null && CarbonImmutable::parse($firstOverall)->lessThan($cutoff);
        $newArtists = 0;
        $classicPlays = 0;

        foreach ($artists as $artistName => $artist) {
            $first = $firstPlays->get($artistName);
            if ($first === null) {
                continue;
            }
            $first = CarbonImmutable::parse($first);
            if ($hasHistory && $first->greaterThanOrEqualTo($cutoff)) {
                $newArtists++;
            }
            if ($first->lessThan($cutoff->subDays(self::CLASSIC_DAYS))) {
                $classicPlays += (int) $artist->plays;
            }
        }

        $opener = $this->phrases($period)[2];
        $story = $topGenre === null
            ? 'you kept coming back to '.$name
            : ($topGenre === $baselineGenre ? 'you stuck with ' : 'you explored ').$this->mood($topGenre);
        $ending = match (true) {
            $classicPlays / $plays >= self::CLASSIC_SHARE => ' while revisiting classic favourites',
            $newArtists >= self::MANY_NEW => ' and found '.$this->formatNumber($newArtists).' new artists',
            $topGenre !== null => ' with '.$name.' on repeat',
            default => '',
        };
        $headline = $opener.' '.$story.$ending.'.';
        $facts = compact('minutes', 'plays', 'name', 'topGenre', 'baselineGenre', 'newArtists', 'classicPlays');
        $headline = $this->polish($headline, $facts);
        $body = $this->formatNumber($minutes).' minutes, '.$this->formatNumber($plays).' '.$this->plural($plays, 'play').' and '.$this->formatNumber($artists->count()).' '.$this->plural($artists->count(), 'artist').'. '.$name.' led the way with '.$this->formatNumber((int) $top->plays).' '.$this->plural((int) $top->plays, 'play').'.';

        return $this->card('summary', $headline, $body, $this->formatNumber($minutes), 'minutes', Artist::enrich($name)->image_url);
    }

    /** @return array<string, string|null> */
    private function totalChange(int $current, int $baseline, string $during, string $before): array
    {
        $percent = $baseline > 0 ? (int) round(($current - $baseline) / $baseline * 100) : 0;
        $direction = $percent >= 0 ? 'more' : 'less';
        $change = $this->signedNumber($percent).'%';

        return $this->card('biggest_change', 'You listened '.$this->formatNumber(abs($percent)).'% '.$direction.' '.$during.'.',
            $this->formatNumber($current).' minutes, '.($percent >= 0 ? 'up' : 'down').' from '.$this->formatNumber($baseline).' '.$before.'.', $change, 'listening time');
    }

    /** @param Collection<int|string, object> $artists */
    private function minutes(Collection $artists): int
    {
        return (int) round($artists->sum('ms') / 60000);
    }

    /** @return array{string, string, string, string} */
    private function phrases(Period $period): array
    {
        return match ($period) {
            Period::Week => ['this week', 'last week', 'This week', 'this week'],
            Period::Month => ['this month', 'the month before', 'This month', 'this month'],
            Period::HalfYear => ['in the last 6 months', 'the 6 months before', 'In the last 6 months', 'in the last 6 months'],
            Period::Year => ['this year', 'the year before', 'This year', 'this year'],
            Period::AllTime => ['so far', 'a year ago', 'Over the years', 'in the last year'],
        };
    }

    private function day(CarbonImmutable $date, Period $period): string
    {
        $format = in_array($period, [Period::Week, Period::Month], true) ? 'l j F' : 'j F Y';

        return $date->setTimezone(config('app.display_timezone'))->format($format);
    }

    private function plural(int $count, string $word): string
    {
        return $count === 1 ? $word : $word.'s';
    }

    private function formatNumber(int $value): string
    {
        return number_format($value);
    }

    private function signedNumber(int $value): string
    {
        return ($value > 0 ? '+' : ($value < 0 ? '-' : '')).$this->formatNumber(abs($value));
    }

    private function mood(string $genre): string
    {
        // ponytail: broad genre-word mapping stands in for unavailable audio features.
        foreach (self::MOODS as $word => $adjective) {
            if (str_contains(mb_strtolower($genre), $word)) {
                $rest = trim((string) preg_replace('/\b'.preg_quote($word, '/').'\b\s*/iu', '', $genre, 1));

                return $adjective.' '.($rest !== '' ? $rest : 'music');
            }
        }

        return $genre;
    }

    /** @param array<string, int|string|null> $facts */
    private function polish(string $sentence, array $facts): string
    {
        $key = config('ai.providers.anthropic.key');
        if (! is_string($key) || $key === '') {
            return $sentence;
        }

        return rescue(function () use ($sentence, $facts): string {
            $response = WrapSentencePolisher::make()->prompt(
                json_encode(['draft' => $sentence, 'facts' => $facts], JSON_THROW_ON_ERROR),
                model: config('ai.wrap_model'),
            );
            $text = $response->text;
            $finishReason = $response->steps->last()?->finishReason;
            if (($finishReason !== null && $finishReason !== FinishReason::Stop)
                || trim($text) === '' || str_contains($text, "\n") || str_word_count($text) > 30) {
                return $sentence;
            }

            return trim($text);
        }, $sentence);
    }

    /** @return array{type: string, headline: string, body: string, stat: ?string, stat_label: ?string, image_url: ?string, theme: string} */
    private function card(string $type, string $headline, string $body, ?string $stat = null, ?string $statLabel = null, ?string $imageUrl = null): array
    {
        return [
            'type' => $type,
            'headline' => $headline,
            'body' => $body,
            'stat' => $stat,
            'stat_label' => $statLabel,
            'image_url' => $imageUrl,
            'theme' => match ($type) {
                'biggest_change' => 'ember',
                'new_discovery' => 'violet',
                default => 'gold',
            },
        ];
    }
}
