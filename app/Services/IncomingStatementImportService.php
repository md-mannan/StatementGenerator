<?php

namespace App\Services;

use App\Models\Client;
use App\Models\IncomingStatementEntry;
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

class IncomingStatementImportService
{
    /**
     * @return array{imported: int, skipped: int, unresolved: int, year: int, month: int}
     */
    public function import(
        Client $client,
        User $user,
        string $filePath,
        ?int $statementYear = null,
        ?int $statementMonth = null,
    ): array {
        $rows = Excel::toCollection(new IncomingStatementRowsImport, $filePath)->first() ?? collect();
        $branchLookup = $this->buildBranchLookup($client);

        $imported = 0;
        $skipped = 0;
        $unresolved = 0;
        $invoiceMonthCounts = [];

        foreach ($rows as $row) {
            $parsed = $this->parseRow($row);

            if ($parsed === null) {
                $skipped++;

                continue;
            }

            $branchId = $branchLookup[$parsed['invoice_no']] ?? null;

            if ($branchId === null) {
                $unresolved++;
            }

            $billDate = Carbon::parse($parsed['transaction_date']);
            $period = StatementPeriod::resolve($statementYear, $statementMonth, $billDate);

            IncomingStatementEntry::query()->create([
                'client_id' => $client->id,
                'user_id' => $user->id,
                'branch_id' => $branchId,
                'transaction_date' => $parsed['transaction_date'],
                'statement_year' => $period['statement_year'],
                'statement_month' => $period['statement_month'],
                'invoice_no' => $parsed['invoice_no'],
                'amount' => $parsed['amount'],
            ]);

            $imported++;

            $invoiceMonthKey = $billDate->year.'-'.$billDate->month;
            $invoiceMonthCounts[$invoiceMonthKey] = ($invoiceMonthCounts[$invoiceMonthKey] ?? 0) + 1;
        }

        [$year, $month] = $this->resolveRedirectPeriod($invoiceMonthCounts);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'unresolved' => $unresolved,
            'year' => $year,
            'month' => $month,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function buildBranchLookup(Client $client): array
    {
        return StatementEntry::query()
            ->whereHas('branch', fn ($query) => $query->where('client_id', $client->id))
            ->with('branch')
            ->get()
            ->groupBy(fn (StatementEntry $entry): string => $this->normalizeInvoiceNo($entry->invoice_no))
            ->mapWithKeys(function (Collection $entries, string $invoiceNo): array {
                $uniqueBranches = $entries
                    ->unique('branch_id')
                    ->sortBy(fn (StatementEntry $entry): string => $entry->branch->code)
                    ->values();

                if ($uniqueBranches->isEmpty()) {
                    return [];
                }

                return [$invoiceNo => $uniqueBranches->first()->branch_id];
            })
            ->all();
    }

    /**
     * @param  Collection<int|string, mixed>  $row
     * @return array{transaction_date: string, invoice_no: string, amount: float}|null
     */
    private function parseRow(Collection $row): ?array
    {
        $date = $this->extractValue($row, ['date', 'transaction_date']);
        $invoiceNo = $this->extractValue($row, ['invoice_no', 'invoice', 'invoice_number']);
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
            'invoice_no' => $this->normalizeInvoiceNo((string) $invoiceNo),
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

    private function normalizeInvoiceNo(string $invoiceNo): string
    {
        return trim($invoiceNo);
    }

    /**
     * @param  array<string, int>  $invoiceMonthCounts
     * @return array{0: int, 1: int}
     */
    private function resolveRedirectPeriod(array $invoiceMonthCounts): array
    {
        if ($invoiceMonthCounts === []) {
            return [now()->year, now()->month];
        }

        arsort($invoiceMonthCounts);
        $period = (string) array_key_first($invoiceMonthCounts);
        [$year, $month] = array_map(intval(...), explode('-', $period));

        return [$year, $month];
    }
}

class IncomingStatementRowsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $collection): Collection
    {
        return $collection;
    }
}
