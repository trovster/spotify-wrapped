<?php

namespace App\View\Components;

use App\Enums\Period;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class PeriodPicker extends Component
{
    /** @var list<array{value: string, label: string}> */
    public array $options;

    public function __construct(public string $selected = 'week')
    {
        $this->options = array_map(fn (Period $period): array => [
            'value' => $period->value,
            'label' => match ($period) {
                Period::Week => '1 week',
                Period::Month => '1 month',
                Period::HalfYear => '6 months',
                Period::Year => '1 year',
                Period::AllTime => 'All time',
            },
        ], Period::cases());
    }

    public function render(): View
    {
        return view('components.period-picker');
    }
}
