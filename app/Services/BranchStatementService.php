<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientAnnexureEntry;
use App\Models\StatementEntry;
use App\Support\StatementAmount;
use App\Support\StatementAvailableMonths;
use App\Support\StatementDate;
use App\Support\StatementPeriod;
use App\Support\StatementRequestFilters;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BranchStatementService
{
    public function __construct(
        private readonly IncomingStatementComparisonService $comparisonService,
    ) {}

    /**
     * @return array{
     *     branchIds: Collection<int, int>,
     *     branches: Collection<int, Branch>,
     *     periods: Collection<int, array{year: int, month: int, label: string}>,
     *     entries: Collection<int, array<string, mixed>>,
     *     total: float,
     *     availableMonths: Collection<int, array{year: int, month: int, label: string}>,
     *     periodLabel: string,
     *     primaryYear: int,
     *     primaryMonth: int,
     * }
     */
    public function resolve(Request $request, Branch $branch): array
    {
        $branch->load('client');

        $branchIds = StatementRequestFilters::branchIds($request, $branch->id);

        $branches = $branch->client->branches()
            ->whereIn('id', $branchIds)
            ->orderBy('code')
            ->get();

        abort_if($branches->isEmpty(), 422, __('Select at least one branch.'));

        $availableMonths = StatementAvailableMonths::forBranchIds($branches->pluck('id'));
        $periods = StatementRequestFilters::periodsOrAll($request);

        $entries = StatementEntry::query()
            ->with('branch')
            ->whereIn('branch_id', $branches->pluck('id'))
            ->forInvoiceMonths($periods)
            ->get()
            ->sortBy(fn (StatementEntry $entry): array => [
                $entry->transaction_date->format('Y-m'),
                $entry->branch?->code ?? '',
                $entry->transaction_date->format('Y-m-d'),
                $entry->id,
            ])
            ->values();

        $total = (float) $entries->sum('amount');

        $primary = StatementRequestFilters::primaryPeriod($periods, $availableMonths);
        $annexureLookup = $this->annexureLookup(
            $branch->client,
            $branches->pluck('id'),
            $periods,
        );
        $clientStatementLookup = $this->comparisonService->clientStatementAmountLookup(
            $branch->client,
            $branches->pluck('id'),
            $periods,
        );

        $chequeReceivedTotal = 0.0;
        $differenceTotal = 0.0;
        $clientStatementTotal = 0.0;
        $clientDifferenceTotal = 0.0;

        $mappedEntries = $entries->map(function (StatementEntry $entry) use (
            $annexureLookup,
            $clientStatementLookup,
            &$chequeReceivedTotal,
            &$differenceTotal,
            &$clientStatementTotal,
            &$clientDifferenceTotal,
        ): array {
            $lookupKey = $this->lookupKey(
                $entry->transaction_date->year,
                $entry->transaction_date->month,
                $entry->branch_id,
                $entry->invoice_no,
            );
            $annexure = $annexureLookup[$lookupKey] ?? null;
            $chequeAmount = $annexure['amount'] ?? null;
            $clientAmount = $clientStatementLookup[$lookupKey] ?? null;
            $branchAmount = (float) $entry->amount;
            $difference = $chequeAmount !== null
                ? $branchAmount - $chequeAmount
                : null;
            $clientDifference = $clientAmount !== null
                ? $clientAmount - $branchAmount
                : null;

            if ($chequeAmount !== null) {
                $chequeReceivedTotal += $chequeAmount;
            }

            if ($difference !== null) {
                $differenceTotal += $difference;
            }

            if ($clientAmount !== null) {
                $clientStatementTotal += $clientAmount;
            }

            if ($clientDifference !== null) {
                $clientDifferenceTotal += $clientDifference;
            }

            return [
                'id' => $entry->id,
                'branch_id' => $entry->branch_id,
                'branch_code' => $entry->branch?->code,
                'branch_name' => $entry->branch?->name,
                'transaction_date' => StatementDate::format($entry->transaction_date),
                'invoice_no' => $entry->invoice_no,
                'amount' => StatementAmount::format($entry->amount),
                'amount_value' => $branchAmount,
                'statement_period' => Carbon::create(
                    $entry->transaction_date->year,
                    $entry->transaction_date->month,
                    1,
                )->format('M Y'),
                'client_statement_amount' => $clientAmount !== null
                    ? StatementAmount::format($clientAmount)
                    : null,
                'client_statement_amount_value' => $clientAmount,
                'client_difference_amount' => $clientDifference !== null
                    ? StatementAmount::format($clientDifference)
                    : null,
                'client_difference_amount_value' => $clientDifference,
                'has_client_difference' => $clientDifference !== null
                    && abs($clientDifference) >= 0.0005,
                'cheque_number' => $annexure['cheque_number'] ?? null,
                'cheque_received_amount' => $chequeAmount !== null
                    ? StatementAmount::format($chequeAmount)
                    : null,
                'cheque_received_amount_value' => $chequeAmount,
                'difference_amount' => $difference !== null
                    ? StatementAmount::format($difference)
                    : null,
                'difference_amount_value' => $difference,
                'has_difference' => $difference !== null
                    && abs($difference) >= 0.0005,
                'is_resolved' => $clientAmount !== null || $chequeAmount !== null,
                'no_bill_expected' => $entry->no_bill_expected,
                'has_invoice_scan' => $entry->invoice_scan_path !== null,
                'invoice_scan_url' => $entry->invoice_scan_path !== null
                    ? route('statement-entries.invoice-scan.show', $entry)
                    : null,
                'invoice_scan_extension' => $entry->invoice_scan_path !== null
                    ? strtolower(pathinfo($entry->invoice_scan_path, PATHINFO_EXTENSION))
                    : null,
                'invoice_date_differs_from_period' => StatementPeriod::invoiceDateDiffersFromPeriod(
                    $entry->transaction_date,
                    $entry->statement_year ?? $entry->transaction_date->year,
                    $entry->statement_month ?? $entry->transaction_date->month,
                ),
            ];
        });

        return [
            'branchIds' => $branches->pluck('id')->values(),
            'branches' => $branches,
            'periods' => $periods,
            'statementEntries' => $entries,
            'entries' => $mappedEntries,
            'total' => $total,
            'chequeReceivedTotal' => $chequeReceivedTotal,
            'differenceTotal' => $differenceTotal,
            'clientStatementTotal' => $clientStatementTotal,
            'clientDifferenceTotal' => $clientDifferenceTotal,
            'availableMonths' => $availableMonths,
            'periodLabel' => StatementRequestFilters::periodLabel($periods),
            'primaryYear' => $primary['year'],
            'primaryMonth' => $primary['month'],
        ];
    }

    /**
     * @param  Collection<int, int>  $branchIds
     * @param  Collection<int, array{year: int, month: int, label: string}>  $periods
     * @return array<string, array{
     *     amount: float|null,
     *     cheque_number: string|null,
     *     cheque_period: string|null,
     *     cheque_issued: bool,
     * }>
     */
    private function annexureLookup(
        Client $client,
        Collection $branchIds,
        Collection $periods,
    ): array {
        if ($branchIds->isEmpty()) {
            return [];
        }

        $query = ClientAnnexureEntry::query()
            ->select([
                'id',
                'branch_id',
                'transaction_date',
                'invoice_no',
                'amount',
                'client_annexure_cheque_id',
            ])
            ->with('annexureCheque:id,year,month,check_number,payment_saved')
            ->where('client_id', $client->id)
            ->whereIn('branch_id', $branchIds);

        if ($periods->isNotEmpty()) {
            $query->where(function ($query) use ($periods): void {
                foreach ($periods as $period) {
                    $query->orWhere(function ($query) use ($period): void {
                        $query->whereYear('transaction_date', $period['year'])
                            ->whereMonth('transaction_date', $period['month']);
                    });
                }
            });
        }

        $entries = $query->get();

        $grouped = [];

        foreach ($entries as $entry) {
            if ($entry->branch_id === null) {
                continue;
            }

            $key = $this->lookupKey(
                $entry->transaction_date->year,
                $entry->transaction_date->month,
                $entry->branch_id,
                $entry->invoice_no,
            );

            $cheque = $entry->annexureCheque;

            $grouped[$key][] = [
                'id' => $entry->id,
                'amount' => (float) $entry->amount,
                'cheque_number' => $cheque?->check_number ?? '',
                'cheque_year' => $cheque?->year ?? 0,
                'cheque_month' => $cheque?->month ?? 0,
                'payment_saved' => (bool) ($cheque?->payment_saved ?? false),
            ];
        }

        $lookup = [];

        foreach ($grouped as $key => $candidates) {
            $lookup[$key] = AnnexureSelectionResolver::resolve($candidates);
        }

        return $lookup;
    }

    private function lookupKey(
        int $year,
        int $month,
        int $branchId,
        string $invoiceNo,
    ): string {
        return $year.'-'.$month.'|'.$branchId.'|'.trim($invoiceNo);
    }
}
