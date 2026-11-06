<?php

namespace App\Wrapped;

final class CardNumberFormatter
{
    public static function text(string $value): string
    {
        return preg_replace_callback(
            '/(?<![\d,])\d{4,}(?=%|\s+(?:(?:new|familiar|different|unique)\s+)?(?:minutes?|plays?|artists?|songs?|tracks?)\b)/u',
            static fn (array $matches): string => number_format((int) $matches[0]),
            $value,
        ) ?? $value;
    }

    public static function stat(?string $value): ?string
    {
        if ($value === null || preg_match('/^([+-]?)(\d+)(%)?$/', $value, $parts) !== 1) {
            return $value;
        }

        return $parts[1].number_format((int) $parts[2]).($parts[3] ?? '');
    }
}
