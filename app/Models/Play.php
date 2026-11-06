<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Play extends Model
{
    public const MIN_MS = 30_000;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['played_at' => 'immutable_datetime', 'ms_played' => 'integer'];
    }
}
