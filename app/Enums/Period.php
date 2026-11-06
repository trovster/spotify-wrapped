<?php

namespace App\Enums;

use App\Models\Play;
use Carbon\CarbonImmutable;

enum Period: string
{
    case Week = 'week';
    case Month = 'month';
    case HalfYear = 'half-year';
    case Year = 'year';
    case AllTime = 'all-time';

    public function days(): ?int
    {
        return match ($this) {
            self::Week => 7,
            self::Month => 30,
            self::HalfYear => 182,
            self::Year => 365,
            self::AllTime => null,
        };
    }

    /** @return array{?CarbonImmutable, CarbonImmutable} */
    public function window(CarbonImmutable $now): array
    {
        $now = $now->utc();

        if ($this->days() !== null) {
            return [$now->subDays($this->days()), $now];
        }

        $first = Play::query()->min('played_at');

        return [$first ? CarbonImmutable::parse($first, 'UTC') : null, $now];
    }

    /** @return array{?CarbonImmutable, CarbonImmutable} */
    public function baseline(CarbonImmutable $now): array
    {
        $now = $now->utc();

        if ($this->days() !== null) {
            return [$now->subDays(2 * $this->days()), $now->subDays($this->days())];
        }

        $first = Play::query()->min('played_at');

        return [$first ? CarbonImmutable::parse($first, 'UTC') : null, $now->subYear()];
    }

    public function label(): string
    {
        return match ($this) {
            self::Week => 'this week',
            self::Month => 'this month',
            self::HalfYear => 'the last 6 months',
            self::Year => 'this year',
            self::AllTime => 'all time',
        };
    }
}
