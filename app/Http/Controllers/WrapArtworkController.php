<?php

namespace App\Http\Controllers;

use App\Models\Wrap;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class WrapArtworkController extends Controller
{
    public function __invoke(Wrap $wrap, int $card): Response
    {
        $imageUrl = $wrap->cards[$card]['image_url'] ?? null;

        abort_unless(is_string($imageUrl) && $this->isSpotifyArtworkUrl($imageUrl), 404);

        try {
            $artwork = Http::withoutRedirecting()->timeout(8)->get($imageUrl);
        } catch (ConnectionException) {
            abort(404);
        }

        $contentType = strtolower(trim(explode(';', $artwork->header('Content-Type') ?? '')[0]));
        $body = $artwork->body();

        abort_unless($artwork->successful()
            && in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)
            && strlen($body) <= 5 * 1024 * 1024, 404);

        return response($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isSpotifyArtworkUrl(string $imageUrl): bool
    {
        $parts = parse_url($imageUrl);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower($parts['host'] ?? ''), ['i.scdn.co', 'image-cdn-ak.spotifycdn.com'], true)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && preg_match('/\A\/image\/[A-Za-z0-9]+\z/', $parts['path'] ?? '') === 1;
    }
}
