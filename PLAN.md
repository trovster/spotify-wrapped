# Plan: Spotify Wrapped (single-user, weekly) — Epic implementation

## Context

The repo is empty apart from `task.md` and five design images. The goal is a
**single-user** Laravel app that turns your Spotify listening history into a
regularly refreshed, swipeable "Wrapped". It works over rolling periods (1 week,
1 month, 6 months, 1 year, all time); the MVP is the weekly Wrapped.

History comes from two places:
- a one-off **Spotify data export**, for your full lifetime history;
- the **Spotify Web API**, which keeps recent plays up to date.

A **non-developer** must be able to install it by following the README exactly.
The MVP outputs 3 cards in this order: **Biggest Change → New Discovery → Weekly
Summary**.

Alongside the Wrapped there is a **stats dashboard** (Story 6).
- **Overview:** top artists, songs, albums and genres.
- **Insights:** charts about how you listen.
- **Recent plays.**

It works for any period. The project targets **PHP 8.5**.

Decisions made with you:
- **Where it runs:** locally on a Mac via Laravel Herd. There is no login.
- **Summary card:** written from rule-based templates. If `ANTHROPIC_API_KEY` is
  set, Claude can rewrite the sentence.

How this plan was made: three sub-agents each planned part of the epic (Story 1
plus the data foundation, Stories 2–4, and Story 5) against one shared contract,
which I set and then checked their plans against (see "Integration decisions").
Story 6 (the dashboard) was added afterwards. I designed it directly against the
same contract, reusing the foundation and card queries rather than duplicating
them.

---

## Verified external constraints (Nov 2026)

| Constraint | Design consequence |
|---|---|
| Since Feb 2026, the **owner** of a Development Mode app needs **Spotify Premium**. Each app allows at most 5 users. | Listed as a README prerequisite. A lapsed subscription causes a 403, which gets a readable message. |
| **Batch** `GET /tracks` and `GET /artists` were removed. Single `GET /tracks/{id}` and `GET /artists/{id}` remain. | Artist details (genres, image) are fetched lazily and one at a time, only for the ~10 artists a Wrapped needs. Results are cached forever. |
| Artist/track `popularity` and `followers` were removed. Audio features (valence/energy) are unavailable to new apps. | Mood words such as "darker" have to come from **genre names**. On the dashboard, volt.fm's mood sliders, "By Popularity" chart and follower count **can't be rebuilt**, so the Insights tab uses data we do have instead (Story 6). |
| A recently-played item contains the full track object: `duration_ms`, album images and `album.release_date`. | Sync stores track details **at no extra API cost**, and API plays get a real duration. |
| `GET /me/player/recently-played` returns only the **last 50 plays**, with no `ms_played`. | Sync on page visit (at most every 15 minutes) and hourly through the optional scheduler. The README explains the gap. A fresh export fills it. |
| Redirect URIs must be **HTTPS or `http://127.0.0.1`**. `localhost` is rejected and the URI must match exactly. | Use Herd **Secure**: `https://spotify-wrapped.test/spotify/callback`. Fallback for developers: `http://127.0.0.1:8000/spotify/callback`. |
| The export is **Extended streaming history** (`Streaming_History_Audio_*.json`, up to 30 days to arrive) or **Account data** (`StreamingHistory_music_*.json`, about 5 days, last year only, no URIs). | Import both. Precedence when they overlap: **extended > basic > api**. |

## Technology

| Layer | Choice | Why |
|---|---|---|
| Language | **PHP 8.5** (`"php": "^8.5"` in `composer.json`) | Current release; Laravel 13 supports 8.3–8.5. `array_first()`/`array_last()` and the pipe operator `\|>` are available, but use them only where they read more simply; don't force them. |
| Framework | **Laravel 13** | You know it. It gives migrations, the scheduler, the HTTP client, encrypted casts and tests out of the box. |
| Database | **SQLite**, one file at `database/database.sqlite` | No server. Backup is copying one file. It handles hundreds of thousands of plays easily. |
| Local server | **Laravel Herd** (free) | One installer gives PHP and Composer and serves `https://<folder>.test`. Your own Flock setup works identically. |
| Front end | Blade, `public/css/app.css` and `public/js/wrapped.js` (~45 lines) | **No Node, npm or Vite**, so setup is `composer run setup` and nothing else. |
| Swipe | **CSS scroll-snap** | Native touch swipe. JS only adds buttons, keys, tap zones and the counter. |
| Dashboard charts | **CSS bars** (`width: calc(var(--v) * 1%)`) inside `<figure>` and `<ol>` | No chart library and no JS. Labels and values are real text, so screen readers get them. Load the `dataviz` skill when building them. |
| Spotify | Laravel `Http` client and a hand-rolled Authorization Code flow | No Socialite: one user, four endpoints. |
| Optional AI | Official `anthropic-ai/sdk`, `claude-opus-5-5` at `effort: low` | Used only when a key is set. Any failure or refusal falls back to the template. |
| Tests | PHPUnit (skeleton default), `Http::fake`, `Http::preventStrayRequests`, JSON fixtures | — |
| Background | None (`QUEUE_CONNECTION=sync`, file session and cache) | "Lazy mode" does sync and weekly generation when you open the app. The scheduler is optional. |

## What you need to supply

| Item | Where from | Goes in |
|---|---|---|
| Spotify account **with Premium** | — | Required to own the developer app |
| **Data export** (Extended streaming history, plus Account data for a quicker start) | spotify.com/account/privacy | Unzipped JSON goes in `import/` |
| **Client ID** and **Client Secret** | developer.spotify.com/dashboard → Create app → Settings | `.env` `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` |
| **Redirect URI**, typed identically in both places | `https://spotify-wrapped.test/spotify/callback` | Dashboard, and `.env` `SPOTIFY_REDIRECT_URI` |
| **Timezone** | e.g. `Europe/London` | `.env` `WRAPPED_TIMEZONE` |
| Your Spotify email (only if Spotify says "user not registered") | spotify.com → Account | Dashboard → User Management |
| *Optional:* Anthropic API key, and a model | console.anthropic.com | `.env` `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` |

Your **Spotify username and password are not needed**. You sign in on Spotify's
own page, and your name and avatar come from `GET /me`.

---

## Architecture

```
import/*.json ──wrapped:import / POST /import──▶ HistoryImporter ─┐
Spotify API ──wrapped:sync / lazy on GET / ──▶ Spotify::sync() ───┼─▶ plays (SQLite)
                                                                  │
         (sync also upserts tracks: cover, year, duration) ───────┤
GET /tracks/{id}, /artists/{id} (lazy, cached) ◀── Track::enrich() / Artist::enrich()
                                                                  ▼
                         Stats (shared queries: artists, tracks, albums, genres, clock…)
                           │                                     │
POST /wraps | wrapped:generate | lazy weekly | Mon 07:00         │
   ─▶ WrappedBuilder::build() ─▶ 3 cards [+ optional Claude]     │
   ─▶ wraps.cards (JSON) ─▶ GET /wraps/{id}: scroll-snap deck    │
                                                                 ▼
                      GET /stats/{tab?}?period=: volt.fm-style dashboard
```

### Story → files

| Story | Files |
|---|---|
| Foundation | `database/migrations/0001_01_01_000000_create_wrapped_tables.php`, `app/Models/{Play,Artist,Track,Wrap,Setting}.php`, `app/Services/Stats.php` (queries shared by the cards and the dashboard), `app/Enums/Period.php`, `app/Services/HistoryImporter.php`, `app/Services/Spotify.php`, `app/Http/Controllers/{SpotifyController,ImportController}.php`, `config/{app,services,database}.php`, `.env.example`, `composer.json`, `phpunit.xml` |
| 1 Generate Wrapped | `app/Wrapped/WrappedBuilder.php` (`build()`), `app/Http/Controllers/WrapController.php`, `routes/web.php`, `routes/console.php` (commands and schedule) |
| 2 Biggest Change, 3 New Discovery, 4 Weekly Summary | `app/Wrapped/WrappedBuilder.php` (three public methods, private helpers, `polish()`) |
| 5 Presentation | `resources/views/{layout,home}.blade.php`, `resources/views/wraps/show.blade.php`, `public/css/app.css`, `public/js/wrapped.js` |
| 6 Stats dashboard | `app/Http/Controllers/StatsController.php` (invokable), `resources/views/stats.blade.php`, `resources/views/stats/list.blade.php` (one partial, used by the Overview and the "See all" tabs), plus a dashboard section in `public/css/app.css` |
| Docs | `README.md`, `docs/designs/*` (the images moved here), `docs/*.png` (screenshots) |
| Tests | `tests/Feature/{ImportTest,SpotifyTest,WrapTest,WrappedCardsTest,PresentationTest,StatsTest}.php`, `tests/fixtures/{extended,basic}/*.json` |

---

## Shared contracts (all slices code against these)

**Tables**, in one migration:
- `plays`
  - `id`
  - `played_at`: datetime, UTC `Y-m-d H:i:s`, indexed
  - `ms_played`: int, nullable. API plays store the track's `duration_ms` (`//
    ponytail: assumes the whole track was played`).
  - `track_name`, `artist_name`
  - `album_name`: nullable
  - `track_uri`: nullable
  - `source`: `'export'` or `'api'`
  - `UNIQUE(artist_name, track_name, played_at)`, `INDEX(artist_name,
    played_at)`
  - Only counted plays are stored: `ms_played >= 30000` (Spotify's own
    threshold), or any API play. Podcasts and audiobooks are skipped. So every
    row is one play.
- `artists`: `id`, `name` (unique), `spotify_id`, `image_url`, `genres` (JSON),
  `fetched_at`
- `tracks`: `id`, `uri` (unique), `artists` (JSON list of `{id, name}`),
  `album_image_url`, `release_year` (small int), `duration_ms`, `fetched_at`
  - Filled for free by sync, and lazily by `Track::enrich()` for export plays.
  - It holds the covers, years and durations for the dashboard, and the artist
    ids for `Artist::enrich()`.
- `wraps`: `id`, `period` (indexed), `starts_at`, `ends_at`, `cards` (JSON),
  timestamps
- `settings`: `id`, `key` (unique), `value` (text, `encrypted` cast). Keys:
  - `spotify_access_token`, `spotify_refresh_token`, `spotify_expires_at`
  - `spotify_name`, `spotify_image_url`, `spotify_user_id` (the "username" from
    `GET /me`, used for the dashboard's "Open in Spotify" link; you never type
    it)
  - `last_synced_at`

**Models:**
- `Play`: `MIN_MS = 30_000`; `played_at` cast to immutable_datetime.
- `Artist::enrich(string $name): Artist`
  - Uses `firstOrCreate`.
  - Returns at once, with no network call, if `fetched_at` is set or Spotify
    isn't connected.
  - Otherwise it takes one `spotify:track:` URI from that artist's plays and
    calls `Track::enrich($uri)`, which is usually already cached. From that
    track's `artists` it picks the artist whose name matches, falling back to
    the first. Then it calls `artist()` and stores the genres and
    `images[0].url`.
  - It never throws.
- `Track::enrich(string $uri): Track`
  - Returns the cached row if there is one. Otherwise it calls `GET
    /tracks/{id}`.
  - Every track response is stored through `Track::fromApi(array $track)`, which
    `Spotify::sync()` also uses. It records:
    - `artists` as `[{id, name}]`
    - the ~300px album image: `images[1]` if present, else `images[0]`
    - `release_year` from `album.release_date`
    - `duration_ms`
  - It never throws, and returns an unenriched row when Spotify isn't connected
    or the lookup budget has run out.
- **Lookup budget:**
  - `Spotify` is bound as a **singleton** in `AppServiceProvider`, with `public
    ?int $budget = null`. Null means no limit.
  - Every uncached `track()` or `artist()` call from `enrich()` decrements it.
    At 0, `enrich()` returns the row unenriched.
  - The dashboard sets `$budget = 20` per request, so a first visit makes at
    most about 4 seconds of Spotify calls. Wrapped builds and commands leave it
    unlimited.
  - `// ponytail: per-request cap; use Http::pool or warm the cache in
    wrapped:sync if first loads feel slow.`
- `Wrap`: casts `period` to `Period`, the dates to immutable_datetime, and
  `cards` to an array.
- `Setting::read($key, $default)` wraps decryption in `rescue`, so a changed
  `APP_KEY` just looks like "not connected". `Setting::write($key, ?$value)`
  deletes the row when the value is null.
- `AppServiceProvider` calls `Model::unguard()` and
  `Date::use(CarbonImmutable::class)`.

**`App\Enums\Period`** (string-backed):
- Cases: `Week='week'` (7 days), `Month='month'` (30), `HalfYear='half-year'`
  (182), `Year='year'` (365), `AllTime='all-time'`.
- `window($now)` returns `[$now - days, $now]`. For AllTime it returns `[first
  play, $now]`.
- `baseline($now)` returns `[$now - 2×days, $now - days]`. For AllTime it
  returns `[first play, $now - 365d]`.
- Windows are half-open (`>= from`, `< to`). `$now` is always UTC; `build()`
  forces `->utc()`.
- `label()` returns `this week`, `this month`, `the last 6 months`, `this year`
  or `all time`. It is used for the UI title.

**Card contract**: `wraps.cards` holds exactly 3 cards, in order, each with
these 7 keys:

```php
['type' => 'biggest_change'|'new_discovery'|'summary', 'headline' => string, 'body' => string,
 'stat' => ?string, 'stat_label' => ?string, 'image_url' => ?string, 'theme' => 'ember'|'violet'|'gold']
```

The theme is fixed by type: biggest_change is **ember** (MELO orange),
new_discovery is **violet** (Groove Graph), and summary is **gold** (LISTEN).

**Config and env:**
- `config('app.display_timezone')` comes from `WRAPPED_TIMEZONE`, default
  `Europe/London`. The app timezone stays UTC.
- `config('services.spotify.{client_id,client_secret,redirect}')`.
- `config('services.anthropic.key')` and `config('services.anthropic.model')`.
  The model is `env('ANTHROPIC_MODEL') ?: 'claude-opus-5-5'`, because an empty
  env value is `''`, not null.
- SQLite `busy_timeout` is 5000. WAL stays off, so copying the database file is
  a safe backup.

**Routes** (literal paths in views):
- `GET /` → `WrapController@index`
- `POST /wraps` → `store`
- `GET /wraps/{wrap}` → `show`
- `GET /spotify/connect`, `GET /spotify/callback`, `POST /spotify/sync` →
  `SpotifyController`
- `POST /import` → `ImportController` (invokable)
- `GET /stats/{tab?}?period=` → `StatsController` (invokable)
  - `tab` is one of `overview` (default), `artists`, `songs`, `albums`,
    `genres`, `insights` or `recent`. Anything else returns 404, enforced with
    `whereIn`.
  - `period` is a `Period` value, default `year` (closest to volt.fm's "Long
    Term"). An invalid value falls back to the default.

**`App\Services\Stats`** holds the queries shared by the cards and the
dashboard. It has no state, and each method takes a window `?from, to` plus a
limit where relevant:
- `between(?from, to): Query\Builder`
- `artists(?from, to, ?int $limit = null): Collection`: `artist_name, plays,
  ms`, ordered by plays descending, then name ascending, and keyed by
  `artist_name`.
- `firstPlays(CarbonImmutable $before): Collection`: `artist_name =>
  MIN(played_at)`.
- `tracks(?from, to, int $limit)`: grouped by `track_name, artist_name`. Returns
  `plays`, `MAX(track_uri) uri` (basic-export rows have no URI) and
  `MAX(ms_played) longest`. Your longest play of a track is used as its length,
  with no API call (`// ponytail: wrong only for tracks you never finished`).
- `albums(?from, to, int $limit)`: `whereNotNull('album_name')`, grouped by
  `album_name, artist_name`. Returns `plays` and `MAX(track_uri) uri`, used for
  the cover and year.
- `genres(Collection $artists, int $take = 10): array`: `genre => weighted
  plays`, sorted descending. It enriches the first `$take` artists.
- `clock(?from, to): array{hours: int[24], days: int[7]}`:
  - SQL groups by **UTC hour bucket**: `strftime('%Y-%m-%d %H:00:00',
    played_at)` with `COUNT(*)`.
  - PHP converts each bucket to `display_timezone` and re-buckets it by local
    hour and ISO weekday, so DST is exact.
  - That's at most about 8.8k rows per year.
- `familiar(?from, to, CarbonImmutable $cutoff): array{new, familiar}`: plays
  from artists first heard on or after the cutoff, against the rest. It reuses
  `firstPlays`.
- `decades(Collection $tracks): array`: `decade => plays`, from
  `Track::enrich($uri)->release_year`. Tracks with no year are left out, and a
  count of those is returned.
- `lengths(Collection $tracks): array`: `≤3m`, `3–4m`, `4–5m`, `5–9m`, `9m+` →
  plays, from `longest`.
- `totals(?from, to): array{plays, minutes, artists, songs}`
- `recent(int $limit = 50): Collection`

`WrappedBuilder` takes `Stats` in its constructor and uses `between`, `artists`,
`firstPlays` and `genres`. Its own private copies of those (from the cards plan)
are dropped.

**Home view data** (one agreed list):
- `$configured`, `$connected` (bools)
- `$profile`: `?['name', 'image_url']`
- `$lastSyncedAt`, `$firstPlayedAt`, `$lastPlayedAt`: `?CarbonImmutable`
- `$playCount`: int
- `$wraps`: newest first by `ends_at`
- `$periods`: `Period::cases()`
- `$importPath`: `base_path('import')`
- Flash messages: `session('status')` for success, `session('warning')` for any
  problem, plus `$errors`.

---

## Implementation by milestone

### M0: Bootstrap

1. **Move the images.** `mkdir -p docs/designs && mv music-* docs/designs/`.
   `TASK.md` stays at the root.
2. **Create the skeleton in a temp folder**, because `create-project` won't run
   in a folder that isn't empty: `composer create-project laravel/laravel:^13.0
   "$TMPDIR/wrapped-skeleton"`.
   - Run it with **PHP 8.5**. On this machine Flock defaults to 8.3 but has
     8.5.12 installed, so point the site at 8.5 with Flock's `update_site`.
   - Then set `"php": "^8.5"` in `composer.json` and run `composer update` so
     the lock file resolves against 8.5. Flock's shims and Herd then pick 8.5
     for this project.
3. **Copy it in:** `rsync -a --exclude vendor --exclude .env` into the repo.
4. **Delete what we don't use:**
   - `package.json`, `vite.config.js`, `resources/css`, `resources/js`,
     `welcome.blade.php`
   - `app/Models/User.php`, `database/factories`, `database/seeders`
   - the stock `0001_01_01_00000*` migrations
   - `tests/Unit`, `ExampleTest.php`
   - Check the actual skeleton file list after `create-project`.
5. **Create the import folder:** `import/.gitkeep`. Add `/import/*` and
   `!/import/.gitkeep` to `.gitignore`.
6. **`composer.json`:**
   - Drop the `dev` script and the factories/seeders autoload entries.
   - Replace `setup` with: `Composer\\Config::disableProcessTimeout`, `composer
     install`, create `.env` **and only then** run `key:generate` (a stock
     re-run would overwrite the key and break the encrypted tokens), then
     `migrate --force` (this creates the sqlite file).
   - **Re-running `setup` is therefore safe**, and the README uses it for
     updates.
7. **`.env.example` additions:**
   - `APP_NAME="Spotify Wrapped"`
   - `APP_URL=https://spotify-wrapped.test`
   - `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`
   - `SPOTIFY_CLIENT_ID=`, `SPOTIFY_CLIENT_SECRET=`
   - `SPOTIFY_REDIRECT_URI=https://spotify-wrapped.test/spotify/callback`
   - `WRAPPED_TIMEZONE=Europe/London`
   - `ANTHROPIC_API_KEY=`, `ANTHROPIC_MODEL=claude-opus-5-5`
   - Remove `VITE_*`.
8. **`phpunit.xml`:**
   - Make sure `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` are
     **uncommented**. This is critical: otherwise tests wipe the real database.
   - Set fake Spotify env values.
   - Add `ANTHROPIC_API_KEY=""` with `force="true"`.
   - Drop the Unit suite.
9. **Dependencies:** `composer require anthropic-ai/sdk`. It is the only added
   dependency.

### Foundation: import (`app/Services/HistoryImporter.php`)

`import(string $dir): array{files, read, imported, skipped, from, to, message}`.

**Finding files:**
- Search `$dir` recursively, so dropping in the whole unzipped folder works.
- Match `Streaming_History_Audio_*.json` (extended) and
  `StreamingHistory_music_*.json` (basic), and process extended files first.
- Video, podcast, playlist and dot files are ignored.

**Mapping rows:**
- **Extended:** skip a row if the track or artist is empty (podcast or
  audiobook) or `ms_played < 30000`.
  - `played_at` = `CarbonImmutable::parse(ts)->utc()->toDateTimeString()`
  - `artist_name` = `master_metadata_album_artist_name`
  - `track_uri` = `spotify_track_uri`
- **Basic:** skip if `msPlayed < 30000`. `endTime` is UTC (seconds become `:00`)
  and `track_uri` is null.
- Use Carbon so bad input throws instead of becoming 1970.

**Dedupe:** the range is worked out per file, and each file is its own
transaction.
- **Extended file:** delete plays in its `[min, max]` where `source='api' OR
  track_uri IS NULL`, then insert.
- **Basic file:** drop rows that fall on or before the last extended play,
  delete api plays in the remaining range, then insert.
- **Inserting:** `insertOrIgnore` in chunks of 500, which returns the inserted
  count. Re-importing is a no-op.

**Messages:**
- Success: "Read 120,431 rows from 9 files. Imported 84,211 new plays (2 Mar
  2014 – 28 Oct 2026). Skipped 36,220 (podcasts, plays under 30 seconds, or
  already imported)."
- No files: a hint naming the folder and the file patterns.
- Invalid JSON: a `RuntimeException` naming the file.

**Memory:** `ini_set('memory_limit', '1G')`, with the comment `// ponytail:
whole-file json_decode (~12MB/file); streaming parser if Spotify ships bigger
files`.

**Two entry points, one code path:**
- `wrapped:import {path?}`
- `POST /import`, which calls `set_time_limit(0)` (`// ponytail: synchronous;
  queue it if big histories time out`). The README sends large imports to the
  command.

### Foundation: Spotify (`app/Services/Spotify.php`, one class)

**Methods:** `configured()`, `connected()`, `authorizeUrl($state)`,
`connect($code)`, `disconnect()`, `me()`, `recentlyPlayed()` (`limit=50`),
`track($id)`, `artist($id)`, `sync(): int`.

**Authorisation:** Authorization Code flow against
`accounts.spotify.com/authorize`, scope `user-read-recently-played`, `state`
kept in the session and checked with `hash_equals`. The token is exchanged at
`/api/token` with Basic auth.

**Tokens:**
- Stored encrypted in `settings`.
- Refreshed when they have less than 1 minute left.
- **Refreshed once on a 401** and the request retried.
- If the refresh itself gets a 4xx, call `disconnect()` and show a "Click
  Connect Spotify" message.
- Keep the old refresh token if Spotify omits a new one.

**Error messages** are readable `RuntimeException`s:
- **403:** "…the account that owns the developer app needs Premium, and your
  account must be listed under User Management…", plus Spotify's own
  `error.message`.
- **429:** includes the `Retry-After` value (`// ponytail: no auto-retry`).
- **Offline:** `ConnectionException` is left unwrapped. Callers catch
  `RuntimeException|ConnectionException`.

**`sync()`:**
- Maps items to `source='api'`, `ms_played=track.duration_ms`,
  `artist_name=artists[0].name`, and `played_at` in UTC with milliseconds
  dropped, so re-syncs dedupe on the unique key.
- Calls `Track::fromApi($item['track'])` for each item, so covers, years and
  durations of recent plays need no extra API calls.
- Drops anything on or before the last export play.
- Uses `insertOrIgnore` and writes `last_synced_at`.
- `connect()` also stores `spotify_user_id` from `GET /me` `id`.

**`SpotifyController`:**
- `connect`: warns if no keys are set; otherwise redirects away to Spotify.
- `callback`: handles `access_denied`, a state mismatch (the message names the
  redirect URI and says to use the same address), and token failures. On success
  it shows "Connected to Spotify as {name}". There's no sync here, because the
  lazy sync on the home page handles it.
- `sync`: shows "Synced N new plays".

### Story 1: Generate Wrapped

**`WrappedBuilder::build(Period $p, CarbonImmutable $now): Wrap`:**
1. `$now = $now->utc()`; `[$from, $to] = $p->window($now)`.
2. Build the cards: `[biggestChange, newDiscovery, summary]`.
3. **Regenerating the same period on the same local day updates that row**,
   matched on `ends_at` within the display-timezone day. The id and URL stay the
   same.

There is no "empty window" exception: the card methods have their own friendly
empty-state copy.

**`WrapController`:**
- `index` runs **lazy mode**:
  1. If connected and the last sync was over 15 minutes ago (or never), call
     `sync()`. On failure, `report()` it and set `session()->now('warning',
     'Could not sync with Spotify: …')`. The page never breaks.
  2. If there are plays in the last 7 days and no weekly wrap with `ends_at` in
     the last 7 days, run `rescue(fn () => build(Period::Week, now()))`.
  3. Then render home with the agreed data.
- `store`: validate `period` with `Rule::enum(Period::class)`, build, redirect
  to `show`.
- `show`: `view('wraps.show', compact('wrap'))`.

**`routes/console.php`** (closures, no command classes):
- `wrapped:import {path?}`
- `wrapped:sync` (warns if not connected)
- `wrapped:generate {period=week}`: rejects unknown periods, prints each
  headline and the URL.
- Schedule:
  - `Schedule::command('wrapped:sync')->hourly()`
  - `Schedule::command('wrapped:generate week')->weeklyOn(1,
    '07:00')->timezone(config('app.display_timezone'))`
  - Both run at 07:00, sync first, because they run in the order defined.

**Acceptance criteria:**
- **Generate for the latest period:** the Generate form, the command, lazy
  weekly generation and the Monday schedule.
- **Fixed set of cards:** always 3, in a fixed order.
- **Built from history:** every card comes from `plays`.

### Stories 2–4: card algorithms (`app/Wrapped/WrappedBuilder.php`)

**Shared ideas:**
- **`$cutoff = baseline($now)[1]`.** An artist is **new** if their first-ever
  play is on or after `$cutoff`. An artist can **rise or drop** only if they had
  at least 1 baseline play. So **Biggest Change and New Discovery can never name
  the same artist**, and neither method needs to know about the other.
- **Queries come from `Stats`** (see Shared contracts): `between()`, `artists()`
  (where `ms` is `SUM(ms_played)`, which is now real for API plays too, so there
  is no estimate constant), `firstPlays()` (using the covering index) and
  `genres()`.
- **Private helpers:**
  - `phrases(Period)` returns the "during" and "before" phrases from the table
    below.
  - `plural()`, `day()` (display timezone: `l j F` for week and month, `j F Y`
    for longer periods) and `card()` (which sets the theme).
- **Constants:**
  - `MIN_CHANGE=5`: a change of 1 → 2 plays never wins.
  - `TOTAL_RATIO=2` with `TOTAL_MIN_PLAYS=20`.
  - `TOP_ARTISTS=10`: the number enriched for genres.
  - `CLASSIC_DAYS=365` and `CLASSIC_SHARE=0.5`.
  - `MANY_NEW=3`.
  - `MOODS`: a substring map from genre words to a mood
    (`doom/sludge/black/death/goth/drone`→darker,
    `thrash/grind/hardcore`→heavier, `ambient/chill/lo-fi/acoustic`→calmer,
    `house/techno/disco/dance`→upbeat), with a `ponytail:` comment.

| Period | during | before | Summary opener | Discovery "since" |
|---|---|---|---|---|
| week | this week | last week | This week | this week |
| month | this month | the month before | This month | this month |
| half-year | in the last 6 months | the 6 months before | In the last 6 months | in the last 6 months |
| year | this year | the year before | This year | this year |
| all-time | so far | a year ago | Over the years | in the last year |

**Story 2: `biggestChange()`**. It checks these cases in order:
1. **Empty window:** "No plays {during}." / "Down from N plays {before} – your
   headphones had a rest." (or an import/sync hint).
2. **No baseline plays:** "You played X minutes of music {during}." / "N plays
   across K artists – nothing earlier to compare with yet."
3. **Totals:** if listening minutes changed by a factor of at least 2, and the
   smaller period has at least 20 plays: "You listened P% more/less {during}." /
   "X minutes, up/down from Y {before}." Stat `+150%`.
4. **Biggest artist move:** among baseline artists with `|Δ| ≥ 5`, sort by `|Δ|`
   descending, then rises before drops, then name ascending.
   - Rise: "**Clutch was your fastest-rising artist this week.**" / "23 plays,
     up from 2 last week." Stat `+21` / "plays vs last week". Image from
     `enrich()`.
   - Drop: "You took a break from {A} {during}."
   - All-time rise: "{A} grew fastest in your all-time listening."
5. **Otherwise:** the totals card, as a percentage.

Skipped: a "rising genre" candidate. The summary card already covers genres.

**Story 3: `newDiscovery()`**, always exactly one card:
- **Empty window:** "No new artists {since}."
- **History guard:** if the earliest play is on or after `$cutoff`, there isn't
  enough history before the period to tell what's new. Show "{Top} kicked off
  your listening history." / "Your history starts on {day}, so every artist
  looks new. Import your full Spotify data for true discoveries."
- **New artists:** pick by most window plays, then earliest first play, then
  name.
  - Headline: "**You discovered Danzig this week.**"
  - Body: "9 plays since you first heard them on Tuesday 3 November, mostly
    'Mother'. One of 2 new artists this week." With a single play: "You first
    heard them on {day} with '{track}'."
  - Stat: plays.
- **Nothing new:** "No new artists {since}." / "You stuck with your favourites –
  all N plays came from K artists you already knew."
- A `ponytail:` comment notes that a thin history (sync only) still over-reports
  new artists. The README says to import the export first.

**Story 4: `summary()`**, always the last card:
- **Facts:** minutes, plays, artist count, top artist, the new-artist count
  (forced to 0 by the history guard), the classic share (window plays from
  artists first heard more than 365 days before `$cutoff`), and the top genre in
  the window and baseline.
- **Top genre:** `array_key_first($stats->genres($artists))`, which looks at the
  top 10 artists with genres weighted by plays and sorted stably. If there are
  no genres (not connected), it is null.
- **Sentence:** `{Opener} {A}{B}.`, chosen deterministically from the facts, so
  the same facts always give the same text.
  - **A** (first that applies): no genre → "you kept coming back to {top}"; same
    genre as the baseline → "you stuck with {mood(genre)}"; otherwise "you
    explored {mood(genre)}". `mood('doom metal')` gives "darker metal".
  - **B** (first that applies): classic share ≥ 0.5 → " while revisiting classic
    favourites"; new artists ≥ 3 → " and found N new artists"; genre known → "
    with {top} on repeat"; otherwise nothing.
  - Result: "**This week you explored darker metal while revisiting classic
    favourites.**", which is the story example exactly.
- **Body:** "{X} minutes, {N} plays and {K} artists. {Top} led the way with {M}
  plays." Stat: minutes. Image: the top artist.
- **Empty window:** "Nothing played {during}."

**Optional Claude rewrite: private `polish(string $sentence, array $facts):
string`** (~20 lines, inside `WrappedBuilder`):
- **Gate:** return the template unless `config('services.anthropic.key')` is
  filled. Never construct `Client` without a key, because it would fall back to
  an `ant auth login` profile.
- **Call:** `(new Anthropic\Client(apiKey: $key))->messages->create(...)` with:
  - `model: config('services.anthropic.model')`, `maxTokens: 16000`,
    `outputConfig: ['effort' => 'low']`
  - a system prompt: one sentence, at most 25 words, British English, second
    person, no emojis or quotes, **only the provided facts**, keep the time
    phrase
  - the user message: `{draft, facts}` as JSON
- **Not sent:** no `thinking: disabled` (a 400 on Opus 5.5) and no
  `temperature`.
- **Safety:** wrap the whole call in `rescue(..., $sentence)`. Accept the result
  only if `stopReason === 'end_turn'` and the first text block is a single line
  of at most 30 words.
- **Timeout:** set a client timeout of about 20 s and 0 retries. Confirm the SDK
  option names in its README at implementation time, because the defaults are 10
  minutes with 2 retries.
- **Scope:** only the headline is rewritten; the body stays factual.
- **Cost:** roughly 1p per Wrapped on Opus 5.5 at low effort, and less with
  `ANTHROPIC_MODEL=claude-haiku-5-5`.
- Not used: the server-side `fallbacks` beta, because the template already is
  the fallback.

**Performance at about 200k plays:** around 8 indexed queries per weekly build.
The first build makes up to about 40 Spotify enrichment calls; after that
they're cached. All-time does full scans in roughly 0.3 s; `ponytail:` memoise
`artists()`/`firstPlays()` per build if it hurts.

### Story 5: Presentation (views, CSS, JS)

**Files:** `layout.blade.php`, `home.blade.php`, `wraps/show.blade.php`,
`public/css/app.css` (~300 lines, the whole design system),
`public/js/wrapped.js` (~45 lines). There are no partials or components, and
cards are rendered inline in an `@foreach`. Assets are cache-busted with `?v={{
filemtime(...) }}`.

**Design tokens** (from the images):
- Base colours: `--bg #0c0c0e`, `--panel #16181d`, `--text #f4f4f5`, `--muted
  #a3a3ad`.
- Buttons: `--green #1db954` with dark text (volt.fm's connect button) and
  `--ember #ff6a3d` (the MELO primary).
- Card themes, each a `--c1/--c2/--glow` trio on `[data-theme]`:
  - violet `#120f2e/#3d36b0/#8b85ff`
  - gold `#1a1206/#7a5520/#e0b25a`
  - ember `#1f0904/#a33a16/#ff7a45`
  - White text on `--c2` is at least 6.6:1.
- **Fonts:** **Unbounded** (Google Fonts, `wght@500..900`) for the wide, heavy
  LISTEN look, and the system UI stack for body text.
- **Type scale:** `clamp()` throughout. Card sizes use container units: headline
  `clamp(1.5rem, 8cqi, 2.25rem)`, stat `min(6rem, 24cqi, 130cqi / var(--len))`
  so long numbers fit.
- **LISTEN ring motif**, in pure CSS: layered `radial-gradient` and
  `repeating-radial-gradient` on `[data-theme]::before`, positioned by
  `--ring-at` and masked to fade before the text.

**`wraps/show.blade.php`, the carousel:**
- A `<section aria-roledescription="carousel">` with a header bar holding
  progress segments, a × close link, a "Wrapped" capsule title (with a visually
  hidden period and date range) and a "**1 / 3**" counter.
- The track `div.wrap-track` (`tabindex=0`) holds one `div.card` per card, each
  with `role="group" aria-roledescription="slide" aria-label="n of 3"`,
  `data-type` and `data-theme`. Inside each card:
  - eyebrow: "BIGGEST CHANGE · THIS WEEK"
  - optional round artist image (`alt=""`)
  - optional big stat and label
  - `h2` headline and the body
  - on the last card: the date range, plus "Back to home" and "Generate another"
    buttons
- Prev/next buttons with `aria-label`s and an `aria-live="polite"` region.
- The `id="card-n"` attributes make `#card-2` deep links work natively.

**Carousel CSS:**
- `scroll-snap-type: x mandatory`, with `scroll-snap-stop: always` so one swipe
  moves one card.
- `overscroll-behavior-x: contain`, so a trackpad swipe doesn't trigger browser
  back.
- `100dvh` height and respect for safe-area insets.
- Desktop (`min-width: 40rem` and `min-height: 40rem`): a centred phone frame
  with a 9/16 aspect ratio, plus a keyboard hint.
- Smooth scrolling only under `prefers-reduced-motion: no-preference`.
- `html:not(.js) [data-js] { display: none }`. A one-line inline script adds the
  `.js` class before first paint.

**`wrapped.js`:**
- An IntersectionObserver (threshold 0.6) keeps the counter, progress bars and
  button `aria-disabled` states in sync, however the card changed.
- `go(i)` scrolls with `scrollTo`, so CSS decides between smooth and instant,
  and updates the live region.
- Taps on the left or right third move back or forward, ignoring links, buttons
  and selected text.
- ←, → and Esc work, ignoring modified keys so Alt+← still goes back.
- Without JS, swiping, scrolling and the close link still work.

**`home.blade.php`** (volt.fm-like, cards in an `auto-fit` grid):
- Header: app name, plus your avatar and name when connected.
- Flash messages.
- Setup states:
  - `!$configured`: "Add your Spotify keys to .env" with a link to the README.
  - `$playCount === 0`: numbered **Get started** steps, mentioning
    `$importPath`.
- **New Wrapped** form: native radio "pills" for the five periods
  (`:has(:checked)` styling, 44px targets) and a "Generate Wrapped" button.
- **Your data** panel: play count, history range, and sync status ("Synced 5
  minutes ago"). It shows **Sync now** or **Connect Spotify** (green), the
  **Import** button, and a "How importing works" disclosure.
- **Your Wraps**: tiles styled like the LISTEN cards, each showing the period
  label, date range and first headline, and linking to `/wraps/{id}`.

**Accessibility checklist:**
- semantic headings
- every slide stays readable in order
- a visible 3px focus ring
- 44px tap targets
- contrast of at least 4.5:1
- reduced motion respected
- usable without JS

### Story 6: Stats dashboard (`/stats`)

**Story:** As a listener, I want a dashboard of my listening stats, so that I
can explore my top artists, songs, albums and genres and see patterns for any
period.

**Acceptance criteria:**
- A period selector (week, month, 6 months, year, all time), default **year**.
  Every tab respects it.
- **Overview:** top artists (10), top songs (10), top albums (10) and top genres
  (15). Each list has a **See all** link to its own tab showing the top 50.
- **Insights:** when you listen (hour of day and weekday), new vs familiar, by
  decade, and by length.
- **Recent plays:** your last 50 plays.
- **Header:** your Spotify avatar, display name, `@username` and an **Open in
  Spotify** link.
- Mobile-first, and usable without JS. Images, genres and decades fill in
  gracefully when Spotify isn't connected or hasn't been asked yet.

**Mapping from the screenshots:**

| volt.fm | Ours | Data source |
|---|---|---|
| Header: avatar, name, Open in Spotify, followers | Avatar, name, `@spotify_user_id`, Open in Spotify (`https://open.spotify.com/user/{id}`) | `settings`. Followers were removed by Spotify, so they're dropped. |
| Tabs: Overview, Artists, Songs, Albums, Genres, Insights, Recent Plays, Playlists | The same, **minus Playlists** | Playlists would need extra scopes (skipped) |
| "Long Term ▾" dropdown | Period **pill links** (`?period=`, `aria-current="page"`), so no JS is needed | `Period` |
| Stats strip (MELO "Statistics" tiles) | Minutes · Plays · Artists · Songs | `Stats::totals` |
| Top Artists: 3-photo collage, then 10 rows (rank, avatar, name) | The same, plus a play count | `Stats::artists`, `Artist::enrich()->image_url` |
| Top Songs: cover collage, then rows (cover, title, year, artist) | The same, plus a play count | `Stats::tracks`, `Track::enrich()` |
| Top Albums: collage and rows | The same | `Stats::albums`, with the cover and year from the album's track `uri` |
| Top Genres: 15 numbered rows | 15 numbered rows, each with a share-of-plays bar | `Stats::genres` over the top 20 artists |
| Insights: mood sliders (Sad–Happy, Acoustic–Electric…) | **When you listen:** 24 hour columns and 7 weekday bars | `Stats::clock`. Audio features aren't available. |
| By Popularity (Obscure / Average / Popular) | **New vs familiar:** plays from artists first heard in this period vs before | `Stats::familiar`. Popularity was removed. |
| By Decade | By Decade, based on your top 50 songs | `tracks.release_year` |
| By Length (≤4m, 5–9m) | By Length (≤3m, 3–4m, 4–5m, 5–9m, 9m+), based on your top 50 songs | your longest play of each track |

**`StatsController::__invoke(Request, Stats, Spotify, string $tab =
'overview')`:**
1. `$period = Period::tryFrom((string) $request->query('period')) ??
   Period::Year`, then `[$from, $to] = $period->window(now())`.
2. Set `$spotify->budget = 20`.
3. Fill `$data` with a `match ($tab)`:

   | Tab | Data |
   |---|---|
   | `overview` | artists, tracks and albums (10 each) and genres (15) |
   | `artists`, `songs`, `albums`, `genres` | that one list, 50 rows |
   | `insights` | `clock`, `familiar($from, $to, $period->baseline(now())[1])` (the same "new" rule as the cards), and `decades` and `lengths` over `tracks(…, 50)` |
   | `recent` | `recent()` |

4. Turn each list into plain arrays here, so the views stay dumb:
   - Each row: `rank`, `name`, `sub` (the artist, or the year and artist),
     `plays` and `image`.
   - Each chart point: `label`, `count` and `v`, where `v` is `round(count / max
     * 100)`.
5. Return `view('stats', [...$data, 'tab', 'period', 'periods' =>
   Period::cases(), 'totals', 'profile', 'connected', 'playCount'])`.

**`stats.blade.php`:**
- **Header:** the profile, like volt.fm's large avatar, name and links.
- **Tab bar:** a `<nav aria-label="Stats">` with links. The active tab has a
  green underline and `aria-current="page"`. It scrolls sideways on mobile
  (`overflow-x: auto`).
- **Period pills, then the stats strip, then `@switch($tab)`:**
  - **Overview:** a 2-column grid on desktop (`repeat(auto-fit, minmax(min(100%,
    22rem), 1fr))`, as on home) and one column on mobile. It shows four panels.
  - **Lists:** `@include('stats.list', ['title', 'rows', 'seeAll'])`. This is
    the one partial, used on both the Overview and its See-all tab. It renders:
    - a collage of the first 3 images: overlapping circles for artists and
      squares for covers, with the middle one larger, in CSS only, and only when
      all 3 exist;
    - then `<ol>` rows: rank, a 40px thumbnail, name and sub, and the play
      count.
  - **Insights:** each chart is a `<figure><figcaption>` with an `<ol>` of
    `<li>` items holding the label, an `aria-hidden` bar and a visible value.
    - Bars: `inline-size: calc(var(--v) * 1%)` in volt green.
    - Hour columns: `block-size: calc(var(--v) * 1%)`. Labels show at 00, 06, 12
      and 18, and each column carries visually hidden text such as "14:00, 120
      plays".
    - Footnotes: "Based on your top 50 songs; 3 without a release year".
      "Connect Spotify to see decades" when not connected.
  - **Recent:** rows with the track, artist and `diffForHumans()` time.
- **Missing images:** a placeholder thumbnail showing the first letter on a
  `data-theme` gradient, reusing the card themes and ring motif.
- **Empty states:** "Not enough plays in this period", and if there are no plays
  at all, a link back to home's getting-started steps.

**Shared navigation:** the layout gains a slim top nav (app name · **Wrapped** ·
**Stats**) on the home and stats pages. The wrap deck stays full-screen with no
nav. Home's "Your data" panel gets a "See your stats →" link.

**Performance and limits:**
- Overview runs about 6 grouped queries.
- All-time with about 200k plays takes roughly 1–2 s (`// ponytail: cache per
  (tab, period, latest played_at) if all-time feels slow`).
- The first visit to a period makes at most 20 Spotify lookups; a refresh or two
  fills in the remaining covers. Sync already supplies covers for recently
  played tracks.

---

## Integration decisions (where the slice plans were reconciled)

1. **Herd domain = folder name.** The folder is `spotify-wrapped`, so the site
   is `https://spotify-wrapped.test` everywhere: `.env`, dashboard, README and
   tests.
2. **No empty-window exception in `build()`.** The card methods' own empty
   states are friendlier, and keeping both would leave one set as dead code. The
   Generate form only appears once plays exist.
3. **Shared home-view variable names** and `$profile['name']` are fixed in
   "Shared contracts" above.
4. **Theme is fixed per card type.** The presentation test fixture uses the same
   mapping.
5. **`ANTHROPIC_MODEL` default** is `env(...) ?: 'claude-opus-5-5'`, which
   survives an empty `.env` value.
6. **Asset paths:** `public/css/app.css` and `public/js/wrapped.js`.
7. **No `Play::within` scope.** Lazy mode uses `Play::where('played_at', '>=',
   now()->subDays(7))->exists()`, and the cards use `Stats::between()`.
8. **One query home for cards and dashboard.** The cards plan's private
   `between()`, `artists()` and `firstPlays()` and its genre weighting move into
   `App\Services\Stats`, and `WrappedBuilder` and `StatsController` both use it.
   No query exists twice.
9. **API plays store the track duration** in `ms_played`. That deletes the cards
   plan's 3.5-minute `ESTIMATED_MS` constant and the "store duration at sync"
   item from the skipped list.
10. **`Artist::enrich` goes through `Track::enrich`**, so a track lookup is
    shared and cached once, whether it was made for an artist's photo, an album
    cover or a decade.
11. **The dashboard caps lookups per request; Wrapped builds don't.** A capped
    first build would lose genres for good, because cards are stored.

---

## Tests (PHPUnit feature tests, `RefreshDatabase`, `Http::preventStrayRequests()` in `TestCase`)

Each file checks:
- **`ImportTest`**
  - The extended fixture (5 rows) imports 3 and skips 2: a podcast and a
    5-second play.
  - Basic rows get `:00` seconds and no URI.
  - A basic podcast file is ignored.
  - Re-importing gives 0.
  - An extended import deletes overlapping api and basic rows but keeps a later
    api play.
  - Basic rows on or before the last extended play are skipped.
  - The command succeeds or fails correctly.
  - Invalid JSON names the file.
- **`SpotifyTest`**
  - The connect redirect has the right parameters and state.
  - Missing keys, a bad state and `access_denied` all show warnings, and a bad
    state sends nothing.
  - On a successful callback the tokens are stored encrypted (the raw value
    differs).
  - An expired token refreshes, and the old refresh token is kept.
  - A 401 refreshes and retries once.
  - A failed refresh disconnects.
  - A 403 message mentions Premium.
  - Sync maps rows, a re-sync inserts 0, and plays before the export end are
    skipped.
  - Sync sets `ms_played` to `duration_ms` and upserts a `tracks` row (cover,
    year, artists) with no extra HTTP call.
  - `connect()` stores `spotify_user_id`.
  - `enrich()` caches, and makes no calls when disconnected.
  - An undecryptable setting reads as the default.
- **`WrapTest`**
  - Period windows and baselines for every case, the AllTime first-play start,
    and the labels.
  - `build()` saves 3 cards with the contract keys and themes.
  - A same-day rebuild keeps one row; a different day adds a row.
  - `POST /wraps` validation and redirect.
  - Lazy sync: called when stale, not called when fresh, and a 500 still renders
    the page with a warning.
  - Lazy weekly generation creates a wrap once, not twice.
  - `wrapped:generate` works, and an unknown period fails.
- **`WrappedCardsTest`** (fixed `$now = 2026-11-05 12:00 UTC`): 14 cases with
  exact expected strings:
  - rise, drop, totals winning, the noise threshold, tie-break
  - first week, with API plays counted at their track duration
  - discovery with a timezone, discovery tie-break
  - **Biggest Change never names the discovery**
  - nothing new, empty window
  - summary with genres, giving the exact story sentence and proving the
    template path runs with no key and no network
  - summary without genres
  - all-time
- **`PresentationTest`**
  - `/wraps/{id}` shows the carousel role, the slides "1 of 3"… in order with
    their headlines, "Back to home", and HTML-escaped card text.
  - Home shows the empty state.
  - Home shows the connected state with past wraps.
- **`StatsTest`**
  - Overview shows top artists, songs and albums in play order with counts, and
    `?period=week` vs `year` changes the result.
  - An unknown tab returns 404, and a bad period falls back to year.
  - **Not connected:** no HTTP is sent (stray requests are blocked),
    placeholders render, and the decades footnote says to connect.
  - **Connected with 25 uncached artists:** at most 20 Spotify lookups per
    request (`Http::assertSentCount`).
  - **Clock DST:** a play at `2026-07-01 22:30 UTC` lands in hour 23 (BST), and
    one at `2026-01-10 22:30 UTC` lands in hour 22.
  - Lengths use the longest play. Decades come from seeded `tracks` rows, and
    tracks with no year are counted.
  - `familiar` splits on the first-play cutoff.
  - Recent shows the 50 newest plays, and the See-all tab shows 50 rows.

---

## README.md (detailed, written for a non-developer)

Section by section, with this content:

1. **Title and pitch.** Your own Wrapped for any week; three swipeable cards; a
   volt.fm-style **stats dashboard** for any period; everything stays on your
   Mac. Add screenshots (`docs/wrap-card-1.png`, `docs/stats.png`).
2. **What you need.**
   - Checklist: a Mac, Spotify **Premium** (and why), about 30 minutes, your Mac
     password.
   - The **information table** from "What you need to supply" above.
   - "You don't need your Spotify username or password."
3. **Step 1: Request your Spotify data (do this first).**
   - spotify.com/account/privacy → **Download your data**.
   - Tick **Extended streaming history** (up to 30 days) and **Account data**
     (about 5 days). Skip technical logs.
   - Click **Request data**, then **click Confirm in Spotify's email**.
   - Download the zip within 14 days.
4. **Step 2: Install Herd.**
   - Download from herd.laravel.com, drag it to Applications and open it. The
     free tier is enough.
   - In Herd → Settings → **PHP**, click **Install** next to **8.5**, then **Set
     as default**.
   - Reopen Terminal, then run `php -v` and check it shows **PHP 8.5**.
5. **Step 3: Get the code into `~/Herd/spotify-wrapped`.**
   - Download ZIP, unzip, and **rename the folder to `spotify-wrapped`** (GitHub
     adds `-main`), or use `git clone`.
   - The folder name becomes the web address.
6. **Step 4: Install.** `cd ~/Herd/spotify-wrapped && composer run setup`. Say
   what success looks like.
7. **Step 5: Turn on HTTPS.** `herd secure spotify-wrapped` (or the Herd → Sites
   → Secure toggle). Open https://spotify-wrapped.test.
8. **Step 6: Create the Spotify developer app.** At
   developer.spotify.com/dashboard, with your Premium login, accept the terms,
   then **Create app**:
   - name and description (anything)
   - **Redirect URI** `https://spotify-wrapped.test/spotify/callback`, typed
     exactly, then **Add**
   - tick **Web API**, then Save
   - In **Settings**, copy the Client ID and use **View client secret**. Treat
     the secret like a password.
9. **Step 7: Edit `.env`.** `open -e ~/Herd/spotify-wrapped/.env`. Fill in the
   four lines, with no quotes or spaces, then save.
10. **Step 8: Connect Spotify (on the Mac).**
    - Click Connect, then Agree on Spotify's page.
    - You should see "Connected to Spotify as …", and the last 50 plays are
      fetched.
    - If Spotify says "user not registered" or the app shows a 403: add your
      name and email under **User Management**.
11. **Step 9: Import your history.**
    - Unzip and copy the `Streaming_History_Audio_*.json` /
      `StreamingHistory_music_*.json` files (or the whole folder) into
      `import/`.
    - Click **Import**, or use `php artisan wrapped:import` for big histories.
    - What gets skipped: podcasts and plays under 30 s. Importing again is safe.
      Which source wins when they overlap.
12. **Step 10: Make your Wrapped.**
    - Pick a period, click **Generate Wrapped**, then swipe.
    - Regenerating on the same day replaces it, and a weekly one is made
      automatically.
13. **The Wrapped: how to use it.**
    - Navigation table: swipe or tap left and right (phone), ← → and Esc or
      click (computer), `#card-2` deep links.
    - What each card means.
14. **Your stats dashboard.** Click **Stats** in the top bar.
    - What each tab shows: Overview, Artists, Songs, Albums, Genres, Insights
      and Recent.
    - The period pills, with **This year** as the default.
    - Photos, covers, genres and decades come from Spotify and **fill in over
      the first refresh or two** (the app fetches up to 20 per page). They need
      Spotify connected.
    - What is missing compared with volt.fm, and why: Spotify removed mood,
      popularity and follower data for apps like this in 2024–2026.
    - Screenshot: `docs/stats.png`.
15. **Keeping it up to date.** The last-50-plays limit (about 3 hours of
    listening). Options:
    - (a) just open the app regularly;
    - (b) `php artisan schedule:work` in a window you leave open;
    - (c) a copy-paste **crontab** one-liner, plus how to remove it, noting it
      only runs while the Mac is awake;
    - (d) re-request and import an export to fill gaps.
16. **View it on your phone.**
    - `php artisan serve --host=0.0.0.0 --port=8000`, find the Mac's IP (System
      Settings → Wi-Fi → Details), open `http://<ip>:8000` and **Add to Home
      Screen**.
    - Allow incoming connections if macOS asks.
    - Warnings: home Wi-Fi only (no login), and Connect Spotify from the Mac.
17. **Optional: AI summaries.**
    - Add `ANTHROPIC_API_KEY` (console.anthropic.com) and optionally
      `ANTHROPIC_MODEL=claude-haiku-5-5` for lower cost.
    - Costs about 1p per Wrapped. Only the summary sentence is rewritten, and it
      falls back to the template on any error.
18. **Backing up.** `cp database/database.sqlite ~/Desktop/wrapped-backup-$(date
    +%F).sqlite`. Also keep `.env` and the original zips.
19. **Updating.**
    - With Git: `git pull && composer run setup`.
    - With a ZIP: rename the old folder, unzip the new one, and copy `.env`,
      `database/database.sqlite` and `import/` across. **Don't let Finder
      "Replace" the old folder.**
    - `setup` is safe to run again.
20. **Privacy and disconnecting.** Everything stays local, and the only scope is
    recently played. Revoke at spotify.com/account/apps.
21. **Troubleshooting table**, with the real messages and fixes:
    - `command not found: composer`
    - `requires php ^8.5` / "Your PHP version does not satisfy that requirement"
      → Herd → Settings → PHP → install 8.5 → Set as default, then reopen
      Terminal
    - Stats show grey initials instead of photos → connect Spotify, then refresh
      (up to 20 are fetched per page)
    - site can't be reached (folder name or Herd not running)
    - not private (HTTPS off)
    - `INVALID_CLIENT: Invalid redirect URI`, and Invalid client
    - Invalid client secret
    - "Spotify refused access" (Premium or User Management)
    - revoked or expired sign-in
    - "could not be verified" (wrong host)
    - cURL error 6 (offline)
    - rate limited
    - no files found
    - import timeout or memory (use the command)
    - database is locked
    - bad timezone
    - missing encryption key
    - database file does not exist
    - `.env` changes ignored (`php artisan config:clear`)
    - port in use
    - phone can't connect
    - for anything else: `storage/logs/laravel.log`
22. **For developers.**
    - Stack (PHP 8.5, Laravel 13, SQLite), the code map (including
      `app/Services/Stats.php`, the query layer shared by the cards and the
      dashboard), and the commands (`wrapped:import|sync|generate`, `php artisan
      test`).
    - Without Herd: `php artisan serve` on **127.0.0.1**, never localhost, with
      a matching `APP_URL` and redirect URI. Flock and Valet work like Herd.
    - Screenshots: headless Chrome at `--window-size=390,844`, one per
      `#card-n`, plus `/stats` and `/stats/insights` at 1280 px wide.
    - `grep -rn "ponytail:"` lists the deliberate shortcuts.

---

## Verification (end-to-end)

1. **Fresh install:** check `php -v` shows 8.5 (on this machine, the Flock site
   is pinned to 8.5 with `update_site`). Run `composer run setup` on a clean
   checkout. Re-run it and confirm the key is unchanged.
2. **Tests:** `php artisan test` passes all six feature files with no network
   (stray requests are blocked; Claude is off because the key is empty).
3. **Import:** `php artisan wrapped:import tests/fixtures/extended` prints the
   summary. Running it again imports 0.
4. **Serve:** with Herd (or locally with Flock: `link_site`, then `site_info`),
   open `https://spotify-wrapped.test`. Check the empty and setup states.
5. **Spotify:** with real keys, Connect → "Connected as …" → plays appear →
   `wrapped:sync` again gives 0 new.
6. **Import the real export** via `php artisan wrapped:import`, then
   `wrapped:generate week`. The three headlines print, and the URL opens the
   deck.
7. **Manual QA:**
   - iPhone Safari over `php artisan serve --host=0.0.0.0`: one card per swipe,
     tap zones, safe areas
   - keyboard ← → Esc
   - VoiceOver announces "2 of 3: …"
   - reduced motion
   - 320px width with a long artist name
   - JS disabled
   - the `#card-3` deep link
8. **Optional AI:** set `ANTHROPIC_API_KEY` and regenerate; the summary headline
   changes and stays one sentence. Then use an invalid key: the template is
   still used and the log shows the error.
9. **Lazy mode:** set `last_synced_at` to 20 minutes ago and load `/`, which
   triggers a sync. Delete the weekly wrap and reload, and one is auto-created.
10. **Dashboard:** open `/stats`.
    - Overview lists match a quick `sqlite3` count.
    - Switch the period pills.
    - The first load fills about 20 images and a refresh fills more.
    - `/stats/insights` hour columns peak at your real listening hours (local
      time).
    - See-all tabs show 50 rows.
    - Check 320 px wide, keyboard tabbing through the tabs and pills, and JS
      disabled.

## Deliberately skipped (add when needed)

| Skipped | Add it when… |
|---|---|
| Login and hosting | You want it reachable from anywhere |
| Playlists tab (volt.fm) | You want it (needs the `playlist-read-private` scope) |
| Mood sliders, popularity chart, follower count (volt.fm) | Spotify gives the data back (removed in 2024–2026) |
| Warming dashboard images in `wrapped:sync`, or `Http::pool` lookups | First dashboard loads feel slow |
| Caching dashboard queries | All-time feels slow |
| Dashboard footer totals (volt.fm's site-wide counts) | Never; they're about volt.fm, not you |
| Queued import | Herd timeouts actually happen |
| Automatic 429 retry | Rate limits are actually hit |
| Share button (`navigator.share`) | You want to send cards to friends |
| Card transition animations | Wanted (behind reduced-motion) |
| "Rising genre" and "rediscovery" candidates | Cards feel samey |
| Legacy export filenames (`endsong_*.json`) | Someone has an old export |
| Disconnect button | Wanted. Revoking at spotify.com covers it for now. |
