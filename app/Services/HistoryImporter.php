<?php

namespace App\Services;

use App\Models\Play;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class HistoryImporter
{
    /** @return array{files: int, read: int, imported: int, skipped: int, from: ?string, to: ?string, message: string} */
    public function import(string $dir): array
    {
        // ponytail: whole-file json_decode (~12MB/file); use a streaming parser if Spotify ships larger files.
        ini_set('memory_limit', '1G');

        $files = $this->files($dir);
        if ($files === []) {
            return [
                'files' => 0, 'read' => 0, 'imported' => 0, 'skipped' => 0, 'from' => null, 'to' => null,
                'message' => "No Spotify history files found in {$dir}. Add Streaming_History_Audio_*.json or StreamingHistory_music_*.json there.",
            ];
        }

        $read = 0;
        $imported = 0;
        $from = null;
        $to = null;

        foreach ($files as [$path, $type]) {
            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || ! array_is_list($decoded)) {
                    throw new RuntimeException('Expected a JSON list of plays.');
                }

                $read += count($decoded);
                $rows = [];
                $lastExtended = $type === 'basic' ? Play::query()->where('source', 'export')->whereNotNull('track_uri')->max('played_at') : null;
                foreach ($decoded as $entry) {
                    $row = is_array($entry) ? $this->map($entry, $type) : null;
                    if ($row === null || ($lastExtended !== null && $row['played_at'] <= $lastExtended)) {
                        continue;
                    }

                    $rows[] = $row;
                }

                if ($rows === []) {
                    continue;
                }

                $dates = array_column($rows, 'played_at');
                $min = min($dates);
                $max = max($dates);
                $from = $from === null ? $min : min($from, $min);
                $to = $to === null ? $max : max($to, $max);

                $imported += DB::transaction(function () use ($rows, $type, $min, $max): int {
                    if ($type === 'extended') {
                        Play::query()->whereBetween('played_at', [$min, $max])->where('source', 'api')->delete();

                        $incoming = [];
                        foreach ($rows as $row) {
                            $incoming[$this->key($row)] ??= $row;
                        }

                        $replaceIds = [];
                        foreach (Play::query()->whereBetween('played_at', [$min, $max])->where('source', 'export')->whereNull('track_uri')->get() as $existing) {
                            $replacement = $incoming[$this->key($existing->getAttributes())] ?? null;
                            if ($replacement !== null
                                && (int) $existing->ms_played === $replacement['ms_played']
                                && $existing->album_name === $replacement['album_name']
                                && $existing->track_uri === $replacement['track_uri']) {
                                continue;
                            }
                            $replaceIds[] = $existing->id;
                        }
                        foreach (array_chunk($replaceIds, 500) as $ids) {
                            Play::query()->whereIn('id', $ids)->delete();
                        }
                    } else {
                        Play::query()->whereBetween('played_at', [$min, $max])->where('source', 'api')->delete();
                    }

                    $inserted = 0;
                    foreach (array_chunk($rows, 500) as $chunk) {
                        $inserted += DB::table('plays')->insertOrIgnore($chunk);
                    }

                    return $inserted;
                });
            } catch (Throwable $exception) {
                throw new RuntimeException("Could not import {$path}: {$exception->getMessage()}", previous: $exception);
            }
        }

        $skipped = $read - $imported;
        $range = $from === null ? '' : ' ('.CarbonImmutable::parse($from, 'UTC')->setTimezone(config('app.display_timezone'))->format('j M Y').' – '.CarbonImmutable::parse($to, 'UTC')->setTimezone(config('app.display_timezone'))->format('j M Y').')';
        $message = 'Read '.number_format($read).' rows from '.count($files).' files. Imported '.number_format($imported).' new plays'.$range.'. Skipped '.number_format($skipped).' (podcasts, plays under 30 seconds, or already imported).';

        return compact('read', 'imported', 'skipped', 'from', 'to') + ['files' => count($files), 'message' => $message];
    }

    /** @param array<string, mixed> $row */
    private function key(array $row): string
    {
        return implode("\x1F", [$row['artist_name'], $row['track_name'], $row['played_at']]);
    }

    /** @return list<array{string, string}> */
    private function files(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $name = $file->getFilename();
            $type = match (true) {
                (bool) preg_match('/^Streaming_History_Audio_.*\.json$/', $name) => 'extended',
                (bool) preg_match('/^StreamingHistory_music_.*\.json$/', $name) => 'basic',
                (bool) preg_match('/^StreamingHistory.*\.json$/', $name) => 'basic',
                default => null,
            };
            if ($type !== null) {
                $found[] = [$file->getPathname(), $type];
            }
        }

        usort($found, fn (array $a, array $b): int => ($a[1] === $b[1] ? 0 : ($a[1] === 'extended' ? -1 : 1)) ?: strcmp($a[0], $b[0]));

        return $found;
    }

    /** @param array<string, mixed> $entry
     * @return ?array{played_at: string, ms_played: int, track_name: string, artist_name: string, album_name: ?string, track_uri: ?string, source: string}
     */
    private function map(array $entry, string $type): ?array
    {
        $ms = (int) ($entry[$type === 'extended' ? 'ms_played' : 'msPlayed'] ?? 0);
        $track = trim((string) ($entry[$type === 'extended' ? 'master_metadata_track_name' : 'trackName'] ?? ''));
        $artist = trim((string) ($entry[$type === 'extended' ? 'master_metadata_album_artist_name' : 'artistName'] ?? ''));
        $date = $entry[$type === 'extended' ? 'ts' : 'endTime'] ?? null;
        if ($ms < Play::MIN_MS || $track === '' || $artist === '' || ! is_string($date) || $date === '') {
            return null;
        }

        return [
            'played_at' => CarbonImmutable::parse($date, 'UTC')->utc()->toDateTimeString(),
            'ms_played' => $ms,
            'track_name' => $track,
            'artist_name' => $artist,
            'album_name' => $type === 'extended' ? ($entry['master_metadata_album_album_name'] ?? null) : null,
            'track_uri' => $type === 'extended' ? ($entry['spotify_track_uri'] ?? null) : null,
            'source' => 'export',
        ];
    }
}
