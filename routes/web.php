<?php

use App\Http\Controllers\ConfigureController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\SpotifyController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\WrapArtworkController;
use App\Http\Controllers\WrapController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', [WrapController::class, 'home'])->name('home');
Route::get('/configure', ConfigureController::class)->name('configure');
Route::get('/guide', fn () => view('guide', ['content' => Str::markdown(file_get_contents(base_path('README.md')))]))->name('guide');
Route::get('/docs/{filename}', fn (string $filename) => response()->file(base_path('docs/'.$filename)))
    ->whereIn('filename', ['wrap-card-1.png', 'stats.png'])->name('guide.image');
Route::post('/wraps', [WrapController::class, 'store'])->name('wraps.store');
Route::get('/wraps', [WrapController::class, 'index'])->name('wraps.index');
Route::get('/wraps/{wrap}', [WrapController::class, 'show'])->name('wraps.show');
Route::get('/wraps/{wrap}/cards/{card}/artwork', WrapArtworkController::class)
    ->whereNumber('card')->name('wraps.cards.artwork');
Route::delete('/wraps/{wrap}', [WrapController::class, 'destroy'])->name('wraps.destroy');

Route::get('/spotify/connect', [SpotifyController::class, 'connect'])->name('spotify.connect');
Route::get('/spotify/callback', [SpotifyController::class, 'callback'])->name('spotify.callback');
Route::post('/spotify/sync', [SpotifyController::class, 'sync'])->name('spotify.sync');
Route::post('/import', ImportController::class)->name('import');

Route::get('/stats/{tab?}', StatsController::class)
    ->whereIn('tab', ['overview', 'artists', 'songs', 'albums', 'genres', 'insights', 'recent', 'playlists'])
    ->name('stats');
