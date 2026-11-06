<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public $timestamps = false;

    public static function read(string $key, ?string $default = null): ?string
    {
        return rescue(fn (): ?string => self::query()->where('key', $key)->first()?->value ?? $default, $default, report: false);
    }

    public static function write(string $key, ?string $value): void
    {
        if ($value === null) {
            self::query()->where('key', $key)->delete();

            return;
        }

        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
