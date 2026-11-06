<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plays', function (Blueprint $table) {
            $table->id();
            $table->dateTime('played_at')->index();
            $table->unsignedInteger('ms_played')->nullable();
            $table->string('track_name');
            $table->string('artist_name');
            $table->string('album_name')->nullable();
            $table->string('track_uri')->nullable();
            $table->string('source', 10);
            $table->unique(['artist_name', 'track_name', 'played_at']);
            $table->index(['artist_name', 'played_at']);
        });

        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('spotify_id')->nullable();
            $table->text('image_url')->nullable();
            $table->json('genres')->nullable();
            $table->dateTime('fetched_at')->nullable();
        });

        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('uri')->unique();
            $table->json('artists')->nullable();
            $table->text('album_image_url')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->dateTime('fetched_at')->nullable();
        });

        Schema::create('wraps', function (Blueprint $table) {
            $table->id();
            $table->string('period')->index();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at');
            $table->json('cards');
            $table->string('accent')->default('violet');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('wraps');
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('artists');
        Schema::dropIfExists('plays');
    }
};
