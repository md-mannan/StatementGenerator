<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAnnexureEntry;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Services\CrossCheckService;
use App\Support\StatementAmount;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ClientPageSummaryService
{
    public function __construct(
        private readonly IncomingStatementComparisonService $comparisonService,
        private readonly ClientAnnexureService $annexureService,
        private readonly CrossCheckService $crossCheckService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forBranches(Client $client): array
    {
        $client->loadCount('branches');
        $branchIds = $client->branches()->pluck('id');

        if ($branchIds->isEmpty()) {
            return [
                'context' => 'branches',
                'branches' => 0,
                'branch_months' => 0,
                'entries' => 0,
                'total_amount' => StatementAmount::format(0),
            ];
        }

        $entriesQuery = StatementEntry::query()->whereIn('branch_id', $branchIds);

        $branchMonthCount = (clone $entriesQuery)
            ->select('branch_id', 'statement_year', 'statement_month')
            ->groupBy('branch_id', 'statement_year', 'statement_month')
            ->get()
            ->count();

        return [
            'context' => 'branches',
            'branches' => $client->branches_count,
            'branch_months' => $branchMonthCount,
            'entries' => (clone $entriesQuery)->count(),
            'total_amount' => StatementAmount::format((clone $entriesQuery)->sum('amount')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forGenerateStatement(Client $client): array
    {
        $client->loadCount('branches');
        $branchIds = $client->branches()->pluck('id');

        if ($branchIds->isEmpty()) {
            return [
                'context' => 'generate_statement',
                'branches' => 0,
                'branches_with_data' => 0,
                'statement_months' => 0,
                'total_amount' => StatementAmount::format(0),
            ];
        }

        $entriesQuery = StatementEntry::query()->whereIn('branch_id', $branchIds);

        $branchesWithData = StatementEntry::query()
            ->whereIn('branch_id', $branchIds)
            ->distinct()
            ->count('branch_id');

        $statementMonths = StatementEntry::query()
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('DISTINCT statement_year, statement_month')
            ->get()
            ->count();

        return [
            'context' => 'generate_statement',
            'branches' => $client->branches_count,
            'branches_with_data' => $branchesWithData,
            'statement_months' => $statementMonths,
            'total_amount' => StatementAmount::format((clone $entriesQuery)->sum('amount')),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $mappedEntries
     * @return array<string, mixed>
     */
    public function forReceivedStatementsFromMapped(
        string $periodLabel,
        \Illuminate\Support\Collection $mappedEntries,
        float $clientTotal,
    ): array {
        $branchTotal = (float) $mappedEntries
            ->whereNotNull('branch_amount_value')
            ->sum('branch_amount_value');
        $differenceTotal = (float) $mappedEntries
            ->whereNotNull('difference_amount_value')
            ->sum('difference_amount_value');

        return [
            'context' => 'received_statements',
            'period_label' => $periodLabel,
            'entries' => $mappedEntries->count(),
            'client_total' => StatementAmount::format($clientTotal),
            'branch_total' => StatementAmount::format($branchTotal),
            'difference_total' => StatementAmount::format($differenceTotal),
            'unresolved_count' => $mappedEntries
                ->filter(fn (array $entry): bool => ! $entry['is_resolved'] && ! ($entry['no_branch_expected'] ?? false))
                ->count(),
            'mismatch_count' => $mappedEntries->where('has_difference', true)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forReceivedStatements(Client $client, int $year, int $month): array
    {
        $entries = IncomingStatementEntry::query()
            ->where('client_id', $client->id)
            ->forInvoiceMonth($year, $month)
            ->get();

        $supplierLookup = $this->comparisonService->supplierAmountLookup($client, $year, $month);

        $mapped = $entries->map(
            fn (IncomingStatementEntry $entry): array => $this->comparisonService->mapEntry($entry, $supplierLookup),
        );

        $clientTotal = (float) $entries->sum('amount');
        $branchTotal = (float) $mapped
            ->whereNotNull('branch_amount_value')
            ->sum('branch_amount_value');
        $differenceTotal = (float) $mapped
            ->whereNotNull('difference_amount_value')
            ->sum('difference_amount_value');

        return [
            'context' => 'received_statements',
            'period_label' => Carbon::create($year, $month, 1)->format('F Y'),
            'entries' => $entries->count(),
            'client_total' => StatementAmount::format($clientTotal),
            'branch_total' => StatementAmount::format($branchTotal),
            'difference_total' => StatementAmount::format($differenceTotal),
            'unresolved_count' => $mapped->filter(fn (array $entry): bool => ! $entry['is_resolved'] && ! ($entry['no_branch_expected'] ?? false))->count(),
            'mismatch_count' => $mapped->where('has_difference', true)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forAnnexure(Client $client, array $resolved): array
    {
        $periods = $resolved['periods'];
        $totals = $this->annexureService->periodTotals($client, $periods);

        $chequeIds = $resolved['selectedChequeIds'];
        $chequeQuery = \App\Models\ClientAnnexureCheque::query()
            ->where('client_id', $client->id);

        if ($periods->isNotEmpty()) {
            $chequeQuery->where(function ($query) use ($periods): void {
                foreach ($periods as $period) {
                    $query->orWhere(function ($query) use ($period): void {
                        $query->forMonth($period['year'], $period['month']);
                    });
                }
            });
        }

        if ($chequeIds->isNotEmpty()) {
            $chequeQuery->whereIn('id', $chequeIds);
        }

        $entryCount = \App\Models\ClientAnnexureEntry::query()
            ->where('client_id', $client->id)
            ->when(
                $chequeIds->isNotEmpty(),
                fn ($query) => $query->whereIn('client_annexure_cheque_id', $chequeIds),
                fn ($query) => $query->whereHas(
                    'annexureCheque',
                    fn ($chequeQuery) => $periods->isEmpty()
                        ? $chequeQuery
                        : $chequeQuery->where(function ($periodQuery) use ($periods): void {
                            foreach ($periods as $period) {
                                $periodQuery->orWhere(function ($periodQuery) use ($period): void {
                                    $periodQuery->forMonth($period['year'], $period['month']);
                                });
                            }
                        }),
                ),
            )
            ->count();

        return [
            'context' => 'annexure',
            'period_label' => $resolved['periodLabel'],
            'entries' => $entryCount,
            'cheque_count' => $chequeQuery->count(),
            'client_total' => StatementAmount::format($totals['clientTotal']),
            'check_total' => StatementAmount::format($totals['checkTotal']),
            'rebate' => StatementAmount::format($totals['rebate']),
            'net_amount' => StatementAmount::format($totals['netAmount']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forAnnexureImport(Client $client): array
    {
        $entryCount = ClientAnnexureEntry::query()
            ->where('client_id', $client->id)
            ->count();

        $savedMonths = ClientAnnexureEntry::query()
            ->where('client_id', $client->id)
            ->get(['transaction_date'])
            ->groupBy(fn (ClientAnnexureEntry $entry): string => $entry->transaction_date->year.'-'.$entry->transaction_date->month)
            ->count();

        return [
            'context' => 'annexure',
            'period_label' => 'All months',
            'entries' => $entryCount,
            'client_total' => StatementAmount::format(
                ClientAnnexureEntry::query()->where('client_id', $client->id)->sum('amount'),
            ),
            'check_total' => StatementAmount::format(0),
            'rebate' => StatementAmount::format(0),
            'net_amount' => StatementAmount::format(0),
            'saved_months' => $savedMonths,
        ];
    }

    /**
     * @param  array{
     *     rows: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     branchTotal: float,
     *     receivedTotal: float,
     *     annexureTotal: float,
     *     matchedCount: int,
     *     mismatchCount: int,
     *     incompleteCount: int,
     * }  $result
     * @return array<string, mixed>
     */
    public function forCrossCheck(Client $client, array $result, int $periodCount): array
    {
        return [
            'context' => 'cross_check',
            'entries' => $result['rows']->count(),
            'branch_total' => StatementAmount::format($result['branchTotal']),
            'received_total' => StatementAmount::format($result['receivedTotal']),
            'annexure_total' => StatementAmount::format($result['annexureTotal']),
            'matched_count' => $result['matchedCount'],
            'complete_count' => $result['completeCount'],
            'mismatch_count' => $result['mismatchCount'],
            'incomplete_count' => $result['incompleteCount'],
            'statement_months' => $periodCount,
            'branches' => $client->branches()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forReceivedStatementsImport(Client $client): array
    {
        $entryCount = IncomingStatementEntry::query()
            ->where('client_id', $client->id)
            ->count();

        $savedMonths = IncomingStatementEntry::query()
            ->where('client_id', $client->id)
            ->get(['transaction_date'])
            ->groupBy(fn (IncomingStatementEntry $entry): string => $entry->transaction_date->year.'-'.$entry->transaction_date->month)
            ->count();

        return [
            'context' => 'received_statements',
            'period_label' => 'All months',
            'entries' => $entryCount,
            'client_total' => StatementAmount::format(
                IncomingStatementEntry::query()->where('client_id', $client->id)->sum('amount'),
            ),
            'branch_total' => StatementAmount::format(0),
            'difference_total' => StatementAmount::format(0),
            'unresolved_count' => 0,
            'mismatch_count' => 0,
            'saved_months' => $savedMonths,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    public function forStatementView(
        string $periodLabel,
        \Illuminate\Support\Collection $entries,
        float $branchTotal,
    ): array {
        $clientTotal = (float) $entries
            ->whereNotNull('client_amount_value')
            ->sum('client_amount_value');
        $differenceTotal = (float) $entries
            ->whereNotNull('difference_amount_value')
            ->sum('difference_amount_value');

        return [
            'context' => 'statement_view',
            'period_label' => $periodLabel,
            'entries' => $entries->count(),
            'branch_total' => StatementAmount::format($branchTotal),
            'client_total' => StatementAmount::format($clientTotal),
            'difference_total' => StatementAmount::format($differenceTotal),
            'unresolved_count' => $entries->where('is_resolved', false)->count(),
            'mismatch_count' => $entries->where('has_difference', true)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    public function forInvoiceDetail(array $invoice): array
    {
        return [
            'context' => 'invoice',
            'invoice_no' => $invoice['invoice_no'],
            'status' => $invoice['status'],
            'branch_amount' => $invoice['branch_amount'] ?? 'Not found',
            'received_amount' => $invoice['received_amount'] ?? 'Not found',
            'annexure_amount' => $invoice['annexure_amount'] ?? 'Not found',
            'cheque_number' => $invoice['cheque_number'] ?? null,
            'cheque_period' => $invoice['cheque_period'] ?? null,
        ];
    }
}
