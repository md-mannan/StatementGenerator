<?php

namespace App\Support;

class StatementAmount
{
    public const DECIMAL_PLACES = 3;

    public static function format(float|int|string $amount): string
    {
        return number_format((float) $amount, self::DECIMAL_PLACES, '.', '');
    }

    public static function parse(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, self::DECIMAL_PLACES);
        }

        $normalized = preg_replace('/[^\d.\-]/', '', (string) $value) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, self::DECIMAL_PLACES);
    }
}
