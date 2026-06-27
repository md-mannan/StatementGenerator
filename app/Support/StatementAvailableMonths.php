<?php

namespace App\Support;

use App\Models\StatementEntry;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

class StatementAvailableMonths
{
    /**
     * @param  iterable<int>  $branchIds
     * @return Collection<int, array{year: int, month: int, label: string}>
     */
    public static function forBranchIds(iterable $branchIds): Collection
    {
        $branchIds = collect($branchIds)->filter()->values();

        if ($branchIds->isEmpty()) {
            return collect();
        }

        $connection = StatementEntry::query()->getConnection();
        [$yearExpression, $monthExpression, $groupByExpression] = self::invoiceMonthSql($connection);

        return StatementEntry::query()
            ->whereIn('branch_id', $branchIds)
            ->selectRaw("{$yearExpression} as year, {$monthExpression} as month")
            ->groupByRaw($groupByExpression)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get()
            ->map(fn ($row): array => [
                'year' => (int) $row->year,
                'month' => (int) $row->month,
                'label' => Carbon::create((int) $row->year, (int) $row->month, 1)->format('F Y'),
            ])
            ->values();
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function invoiceMonthSql(Connection $connection): array
    {
        return match ($connection->getDriverName()) {
            'sqlite' => [
                "CAST(strftime('%Y', transaction_date) AS INTEGER)",
                "CAST(strftime('%m', transaction_date) AS INTEGER)",
                "strftime('%Y', transaction_date), strftime('%m', transaction_date)",
            ],
            'pgsql' => [
                'EXTRACT(YEAR FROM transaction_date)::integer',
                'EXTRACT(MONTH FROM transaction_date)::integer',
                'EXTRACT(YEAR FROM transaction_date), EXTRACT(MONTH FROM transaction_date)',
            ],
            default => [
                'YEAR(transaction_date)',
                'MONTH(transaction_date)',
                'YEAR(transaction_date), MONTH(transaction_date)',
            ],
        };
    }
}
