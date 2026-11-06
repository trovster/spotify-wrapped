<?php

namespace App\Http\Controllers;

use App\Models\Play;
use App\Models\Setting;
use App\Services\Spotify;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\View\View;

class ConfigureController extends Controller
{
    public function __invoke(Spotify $spotify): View
    {
        $connected = $spotify->connected();
        $lastSynced = Setting::read('last_synced_at');
        $lastSyncedAt = $lastSynced ? CarbonImmutable::parse($lastSynced) : null;

        if ($connected && ($lastSyncedAt === null || $lastSyncedAt->lessThan(CarbonImmutable::now('UTC')->subMinutes(15)))) {
            try {
                $spotify->sync();
                $lastSyncedAt = CarbonImmutable::now('UTC');
            } catch (\RuntimeException|ConnectionException $exception) {
                report($exception);
                session()->now('warning', 'Could not sync with Spotify: '.$exception->getMessage());
            }
        }

        $connected = $spotify->connected();
        $first = Play::query()->min('played_at');
        $last = Play::query()->max('played_at');

        return view('configure', [
            'configured' => $spotify->configured(),
            'connected' => $connected,
            'profileName' => $connected ? (Setting::read('spotify_name') ?: 'Spotify listener') : null,
            'profileImageUrl' => $connected ? Setting::read('spotify_image_url') : null,
            'lastSyncedAt' => $lastSyncedAt,
            'firstPlayedAt' => $first ? CarbonImmutable::parse($first, 'UTC') : null,
            'lastPlayedAt' => $last ? CarbonImmutable::parse($last, 'UTC') : null,
            'playCount' => Play::query()->count(),
            'importPath' => base_path('import'),
            'redirectUri' => (string) config('services.spotify.redirect'),
        ]);
    }
}
