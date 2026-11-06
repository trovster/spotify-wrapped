<?php

namespace App\View\Components;

use App\Models\Wrap;
use App\Wrapped\CardNumberFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class WrapTile extends Component
{
    public string $periodLabel;

    public string $headline;

    public string $dateRange;

    public function __construct(public Wrap $wrap, public string $returnTo = 'wraps')
    {
        $this->periodLabel = $wrap->period->label();
        $this->headline = CardNumberFormatter::text($wrap->cards[0]['headline'] ?? 'Your Wrapped');

        $startsAt = $wrap->starts_at?->timezone(config('app.display_timezone'));
        $endsAt = $wrap->ends_at->timezone(config('app.display_timezone'));

        $this->dateRange = ($startsAt ? $startsAt->format('j M').' — ' : '').$endsAt->format('j M Y');
    }

    public function render(): View
    {
        return view('components.wrap-tile');
    }
}
