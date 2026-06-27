<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAnnexureEntry;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CrossCheckService
{
    private const AMOUNT_TOLERANCE = 0.0005;

    private const INVOICE_DATE_PRIORITY_BRANCH = 3;

    private const INVOICE_DATE_PRIORITY_RECEIVED = 2;

    private const INVOICE_DATE_PRIORITY_ANNEXURE = 1;

    /**
     * @return array{
     *     rows: Collection<int, array<string, mixed>>,
     *     branchTotal: float,
     *     receivedTotal: float,
     *     annexureTotal: float,
     *     matchedCount: int,
     *     completeCount: int,
     *     mismatchCount: int,
     *     incompleteCount: int,
     * }
     */
    public function resolve(
        Client $client,
        Collection $periods,
        Collection $filterBranchIds,
    ): array {
        $branchIds = $client->branches()->pluck('id');

        if ($branchIds->isEmpty()) {
            return $this->emptyResult();
        }

        /** @var Collection<int, string> $branchCodeById */
        $branchCodeById = $client->branches()->pluck('code', 'id');

        [$branchLookupByPeriod, $branchLookupGlobal] = $this->buildBranchLookups($branchIds);

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        $branchQuery = StatementEntry::query()
            ->whereIn('branch_id', $branchIds);

        if ($filterBranchIds->isNotEmpty()) {
            $branchQuery->whereIn('branch_id', $filterBranchIds);
        }

        if ($periods->isNotEmpty()) {
            $branchQuery->forInvoiceMonths($periods);
        }

        foreach ($branchQuery->get() as $entry) {
            $this->accumulateBranchRow($rows, $entry, $branchCodeById);
        }

        $incomingQuery = IncomingStatementEntry::query()
            ->where('client_id', $client->id)
            ->with('branch:id,code,name');

        if ($filterBranchIds->isNotEmpty()) {
            $this->applyRelatedBranchFilter($incomingQuery, $filterBranchIds, $branchLookupGlobal);
        }

        if ($periods->isNotEmpty()) {
            $incomingQuery->forInvoiceMonths($periods);
        }

        foreach ($incomingQuery->get() as $entry) {
            $this->accumulateReceivedRow(
                $rows,
                $entry,
                $branchCodeById,
                $branchLookupByPeriod,
                $branchLookupGlobal,
            );
        }

        $annexureQuery = ClientAnnexureEntry::query()
            ->where('client_id', $client->id)
            ->with([
                'branch:id,code,name',
                'annexureCheque:id,year,month,check_number,payment_saved',
            ]);

        if ($filterBranchIds->isNotEmpty()) {
            $this->applyRelatedBranchFilter($annexureQuery, $filterBranchIds, $branchLookupGlobal);
        }

        if ($periods->isNotEmpty()) {
            $this->applyAnnexureInvoiceMonthFilter($annexureQuery, $periods);
        }

        foreach ($annexureQuery->get() as $entry) {
            $this->accumulateAnnexureRow(
                $rows,
                $entry,
                $branchCodeById,
                $branchLookupByPeriod,
                $branchLookupGlobal,
            );
        }

        $mapped = collect($rows)
            ->map(fn (array $row): array => $this->finalizeRow($row))
            ->when(
                $periods->isNotEmpty(),
                fn (Collection $rows) => $rows->filter(
                    fn (array $row): bool => $this->rowMatchesInvoicePeriods($row, $periods),
                ),
            )
            ->sortBy(fn (array $row): array => [
                $row['statement_year'],
                $row['statement_month'],
                $row['branch_code'] ?? 'ZZZ',
                $row['invoice_no'],
            ])
            ->values();

        return [
            'rows' => $mapped,
            'branchTotal' => (float) $mapped->sum('branch_amount_value'),
            'receivedTotal' => (float) $mapped->sum('received_amount_value'),
            'annexureTotal' => (float) $mapped->sum('annexure_amount_value'),
            'matchedCount' => $mapped->where('status', 'matched')->count(),
            'completeCount' => $mapped->where('status', 'complete')->count(),
            'mismatchCount' => $mapped->where('status', 'mismatch')->count(),
            'incompleteCount' => $mapped->where('status', 'incomplete')->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveInvoice(Client $client, string $invoiceNo): ?array
    {
        $normalized = $this->normalizeInvoiceNo($invoiceNo);

        if ($normalized === '') {
            return null;
        }

        $branchIds = $client->branches()->pluck('id');

        if ($branchIds->isEmpty()) {
            return null;
        }

        /** @var Collection<int, string> $branchCodeById */
        $branchCodeById = $client->branches()->pluck('code', 'id');

        [$branchLookupByPeriod, $branchLookupGlobal] = $this->buildBranchLookups($branchIds);

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        foreach (
            StatementEntry::query()
                ->whereIn('branch_id', $branchIds)
                ->whereRaw('TRIM(invoice_no) = ?', [$normalized])
                ->get() as $entry
        ) {
            $this->accumulateBranchRow($rows, $entry, $branchCodeById);
        }

        foreach (
            IncomingStatementEntry::query()
                ->where('client_id', $client->id)
                ->whereRaw('TRIM(invoice_no) = ?', [$normalized])
                ->with('branch:id,code,name')
                ->get() as $entry
        ) {
            $this->accumulateReceivedRow(
                $rows,
                $entry,
                $branchCodeById,
                $branchLookupByPeriod,
                $branchLookupGlobal,
            );
        }

        foreach (
            ClientAnnexureEntry::query()
                ->where('client_id', $client->id)
                ->whereRaw('TRIM(invoice_no) = ?', [$normalized])
                ->with([
                    'branch:id,code,name',
                    'annexureCheque:id,year,month,check_number,payment_saved',
                ])
                ->get() as $entry
        ) {
            $this->accumulateAnnexureRow(
                $rows,
                $entry,
                $branchCodeById,
                $branchLookupByPeriod,
                $branchLookupGlobal,
            );
        }

        $row = $rows[$this->rowKey($normalized)] ?? null;

        if ($row === null) {
            return null;
        }

        return $this->finalizeRow($row);
    }

    /**
     * @return Collection<int, array{year: int, month: int, label: string}>
     */
    public function availablePeriods(Client $client): Collection
    {
        $branchIds = $client->branches()->pluck('id');

        $periods = collect();

        if ($branchIds->isNotEmpty()) {
            $periods = $periods->merge(
                StatementEntry::query()
                    ->whereIn('branch_id', $branchIds)
                    ->get(['transaction_date'])
                    ->map(fn (StatementEntry $entry): array => [
                        'year' => $entry->transaction_date->year,
                        'month' => $entry->transaction_date->month,
                    ]),
            );
        }

        $periods = $periods->merge(
            IncomingStatementEntry::query()
                ->where('client_id', $client->id)
                ->get(['transaction_date'])
                ->map(fn (IncomingStatementEntry $entry): array => [
                    'year' => $entry->transaction_date->year,
                    'month' => $entry->transaction_date->month,
                ]),
        );

        $periods = $periods->merge(
            ClientAnnexureEntry::query()
                ->where('client_id', $client->id)
                ->get(['transaction_date'])
                ->map(fn (ClientAnnexureEntry $entry): array => [
                    'year' => $entry->transaction_date->year,
                    'month' => $entry->transaction_date->month,
                ]),
        );

        return $periods
            ->unique(fn (array $period): string => $period['year'].'-'.$period['month'])
            ->sortByDesc(fn (array $period): string => sprintf('%04d-%02d', $period['year'], $period['month']))
            ->values()
            ->map(fn (array $period): array => [
                'year' => $period['year'],
                'month' => $period['month'],
                'label' => Carbon::create($period['year'], $period['month'], 1)->format('F Y'),
            ]);
    }

    /**
     * @param  Collection<int, int>  $branchIds
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function buildBranchLookups(Collection $branchIds): array
    {
        $lookupByPeriod = [];
        $lookupGlobal = [];

        $entries = StatementEntry::query()
            ->whereIn('branch_id', $branchIds)
            ->with('branch:id,code')
            ->get(['branch_id', 'transaction_date', 'invoice_no']);

        foreach ($entries as $entry) {
            $lookupKey = $this->periodInvoiceLookupKey(
                $entry->transaction_date->year,
                $entry->transaction_date->month,
                $entry->invoice_no,
            );

            $lookupByPeriod[$lookupKey] ??= $entry->branch_id;
        }

        $entries
            ->groupBy(fn (StatementEntry $entry): string => $this->normalizeInvoiceNo($entry->invoice_no))
            ->each(function (Collection $groupedEntries, string $invoiceNo) use (&$lookupGlobal): void {
                $branchId = $groupedEntries
                    ->unique('branch_id')
                    ->sortBy(fn (StatementEntry $entry): string => $entry->branch->code)
                    ->first()
                    ?->branch_id;

                if ($branchId !== null) {
                    $lookupGlobal[$invoiceNo] = $branchId;
                }
            });

        return [$lookupByPeriod, $lookupGlobal];
    }

    /**
     * @param  array<string, int>  $branchLookupByPeriod
     * @param  array<string, int>  $branchLookupGlobal
     */
    private function resolveBranchId(
        int $statementYear,
        int $statementMonth,
        string $invoiceNo,
        ?int $branchId,
        array $branchLookupByPeriod,
        array $branchLookupGlobal,
    ): ?int {
        if ($branchId !== null) {
            return $branchId;
        }

        $periodKey = $this->periodInvoiceLookupKey($statementYear, $statementMonth, $invoiceNo);

        return $branchLookupByPeriod[$periodKey]
            ?? $branchLookupGlobal[$this->normalizeInvoiceNo($invoiceNo)]
            ?? null;
    }

    /**
     * @param  array<string, int>  $branchLookupGlobal
     * @return list<string>
     */
    private function invoiceNumbersForBranch(int $branchId, array $branchLookupGlobal): array
    {
        return collect($branchLookupGlobal)
            ->filter(fn (int $resolvedBranchId): bool => $resolvedBranchId === $branchId)
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $filterBranchIds
     * @param  Builder<IncomingStatementEntry>|Builder<ClientAnnexureEntry>  $query
     */
    private function applyRelatedBranchFilter(
        Builder $query,
        Collection $filterBranchIds,
        array $branchLookupGlobal,
    ): void {
        $invoiceNumbers = $filterBranchIds
            ->flatMap(fn (int $branchId): array => $this->invoiceNumbersForBranch($branchId, $branchLookupGlobal))
            ->unique()
            ->values()
            ->all();

        $query->where(function (Builder $query) use ($filterBranchIds, $invoiceNumbers): void {
            $query->whereIn('branch_id', $filterBranchIds)
                ->orWhereNull('branch_id')
                ->orWhereIn('invoice_no', $invoiceNumbers);
        });
    }

    /**
     * @param  Builder<ClientAnnexureEntry>  $query
     * @param  Collection<int, array{year: int, month: int}>  $periods
     */
    private function applyAnnexureInvoiceMonthFilter(Builder $query, Collection $periods): void
    {
        $query->where(function (Builder $query) use ($periods): void {
            foreach ($periods as $period) {
                $query->orWhere(function (Builder $query) use ($period): void {
                    $query->whereYear('transaction_date', $period['year'])
                        ->whereMonth('transaction_date', $period['month']);
                });
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, array{year: int, month: int}>  $periods
     */
    private function rowMatchesInvoicePeriods(array $row, Collection $periods): bool
    {
        foreach ($periods as $period) {
            if ($this->rowMatchesInvoiceMonth($row, $period['year'], $period['month'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     rows: Collection<int, array<string, mixed>>,
     *     branchTotal: float,
     *     receivedTotal: float,
     *     annexureTotal: float,
     *     matchedCount: int,
     *     completeCount: int,
     *     mismatchCount: int,
     *     incompleteCount: int,
     * }
     */
    private function emptyResult(): array
    {
        return [
            'rows' => collect(),
            'branchTotal' => 0,
            'receivedTotal' => 0,
            'annexureTotal' => 0,
            'matchedCount' => 0,
            'completeCount' => 0,
            'mismatchCount' => 0,
            'incompleteCount' => 0,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  Collection<int, string>  $branchCodeById
     */
    private function accumulateBranchRow(
        array &$rows,
        StatementEntry $entry,
        Collection $branchCodeById,
    ): void {
        $key = $this->rowKey($entry->invoice_no);

        $rows[$key] ??= $this->emptyRow(
            $entry->branch_id,
            $branchCodeById->get($entry->branch_id),
            $entry->invoice_no,
        );

        $this->applyBranchToRow($rows[$key], $entry->branch_id, $branchCodeById->get($entry->branch_id));
        $this->applyInvoiceDateToRow($rows[$key], $entry->transaction_date, self::INVOICE_DATE_PRIORITY_BRANCH);
        $this->trackSourceInvoicePeriod($rows[$key], $entry->transaction_date);

        $rows[$key]['has_branch'] = true;
        $rows[$key]['branch_amount'] = ($rows[$key]['branch_amount'] ?? 0) + (float) $entry->amount;
        $rows[$key]['branch_entry_ids'][] = $entry->id;
        $rows[$key]['filed_statement_periods'][] = [
            'year' => (int) ($entry->statement_year ?? $entry->transaction_date->year),
            'month' => (int) ($entry->statement_month ?? $entry->transaction_date->month),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  Collection<int, string>  $branchCodeById
     * @param  array<string, int>  $branchLookupByPeriod
     * @param  array<string, int>  $branchLookupGlobal
     */
    private function accumulateReceivedRow(
        array &$rows,
        IncomingStatementEntry $entry,
        Collection $branchCodeById,
        array $branchLookupByPeriod,
        array $branchLookupGlobal,
    ): void {
        $invoiceYear = $entry->transaction_date->year;
        $invoiceMonth = $entry->transaction_date->month;
        $resolvedBranchId = $this->resolveBranchId(
            $invoiceYear,
            $invoiceMonth,
            $entry->invoice_no,
            $entry->branch_id,
            $branchLookupByPeriod,
            $branchLookupGlobal,
        );
        $key = $this->rowKey($entry->invoice_no);

        $rows[$key] ??= $this->emptyRow(
            $resolvedBranchId,
            $resolvedBranchId !== null
                ? ($entry->branch?->code ?? $branchCodeById->get($resolvedBranchId))
                : null,
            $entry->invoice_no,
        );

        if ($resolvedBranchId !== null) {
            $this->applyBranchToRow(
                $rows[$key],
                $resolvedBranchId,
                $entry->branch?->code ?? $branchCodeById->get($resolvedBranchId),
            );
        }

        $this->applyInvoiceDateToRow($rows[$key], $entry->transaction_date, self::INVOICE_DATE_PRIORITY_RECEIVED);
        $this->trackSourceInvoicePeriod($rows[$key], $entry->transaction_date);

        $rows[$key]['has_received'] = true;
        $rows[$key]['received_amount'] = ($rows[$key]['received_amount'] ?? 0) + (float) $entry->amount;
        $rows[$key]['received_entry_ids'][] = $entry->id;
        $rows[$key]['filed_statement_periods'][] = [
            'year' => (int) ($entry->statement_year ?? $entry->transaction_date->year),
            'month' => (int) ($entry->statement_month ?? $entry->transaction_date->month),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @param  Collection<int, string>  $branchCodeById
     * @param  array<string, int>  $branchLookupByPeriod
     * @param  array<string, int>  $branchLookupGlobal
     */
    private function accumulateAnnexureRow(
        array &$rows,
        ClientAnnexureEntry $entry,
        Collection $branchCodeById,
        array $branchLookupByPeriod,
        array $branchLookupGlobal,
    ): void {
        $statementYear = $entry->transaction_date->year;
        $statementMonth = $entry->transaction_date->month;
        $resolvedBranchId = $this->resolveBranchId(
            $statementYear,
            $statementMonth,
            $entry->invoice_no,
            $entry->branch_id,
            $branchLookupByPeriod,
            $branchLookupGlobal,
        );
        $key = $this->rowKey($entry->invoice_no);

        $rows[$key] ??= $this->emptyRow(
            $resolvedBranchId,
            $resolvedBranchId !== null
                ? ($entry->branch?->code ?? $branchCodeById->get($resolvedBranchId))
                : null,
            $entry->invoice_no,
        );

        if ($resolvedBranchId !== null) {
            $this->applyBranchToRow(
                $rows[$key],
                $resolvedBranchId,
                $entry->branch?->code ?? $branchCodeById->get($resolvedBranchId),
            );
        }

        $this->applyInvoiceDateToRow($rows[$key], $entry->transaction_date, self::INVOICE_DATE_PRIORITY_ANNEXURE);
        $this->trackSourceInvoicePeriod($rows[$key], $entry->transaction_date);

        $rows[$key]['has_annexure'] = true;
        $rows[$key]['annexure_entry_ids'][] = $entry->id;

        $cheque = $entry->annexureCheque;

        $rows[$key]['annexure_entry_candidates'][] = [
            'id' => $entry->id,
            'amount' => (float) $entry->amount,
            'cheque_number' => $cheque?->check_number ?? '',
            'cheque_year' => $cheque?->year ?? 0,
            'cheque_month' => $cheque?->month ?? 0,
            'payment_saved' => (bool) ($cheque?->payment_saved ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyBranchToRow(array &$row, ?int $branchId, ?string $branchCode): void
    {
        if ($branchId === null) {
            return;
        }

        if ($row['branch_id'] === null) {
            $row['branch_id'] = $branchId;
            $row['branch_code'] = $branchCode;

            return;
        }

        if ($row['branch_id'] !== $branchId && $row['branch_code'] === null) {
            $row['branch_code'] = $branchCode;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRow(
        ?int $branchId,
        ?string $branchCode,
        string $invoiceNo,
    ): array {
        return [
            'statement_year' => null,
            'statement_month' => null,
            'branch_id' => $branchId,
            'branch_code' => $branchCode,
            'invoice_no' => $this->normalizeInvoiceNo($invoiceNo),
            'invoice_date' => null,
            'invoice_date_priority' => 0,
            'source_invoice_periods' => [],
            'has_branch' => false,
            'has_received' => false,
            'has_annexure' => false,
            'branch_amount' => null,
            'received_amount' => null,
            'annexure_amount' => null,
            'cheque_number' => null,
            'cheque_year' => null,
            'cheque_month' => null,
            'cheque_payment_saved' => false,
            'branch_entry_ids' => [],
            'received_entry_ids' => [],
            'annexure_entry_ids' => [],
            'annexure_entry_candidates' => [],
            'filed_statement_periods' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function finalizeRow(array $row): array
    {
        $branchAmount = $row['has_branch'] ? (float) $row['branch_amount'] : null;
        $receivedAmount = $row['has_received'] ? (float) $row['received_amount'] : null;
        $annexureSelection = $this->resolveAnnexureSelection($row['annexure_entry_candidates'] ?? []);
        $annexureAmount = $annexureSelection['amount'];

        $presentAmounts = array_values(array_filter(
            [$branchAmount, $receivedAmount, $annexureAmount],
            fn (?float $amount): bool => $amount !== null,
        ));

        $missingSources = [];

        if (! $row['has_branch']) {
            $missingSources[] = 'branch';
        }

        if (! $row['has_received']) {
            $missingSources[] = 'received';
        }

        if (! $row['has_annexure']) {
            $missingSources[] = 'annexure';
        }

        $amountsMismatch = count($presentAmounts) >= 2
            && ! $this->amountsMatch($presentAmounts);
        $chequeIssued = $annexureSelection['cheque_issued'];
        $branchReceivedMatch = $row['has_branch']
            && $row['has_received']
            && $this->amountsMatch([$branchAmount, $receivedAmount]);

        $status = 'incomplete';

        if ($chequeIssued) {
            $status = 'complete';
        } elseif ($amountsMismatch) {
            $status = 'mismatch';
        } elseif ($branchReceivedMatch) {
            $status = 'matched';
        } elseif (count($missingSources) === 0) {
            if ($this->amountsMatch($presentAmounts)) {
                $status = 'matched';
            } else {
                $status = 'mismatch';
            }
        }

        $invoiceDate = $row['invoice_date'];
        $statementYear = $invoiceDate !== null
            ? $invoiceDate->year
            : (int) ($row['statement_year'] ?? 0);
        $statementMonth = $invoiceDate !== null
            ? $invoiceDate->month
            : (int) ($row['statement_month'] ?? 0);

        return [
            'key' => $this->rowKey((string) $row['invoice_no']),
            'statement_year' => $statementYear,
            'statement_month' => $statementMonth,
            'statement_period' => Carbon::create($statementYear, $statementMonth, 1)->format('M Y'),
            'branch_id' => $row['branch_id'],
            'branch_code' => $row['branch_code'],
            'invoice_no' => $row['invoice_no'],
            'invoice_date' => $invoiceDate !== null
                ? StatementDate::format($invoiceDate)
                : null,
            'branch_amount' => $branchAmount !== null
                ? StatementAmount::format($branchAmount)
                : null,
            'branch_amount_value' => $branchAmount,
            'received_amount' => $receivedAmount !== null
                ? StatementAmount::format($receivedAmount)
                : null,
            'received_amount_value' => $receivedAmount,
            'annexure_amount' => $annexureAmount !== null
                ? StatementAmount::format($annexureAmount)
                : null,
            'annexure_amount_value' => $annexureAmount,
            'cheque_number' => $annexureSelection['cheque_number'],
            'cheque_period' => $annexureSelection['cheque_period'],
            'has_branch' => (bool) $row['has_branch'],
            'has_received' => (bool) $row['has_received'],
            'has_annexure' => (bool) $row['has_annexure'],
            'missing_sources' => $missingSources,
            'status' => $status,
            'has_amount_mismatch' => $amountsMismatch,
            'cheque_issued' => $chequeIssued,
            'invoice_date_differs_from_period' => $this->invoiceDateDiffersFromFiledPeriods(
                $invoiceDate,
                $statementYear,
                $statementMonth,
                $row['filed_statement_periods'] ?? [],
            ),
            'source_invoice_periods' => $row['source_invoice_periods'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyInvoiceDateToRow(array &$row, CarbonInterface $invoiceDate, int $priority): void
    {
        $currentPriority = (int) ($row['invoice_date_priority'] ?? 0);

        if ($priority > $currentPriority) {
            $row['invoice_date'] = $invoiceDate;
            $row['invoice_date_priority'] = $priority;
            $row['statement_year'] = $invoiceDate->year;
            $row['statement_month'] = $invoiceDate->month;

            return;
        }

        if ($priority === $currentPriority && $priority > 0) {
            if ($row['invoice_date'] === null || $invoiceDate->gt($row['invoice_date'])) {
                $row['invoice_date'] = $invoiceDate;
                $row['statement_year'] = $invoiceDate->year;
                $row['statement_month'] = $invoiceDate->month;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function trackSourceInvoicePeriod(array &$row, CarbonInterface $invoiceDate): void
    {
        $period = [
            'year' => $invoiceDate->year,
            'month' => $invoiceDate->month,
        ];

        foreach ($row['source_invoice_periods'] as $existing) {
            if ($existing['year'] === $period['year'] && $existing['month'] === $period['month']) {
                return;
            }
        }

        $row['source_invoice_periods'][] = $period;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowMatchesInvoiceMonth(array $row, int $year, int $month): bool
    {
        foreach ($row['source_invoice_periods'] ?? [] as $period) {
            if ($period['year'] === $year && $period['month'] === $month) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{year: int, month: int}>  $filedPeriods
     */
    private function invoiceDateDiffersFromFiledPeriods(
        mixed $invoiceDate,
        int $invoiceYear,
        int $invoiceMonth,
        array $filedPeriods,
    ): bool {
        if ($invoiceDate === null) {
            return false;
        }

        foreach ($filedPeriods as $period) {
            if ($period['year'] !== $invoiceYear || $period['month'] !== $invoiceMonth) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{
     *     id: int,
     *     amount: float,
     *     cheque_number: string,
     *     cheque_year: int,
     *     cheque_month: int,
     *     payment_saved: bool,
     * }>  $candidates
     * @return array{
     *     amount: float|null,
     *     cheque_number: string|null,
     *     cheque_period: string|null,
     *     cheque_issued: bool,
     * }
     */
    private function resolveAnnexureSelection(array $candidates): array
    {
        if ($candidates === []) {
            return [
                'amount' => null,
                'cheque_number' => null,
                'cheque_period' => null,
                'cheque_issued' => false,
            ];
        }

        usort(
            $candidates,
            function (array $left, array $right): int {
                $leftIssued = trim($left['cheque_number']) !== '' || $left['payment_saved'];
                $rightIssued = trim($right['cheque_number']) !== '' || $right['payment_saved'];

                if ($leftIssued !== $rightIssued) {
                    return $rightIssued <=> $leftIssued;
                }

                if ($left['cheque_year'] !== $right['cheque_year']) {
                    return $right['cheque_year'] <=> $left['cheque_year'];
                }

                if ($left['cheque_month'] !== $right['cheque_month']) {
                    return $right['cheque_month'] <=> $left['cheque_month'];
                }

                return $right['id'] <=> $left['id'];
            },
        );

        $selected = $candidates[0];
        $chequeNumber = trim($selected['cheque_number']);
        $chequeIssued = $chequeNumber !== '' || $selected['payment_saved'];

        return [
            'amount' => $selected['amount'],
            'cheque_number' => $chequeNumber !== '' ? $chequeNumber : null,
            'cheque_period' => $selected['cheque_year'] > 0 && $selected['cheque_month'] > 0
                ? Carbon::create($selected['cheque_year'], $selected['cheque_month'], 1)->format('M Y')
                : null,
            'cheque_issued' => $chequeIssued,
        ];
    }

    /**
     * @param  list<float>  $amounts
     */
    private function amountsMatch(array $amounts): bool
    {
        if ($amounts === []) {
            return true;
        }

        $first = $amounts[0];

        foreach ($amounts as $amount) {
            if (abs($amount - $first) >= self::AMOUNT_TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    private function rowKey(string $invoiceNo): string
    {
        return $this->normalizeInvoiceNo($invoiceNo);
    }

    private function periodInvoiceLookupKey(int $year, int $month, string $invoiceNo): string
    {
        return $year.'-'.$month.'|'.$this->normalizeInvoiceNo($invoiceNo);
    }

    private function normalizeInvoiceNo(string $invoiceNo): string
    {
        return trim($invoiceNo);
    }
}
