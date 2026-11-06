<?php

namespace Tests\Feature;

use App\Models\Play;
use App\Services\HistoryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_extended_history_skips_podcasts_and_short_plays_and_reimport_is_idempotent(): void
    {
        $importer = app(HistoryImporter::class);
        $result = $importer->import(base_path('tests/fixtures/extended'));

        $this->assertSame(1, $result['files']);
        $this->assertSame(5, $result['read']);
        $this->assertSame(3, $result['imported']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(3, Play::count());
        $this->assertSame(0, $importer->import(base_path('tests/fixtures/extended'))['imported']);
    }

    public function test_extended_history_replaces_overlapping_api_and_basic_rows(): void
    {
        DB::table('plays')->insert([
            ['played_at' => '2026-11-01 10:00:00', 'ms_played' => 210000, 'track_name' => 'Electric Worry', 'artist_name' => 'Clutch', 'album_name' => null, 'track_uri' => null, 'source' => 'export'],
            ['played_at' => '2026-11-02 12:00:00', 'ms_played' => 190000, 'track_name' => 'The Mob Rules', 'artist_name' => 'Black Sabbath', 'album_name' => null, 'track_uri' => 'spotify:track:sabbath1', 'source' => 'api'],
            ['played_at' => '2026-11-03 12:00:00', 'ms_played' => 190000, 'track_name' => 'Later API', 'artist_name' => 'Clutch', 'album_name' => null, 'track_uri' => 'spotify:track:later', 'source' => 'api'],
        ]);

        app(HistoryImporter::class)->import(base_path('tests/fixtures/extended'));

        $this->assertSame(4, Play::count());
        $this->assertSame('spotify:track:clutch1', Play::where('track_name', 'Electric Worry')->value('track_uri'));
        $this->assertSame('export', Play::where('track_name', 'The Mob Rules')->value('source'));
        $this->assertDatabaseHas('plays', ['track_name' => 'Later API', 'source' => 'api']);
    }

    public function test_basic_history_after_extended_history_uses_utc_seconds_and_extended_precedence(): void
    {
        $importer = app(HistoryImporter::class);
        $importer->import(base_path('tests/fixtures/extended'));
        $result = $importer->import(base_path('tests/fixtures/basic'));

        $this->assertSame(1, $result['imported']);
        $this->assertSame('2026-11-03 14:15:00', Play::where('track_name', 'Twist of Cain')->first()->played_at->format('Y-m-d H:i:s'));
        $this->assertNull(Play::where('track_name', 'Twist of Cain')->value('track_uri'));
        $this->assertDatabaseMissing('plays', ['track_name' => 'Old song']);
    }

    public function test_extended_row_without_uri_remains_idempotent_and_replaces_basic_metadata(): void
    {
        $dir = sys_get_temp_dir().'/wrapped-null-uri-'.uniqid();
        mkdir($dir);
        $path = $dir.'/Streaming_History_Audio_null.json';
        file_put_contents($path, json_encode([[
            'ts' => '2026-11-01T10:00:00Z',
            'ms_played' => 180000,
            'master_metadata_track_name' => 'Unknown URI',
            'master_metadata_album_artist_name' => 'Clutch',
            'master_metadata_album_album_name' => 'Album from extended export',
            'spotify_track_uri' => null,
        ]], JSON_THROW_ON_ERROR));

        try {
            Play::create(['played_at' => '2026-11-01 10:00:00', 'ms_played' => 180000, 'track_name' => 'Unknown URI', 'artist_name' => 'Clutch', 'source' => 'export']);
            $importer = app(HistoryImporter::class);
            $this->assertSame(1, $importer->import($dir)['imported']);
            $this->assertSame('Album from extended export', Play::first()->album_name);
            $this->assertSame(0, $importer->import($dir)['imported']);
            $this->assertSame(1, Play::count());
        } finally {
            unlink($path);
            rmdir($dir);
        }
    }

    public function test_no_files_and_invalid_json_have_useful_messages(): void
    {
        $importer = app(HistoryImporter::class);
        $this->assertStringContainsString('Streaming_History_Audio_', $importer->import('/nonexistent/history')['message']);

        $dir = sys_get_temp_dir().'/wrapped-invalid-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/Streaming_History_Audio_bad.json', '{');
        try {
            $importer->import($dir);
            $this->fail('Expected invalid JSON to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Streaming_History_Audio_bad.json', $exception->getMessage());
        } finally {
            unlink($dir.'/Streaming_History_Audio_bad.json');
            rmdir($dir);
        }
    }

    public function test_import_command_uses_the_same_importer(): void
    {
        $this->artisan('wrapped:import', ['path' => base_path('tests/fixtures/extended')])->assertSuccessful();
        $this->assertSame(3, Play::count());

        $this->artisan('wrapped:import', ['path' => base_path('tests/fixtures/extended')])->assertSuccessful();
        $this->assertSame(3, Play::count());
    }
}
