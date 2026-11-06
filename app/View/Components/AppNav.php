<?php

namespace App\View\Components;

use App\Models\Setting;
use App\Services\Spotify;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class AppNav extends Component
{
    public bool $connected;

    public string $spotifyUrl;

    public function __construct(Spotify $spotify)
    {
        $this->connected = $spotify->connected();

        $spotifyUserId = Setting::read('spotify_user_id');

        $this->spotifyUrl = $spotifyUserId
            ? 'https://open.spotify.com/user/'.rawurlencode($spotifyUserId)
            : 'https://open.spotify.com/';
    }

    public function render(): View
    {
        return view('components.app-nav');
    }
}
