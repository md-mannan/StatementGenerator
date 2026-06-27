<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class StatementDate
{
    public const FORMAT = 'd/m/Y';

    /**
     * @var list<string>
     */
    private const PARSE_FORMATS = [
        'd/m/Y',
        'j/n/Y',
        'd-m-Y',
        'j-n-Y',
        'd/m/y',
        'j/n/y',
    ];

    public static function format(Carbon|\DateTimeInterface|string $date): string
    {
        if (is_string($date)) {
            $date = Carbon::parse($date);
        }

        return $date->format(self::FORMAT);
    }

    public static function parse(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(Carbon::parse($value))->startOfDay();
        }

        $string = trim((string) $value);

        if (is_numeric($string)) {
            return self::parseExcelSerial((float) $string);
        }

        foreach (self::PARSE_FORMATS as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $string);

                if ($parsed !== false) {
                    return $parsed->startOfDay();
                }
            } catch (InvalidFormatException) {
                continue;
            }
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $string)) {
            try {
                return Carbon::parse($string)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/[\/\-]/', $string)) {
            return null;
        }

        try {
            return Carbon::parse($string)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function parseExcelSerial(float $value): ?Carbon
    {
        if ($value < 36526 || $value > 55154) {
            return null;
        }

        try {
            $parsed = Carbon::instance(ExcelDate::excelToDateTimeObject($value));

            if ($parsed->year < 2000 || $parsed->year > 2100) {
                return null;
            }

            return $parsed->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
