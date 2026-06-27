<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAnnexureEntry;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use App\Support\StatementPeriod;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class IncomingStatementComparisonService
{
    /**
     * @return array<string, float>
     */
    public function supplierAmountLookup(Client $client, int $year, int $month): array
    {
        return $this->supplierAmountLookupByInvoiceMonth($client, [
            ['year' => $year, 'month' => $month],
        ]);
    }

    /**
     * @param  iterable<int, array{year: int, month: int}>  $periods
     * @return array<string, float>
     */
    public function supplierAmountLookupForPeriods(Client $client, iterable $periods): array
    {
        $branchIds = $client->branches()->pluck('id');

        if ($branchIds->isEmpty()) {
            return [];
        }

        $lookup = [];

        foreach ($this->uniquePeriods($periods) as $period) {
            $entries = StatementEntry::query()
                ->whereIn('branch_id', $branchIds)
                ->forMonth($period['year'], $period['month'])
                ->get();

            foreach ($entries as $entry) {
                $key = $this->periodLookupKey(
                    $period['year'],
                    $period['month'],
                    $entry->branch_id,
                    $entry->invoice_no,
                );

                $lookup[$key] = ($lookup[$key] ?? 0) + (float) $entry->amount;
            }
        }

        return $lookup;
    }

    /**
     * @param  Collection<int, ClientAnnexureEntry>  $entries
     * @return array<string, float>
     */
    public function supplierAmountLookupForAnnexureEntries(
        Client $client,
        Collection $entries,
    ): array {
        $periods = $entries
            ->map(fn (ClientAnnexureEntry $entry): array => [
                'year' => $entry->transaction_date->year,
                'month' => $entry->transaction_date->month,
            ])
            ->unique(fn (array $period): string => $period['year'].'-'.$period['month'])
            ->values()
            ->all();

        if ($periods === []) {
            return [];
        }

        return $this->supplierAmountLookupByInvoiceMonth($client, $periods);
    }

    /**
     * @param  iterable<int, array{year: int, month: int}>  $periods
     * @return array<string, float>
     */
    public function supplierAmountLookupByInvoiceMonth(Client $client, iterable $periods): array
    {
        $branchIds = $client->branches()->pluck('id');

        if ($branchIds->isEmpty()) {
            return [];
        }

        $lookup = [];

        foreach ($this->uniquePeriods($periods) as $period) {
            $entries = StatementEntry::query()
                ->whereIn('branch_id', $branchIds)
                ->forInvoiceMonth($period['year'], $period['month'])
                ->get();

            foreach ($entries as $entry) {
                $key = $this->periodLookupKey(
                    $period['year'],
                    $period['month'],
                    $entry->branch_id,
                    $entry->invoice_no,
                );

                $lookup[$key] = ($lookup[$key] ?? 0) + (float) $entry->amount;
            }
        }

        return $lookup;
    }

    /**
     * @param  iterable<int>  $branchIds
     * @param  iterable<int, array{year: int, month: int}>  $periods
     * @return array<string, float>
     */
    public function clientStatementAmountLookup(
        Client $client,
        iterable $branchIds,
        iterable $periods,
    ): array {
        $branchIds = collect($branchIds)->filter()->values();

        if ($branchIds->isEmpty()) {
            return [];
        }

        $branchLookups = $this->branchIdLookup($client);

        $query = IncomingStatementEntry::query()
            ->where('client_id', $client->id)
            ->where(function ($query) use ($branchIds): void {
                $query->whereIn('branch_id', $branchIds)
                    ->orWhereNull('branch_id');
            });

        $periodList = $this->uniquePeriods($periods);

        if ($periodList !== []) {
            $query->forInvoiceMonths($periodList);
        }

        $lookup = [];

        foreach ($query->get() as $entry) {
            $invoiceYear = $entry->transaction_date->year;
            $invoiceMonth = $entry->transaction_date->month;

            $branchId = $this->resolveBranchId(
                $invoiceYear,
                $invoiceMonth,
                $entry->invoice_no,
                $entry->branch_id,
                $branchLookups['byPeriod'],
                $branchLookups['byInvoice'],
            );

            if ($branchId === null || ! $branchIds->contains($branchId)) {
                continue;
            }

            $key = $this->periodLookupKey(
                $invoiceYear,
                $invoiceMonth,
                $branchId,
                $entry->invoice_no,
            );

            $lookup[$key] = ($lookup[$key] ?? 0) + (float) $entry->amount;
        }

        return $lookup;
    }

    /**
     * @return array{
     *     byPeriod: array<string, int>,
     *     byInvoice: array<string, int>,
     * }
     */
    public function branchIdLookup(Client $client): array
    {
        $branchIds = $client->branches()->pluck('id');

        if ($branchIds->isEmpty()) {
            return ['byPeriod' => [], 'byInvoice' => []];
        }

        $byPeriod = [];
        $byInvoice = [];

        $entries = StatementEntry::query()
            ->whereIn('branch_id', $branchIds)
            ->with('branch:id,code')
            ->get(['branch_id', 'transaction_date', 'invoice_no']);

        foreach ($entries as $entry) {
            $periodKey = $this->periodInvoiceLookupKey(
                $entry->transaction_date->year,
                $entry->transaction_date->month,
                $entry->invoice_no,
            );

            $byPeriod[$periodKey] ??= $entry->branch_id;
        }

        $entries
            ->groupBy(fn (StatementEntry $entry): string => trim($entry->invoice_no))
            ->each(function (Collection $groupedEntries, string $invoiceNo) use (&$byInvoice): void {
                $branchId = $groupedEntries
                    ->unique('branch_id')
                    ->sortBy(fn (StatementEntry $entry): string => $entry->branch->code)
                    ->first()
                    ?->branch_id;

                if ($branchId !== null) {
                    $byInvoice[trim($invoiceNo)] = $branchId;
                }
            });

        return ['byPeriod' => $byPeriod, 'byInvoice' => $byInvoice];
    }

    public function resolveBranchId(
        int $statementYear,
        int $statementMonth,
        string $invoiceNo,
        ?int $branchId,
        array $branchLookupByPeriod,
        array $branchLookupByInvoice,
    ): ?int {
        if ($branchId !== null) {
            return $branchId;
        }

        $periodKey = $this->periodInvoiceLookupKey(
            $statementYear,
            $statementMonth,
            $invoiceNo,
        );

        return $branchLookupByPeriod[$periodKey]
            ?? $branchLookupByInvoice[trim($invoiceNo)]
            ?? null;
    }

    /**
     * @param  array<string, float>  $supplierLookup
     * @param  array<string, int>  $branchLookupByPeriod
     * @param  array<string, int>  $branchLookupByInvoice
     * @param  array<int, string>  $branchCodeById
     * @param  array<int, string>  $branchNameById
     * @return array<string, mixed>
     */
    public function mapEntry(
        IncomingStatementEntry $entry,
        array $supplierLookup,
        array $branchLookupByPeriod = [],
        array $branchLookupByInvoice = [],
        array $branchCodeById = [],
        array $branchNameById = [],
    ): array {
        $invoiceYear = $entry->transaction_date->year;
        $invoiceMonth = $entry->transaction_date->month;

        $suggestedBranchId = $this->resolveBranchId(
            $invoiceYear,
            $invoiceMonth,
            $entry->invoice_no,
            null,
            $branchLookupByPeriod,
            $branchLookupByInvoice,
        );

        $effectiveBranchId = $entry->branch_id ?? $suggestedBranchId;
        $effectiveBranchCode = $entry->branch?->code
            ?? ($effectiveBranchId !== null
                ? ($branchCodeById[$effectiveBranchId] ?? null)
                : null);

        $mapped = $this->mapComparableEntry(
            id: $entry->id,
            branchId: $effectiveBranchId,
            branchCode: $effectiveBranchCode,
            transactionDate: $entry->transaction_date,
            invoiceNo: $entry->invoice_no,
            amount: (float) $entry->amount,
            supplierLookup: $supplierLookup,
            statementYear: $invoiceYear,
            statementMonth: $invoiceMonth,
        );

        $mapped['branch_id'] = $entry->branch_id;
        $mapped['branch_code'] = $entry->branch?->code
            ?? ($suggestedBranchId !== null
                ? ($branchCodeById[$suggestedBranchId] ?? null)
                : null);
        $mapped['branch_name'] = $entry->branch?->name
            ?? ($suggestedBranchId !== null
                ? ($branchNameById[$suggestedBranchId] ?? null)
                : null);
        $mapped['suggested_branch_id'] = $entry->branch_id === null
            ? $suggestedBranchId
            : null;
        $mapped['is_resolved'] = $entry->branch_id !== null;
        $mapped['no_branch_expected'] = $entry->no_branch_expected;
        $mapped['statement_period'] = Carbon::create($invoiceYear, $invoiceMonth, 1)->format('M Y');

        $mapped['invoice_date_differs_from_period'] = StatementPeriod::invoiceDateDiffersFromPeriod(
            $entry->transaction_date,
            $entry->statement_year ?? $invoiceYear,
            $entry->statement_month ?? $invoiceMonth,
        );

        return $mapped;
    }

    /**
     * @param  array<string, float>  $supplierLookup
     * @return array<string, mixed>
     */
    public function mapAnnexureEntry(
        ClientAnnexureEntry $entry,
        array $supplierLookup,
    ): array {
        $mapped = $this->mapComparableEntry(
            id: $entry->id,
            branchId: $entry->branch_id,
            branchCode: $entry->branch?->code,
            transactionDate: $entry->transaction_date,
            invoiceNo: $entry->invoice_no,
            amount: (float) $entry->amount,
            supplierLookup: $supplierLookup,
            statementYear: $entry->transaction_date->year,
            statementMonth: $entry->transaction_date->month,
        );

        $mapped['no_branch_expected'] = $entry->no_branch_expected;

        return $mapped;
    }

    /**
     * @param  array<string, float>  $supplierLookup
     * @return array<string, mixed>
     */
    private function mapComparableEntry(
        int $id,
        ?int $branchId,
        ?string $branchCode,
        \DateTimeInterface $transactionDate,
        string $invoiceNo,
        float $amount,
        array $supplierLookup,
        ?int $statementYear = null,
        ?int $statementMonth = null,
    ): array {
        $resolvedStatementYear = $statementYear ?? (int) $transactionDate->format('Y');
        $resolvedStatementMonth = $statementMonth ?? (int) $transactionDate->format('n');

        $branchAmount = null;
        $difference = null;

        if ($branchId !== null) {
            $key = $this->periodLookupKey(
                $resolvedStatementYear,
                $resolvedStatementMonth,
                $branchId,
                $invoiceNo,
            );

            if (array_key_exists($key, $supplierLookup)) {
                $branchAmount = $supplierLookup[$key];
                $difference = $amount - $branchAmount;
            }
        }

        return [
            'id' => $id,
            'branch_id' => $branchId,
            'branch_code' => $branchCode,
            'transaction_date' => StatementDate::format($transactionDate),
            'invoice_no' => $invoiceNo,
            'amount' => StatementAmount::format($amount),
            'amount_value' => $amount,
            'branch_amount' => $branchAmount !== null
                ? StatementAmount::format($branchAmount)
                : null,
            'branch_amount_value' => $branchAmount,
            'difference_amount' => $difference !== null
                ? StatementAmount::format($difference)
                : null,
            'difference_amount_value' => $difference,
            'is_resolved' => $branchId !== null,
            'has_difference' => $difference !== null
                && abs($difference) >= 0.0005,
            'invoice_date_differs_from_period' => StatementPeriod::invoiceDateDiffersFromPeriod(
                $transactionDate,
                $resolvedStatementYear,
                $resolvedStatementMonth,
            ),
        ];
    }

    /**
     * @param  iterable<int, array{year: int, month: int}>  $periods
     * @return list<array{year: int, month: int}>
     */
    private function uniquePeriods(iterable $periods): array
    {
        return collect($periods)
            ->filter(fn (array $period): bool => $period['year'] > 0 && $period['month'] > 0)
            ->unique(fn (array $period): string => $period['year'].'-'.$period['month'])
            ->values()
            ->all();
    }

    private function periodLookupKey(
        int $year,
        int $month,
        int $branchId,
        string $invoiceNo,
    ): string {
        return $year.'-'.$month.'|'.$branchId.'|'.trim($invoiceNo);
    }

    private function periodInvoiceLookupKey(
        int $year,
        int $month,
        string $invoiceNo,
    ): string {
        return $year.'-'.$month.'|'.trim($invoiceNo);
    }
}
