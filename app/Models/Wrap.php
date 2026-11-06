<?php

namespace App\Models;

use App\Enums\Period;
use Illuminate\Database\Eloquent\Model;

class Wrap extends Model
{
    /** @var list<string> */
    public const ACCENTS = ['violet', 'cobalt', 'coral', 'mint', 'rose', 'gold'];

    public static function randomAccent(): string
    {
        return self::ACCENTS[random_int(0, count(self::ACCENTS) - 1)];
    }

    protected function casts(): array
    {
        return [
            'period' => Period::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'cards' => 'array',
        ];
    }
}
