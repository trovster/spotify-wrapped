<?php

namespace App\Http\Controllers;

use App\Enums\Period;
use App\Models\Play;
use App\Models\Setting;
use App\Models\Wrap;
use App\Wrapped\WrappedBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

class WrapController extends Controller
{
    public function home(WrappedBuilder $builder): View
    {
        $now = CarbonImmutable::now('UTC');
        $lastSynced = Setting::read('last_synced_at');
        $lastSyncedAt = $lastSynced ? CarbonImmutable::parse($lastSynced) : null;
        $deletedWeeklyAt = Setting::read('weekly_wrap_deleted_at');
        $recentWeeklyDeletion = $deletedWeeklyAt && CarbonImmutable::parse($deletedWeeklyAt)->greaterThanOrEqualTo($now->subDays(7));

        if (! $recentWeeklyDeletion
            && Play::query()->where('played_at', '>=', $now->subDays(7))->exists()
            && ! Wrap::query()->where('period', Period::Week->value)
                ->where('ends_at', '>=', $now->subDays(7))->exists()) {
            rescue(fn (): Wrap => $builder->build(Period::Week, $now));
        }

        $first = Play::query()->min('played_at');
        $last = Play::query()->max('played_at');

        return view('home', [
            'lastSyncedAt' => $lastSyncedAt,
            'firstPlayedAt' => $first ? CarbonImmutable::parse($first, 'UTC') : null,
            'lastPlayedAt' => $last ? CarbonImmutable::parse($last, 'UTC') : null,
            'playCount' => Play::query()->count(),
            'profileName' => Setting::read('spotify_name') ?: 'Your listening',
            'profileImageUrl' => Setting::read('spotify_image_url'),
            'recentWraps' => Wrap::query()->orderByDesc('ends_at')->orderByDesc('id')->limit(3)->get(),
        ]);
    }

    public function index(): View
    {
        return view('wraps.index', [
            'wraps' => Wrap::query()->orderByDesc('ends_at')->get(),
        ]);
    }

    public function store(Request $request, WrappedBuilder $builder): RedirectResponse
    {
        $validated = $request->validate(['period' => ['required', Rule::enum(Period::class)]]);
        $period = Period::from($validated['period']);
        $wrap = $builder->build($period, CarbonImmutable::now('UTC'));

        return redirect()->route('wraps.show', $wrap);
    }

    public function show(Wrap $wrap): View
    {
        return view('wraps.show', compact('wrap'));
    }

    public function destroy(Request $request, Wrap $wrap): RedirectResponse
    {
        $now = CarbonImmutable::now('UTC');
        if ($wrap->period === Period::Week && $wrap->ends_at->greaterThanOrEqualTo($now->subDays(7))) {
            Setting::write('weekly_wrap_deleted_at', $now->toIso8601String());
        }
        $wrap->delete();

        $returnRoute = $request->input('return_to') === 'home' ? 'home' : 'wraps.index';

        return redirect()->route($returnRoute)->with('status', 'Wrapped story deleted.');
    }
}
