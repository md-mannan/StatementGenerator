<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\StatementEntry;
use App\Models\User;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use App\Support\StatementPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;

class StatementImportService
{
    /**
     * @return array{imported: int, skipped: int, year: int, month: int}
     */
    public function import(
        Branch $branch,
        User $user,
        string $filePath,
        ?int $statementYear = null,
        ?int $statementMonth = null,
    ): array {
        $rows = Excel::toCollection(new StatementRowsImport, $filePath)->first() ?? collect();

        $imported = 0;
        $skipped = 0;
        $periodCounts = [];

        foreach ($rows as $row) {
            $parsed = $this->parseRow($row);

            if ($parsed === null) {
                $skipped++;

                continue;
            }

            $billDate = Carbon::parse($parsed['transaction_date']);
            $period = StatementPeriod::resolve($statementYear, $statementMonth, $billDate);

            StatementEntry::query()->create([
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'transaction_date' => $parsed['transaction_date'],
                'statement_year' => $period['statement_year'],
                'statement_month' => $period['statement_month'],
                'invoice_no' => $parsed['invoice_no'],
                'amount' => $parsed['amount'],
            ]);

            $imported++;

            $periodKey = $period['statement_year'].'-'.$period['statement_month'];
            $periodCounts[$periodKey] = ($periodCounts[$periodKey] ?? 0) + 1;
        }

        [$year, $month] = $this->resolveRedirectPeriod($periodCounts, $statementYear, $statementMonth);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'year' => $year,
            'month' => $month,
        ];
    }

    /**
     * @param  Collection<int|string, mixed>  $row
     * @return array{transaction_date: string, invoice_no: string, amount: float}|null
     */
    private function parseRow(Collection $row): ?array
    {
        $date = $this->extractValue($row, ['date', 'transaction_date']);
        $invoiceNo = $this->extractValue($row, ['invoice_no', 'invoice_no', 'invoice', 'invoice_number']);
        $amount = $this->extractValue($row, ['amount', 'total', 'value']);

        if ($date === null || $invoiceNo === null || $amount === null) {
            return null;
        }

        $parsedDate = StatementDate::parse($date);

        if ($parsedDate === null) {
            return null;
        }

        $parsedAmount = StatementAmount::parse($amount);

        if ($parsedAmount === null) {
            return null;
        }

        return [
            'transaction_date' => $parsedDate->toDateString(),
            'invoice_no' => (string) $invoiceNo,
            'amount' => $parsedAmount,
        ];
    }

    /**
     * @param  Collection<int|string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function extractValue(Collection $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if ($row->has($key) && $row->get($key) !== null && $row->get($key) !== '') {
                return $row->get($key);
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $periodCounts
     * @return array{0: int, 1: int}
     */
    private function resolveRedirectPeriod(
        array $periodCounts,
        ?int $statementYear,
        ?int $statementMonth,
    ): array {
        if ($statementYear !== null && $statementMonth !== null) {
            return [$statementYear, $statementMonth];
        }

        if ($periodCounts === []) {
            return [now()->year, now()->month];
        }

        arsort($periodCounts);
        $period = (string) array_key_first($periodCounts);
        [$year, $month] = array_map(intval(...), explode('-', $period));

        return [$year, $month];
    }
}

class StatementRowsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $collection): Collection
    {
        return $collection;
    }
}
