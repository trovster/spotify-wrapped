<?php

namespace App\Http\Controllers;

use App\Services\Spotify;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class SpotifyController extends Controller
{
    public function connect(Request $request, Spotify $spotify): RedirectResponse
    {
        if (! $spotify->configured()) {
            return redirect()->route('configure')->with('warning', 'Add your Spotify Client ID and Client Secret to .env first.');
        }

        $state = Str::random(40);
        $request->session()->put('spotify_oauth_state', $state);

        return redirect()->away($spotify->authorizeUrl($state));
    }

    public function callback(Request $request, Spotify $spotify): RedirectResponse
    {
        if ($request->query('error')) {
            $request->session()->forget('spotify_oauth_state');

            return redirect()->route('configure')->with('warning', 'Spotify connection was cancelled: '.$request->query('error').'.');
        }

        $expected = $request->session()->pull('spotify_oauth_state');
        $actual = $request->query('state');
        if (! is_string($expected) || ! is_string($actual) || ! hash_equals($expected, $actual)) {
            return redirect()->route('configure')->with('warning', 'Spotify sign-in could not be verified. Use the same address as your redirect URI ('.config('services.spotify.redirect').').');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect()->route('configure')->with('warning', 'Spotify did not return a sign-in code. Click Connect Spotify again.');
        }

        try {
            $profile = $spotify->connect($code);

            return redirect()->route('configure')->with('status', 'Connected to Spotify as '.($profile['display_name'] ?? $profile['id'] ?? 'your account').'.');
        } catch (RuntimeException|ConnectionException $exception) {
            report($exception);

            return redirect()->route('configure')->with('warning', $exception->getMessage());
        }
    }

    public function sync(Spotify $spotify): RedirectResponse
    {
        if (! $spotify->connected()) {
            return redirect()->route('configure')->with('warning', 'Spotify is not connected. Click Connect Spotify.');
        }

        try {
            $count = $spotify->sync();

            return redirect()->route('configure')->with('status', "Synced {$count} new plays.");
        } catch (RuntimeException|ConnectionException $exception) {
            report($exception);

            return redirect()->route('configure')->with('warning', $exception->getMessage());
        }
    }
}
