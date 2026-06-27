<?php

namespace App\Support;

use DateTimeInterface;

class StatementPeriod
{
    /**
     * @return array{statement_year: int, statement_month: int}
     */
    public static function resolve(
        ?int $year,
        ?int $month,
        ?DateTimeInterface $fallbackDate = null,
    ): array {
        if ($year !== null && $month !== null && $year > 0 && $month > 0) {
            return [
                'statement_year' => $year,
                'statement_month' => $month,
            ];
        }

        if ($fallbackDate !== null) {
            return [
                'statement_year' => (int) $fallbackDate->format('Y'),
                'statement_month' => (int) $fallbackDate->format('n'),
            ];
        }

        return [
            'statement_year' => now()->year,
            'statement_month' => now()->month,
        ];
    }

    public static function invoiceDateDiffersFromPeriod(
        DateTimeInterface $invoiceDate,
        int $statementYear,
        int $statementMonth,
    ): bool {
        return (int) $invoiceDate->format('Y') !== $statementYear
            || (int) $invoiceDate->format('n') !== $statementMonth;
    }
}
