<?php

use App\Enums\Period;
use App\Services\HistoryImporter;
use App\Services\Spotify;
use App\Wrapped\WrappedBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('wrapped:import {path?}', function (HistoryImporter $importer) {
    try {
        $result = $importer->import($this->argument('path') ?: base_path('import'));
        $this->info($result['message']);

        return $result['files'] ? 0 : 1;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Import your Spotify listening history');

Artisan::command('wrapped:sync', function (Spotify $spotify) {
    if (! $spotify->connected()) {
        $this->warn('Connect Spotify in the app first.');

        return 1;
    }

    try {
        $count = $spotify->sync();
        $this->info("Synced {$count} new plays.");

        return 0;
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return 1;
    }
})->purpose('Fetch your latest Spotify plays');

Artisan::command('wrapped:generate {period=week}', function (WrappedBuilder $builder) {
    $period = Period::tryFrom((string) $this->argument('period'));
    if (! $period) {
        $this->error('Unknown period. Use week, month, half-year, year or all-time.');

        return 1;
    }

    $wrap = $builder->build($period, CarbonImmutable::now('UTC'));
    foreach ($wrap->cards as $card) {
        $this->line($card['headline']);
    }
    $this->info(route('wraps.show', $wrap));

    return 0;
})->purpose('Generate a Wrapped for a listening period');

Schedule::command('wrapped:sync')->hourly();
Schedule::command('wrapped:generate week')->weeklyOn(1, '07:00')->timezone(config('app.display_timezone'));
