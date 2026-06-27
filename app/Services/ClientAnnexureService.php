<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAnnexureCheque;
use App\Models\ClientAnnexureEntry;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use App\Support\StatementRequestFilters;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ClientAnnexureService
{
    public function __construct(
        private readonly IncomingStatementComparisonService $comparisonService,
    ) {}

    /**
     * @return array{
     *     phase: string,
     *     year: int,
     *     month: int,
     *     periods: Collection<int, array{year: int, month: int, label: string}>,
     *     periodLabel: string,
     *     chequeId: int|null,
     *     selectedChequeIds: Collection<int, int>,
     *     cheques: Collection<int, array<string, mixed>>,
     *     entries: Collection<int, array<string, mixed>>,
     *     clientTotal: float,
     *     branchTotal: float,
     *     differenceTotal: float,
     *     unresolvedCount: int,
     *     mismatchCount: int,
     *     cheque: ClientAnnexureCheque|null,
     *     rebate: float,
     *     checkTotal: float,
     *     netAmount: float,
     *     checkNumber: string,
     * }
     */
    public function resolve(Request $request, Client $client): array
    {
        $selectedChequeIds = collect($request->input('cheque_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($selectedChequeIds->isEmpty() && $request->filled('cheque')) {
            $selectedChequeIds = collect([(int) $request->integer('cheque')]);
        }

        $periods = StatementRequestFilters::optionalPeriods($request);

        if ($periods->isEmpty()) {
            $latest = $this->availableMonths($client)->first();

            if ($latest !== null) {
                $periods = collect([$latest]);
            } else {
                $now = now();
                $periods = collect([[
                    'year' => $now->year,
                    'month' => $now->month,
                    'label' => $this->periodLabel($now->year, $now->month),
                ]]);
            }
        }

        $allCheques = $this->chequesForPeriods($client, $periods);

        $singleCheque = $selectedChequeIds->count() === 1
            ? ClientAnnexureCheque::query()
                ->where('client_id', $client->id)
                ->whereKey($selectedChequeIds->first())
                ->first()
            : null;

        $primary = $singleCheque !== null
            ? ['year' => $singleCheque->year, 'month' => $singleCheque->month]
            : [
                'year' => $periods->first()['year'],
                'month' => $periods->first()['month'],
            ];

        $entries = collect();
        $cheque = null;

        if ($singleCheque !== null) {
            $cheque = $singleCheque;
            $entries = $this->entriesForCheques($client, collect([$singleCheque->id]), $singleCheque);
        } elseif ($selectedChequeIds->count() > 1) {
            $entries = $this->entriesForCheques($client, $selectedChequeIds);
        }

        $clientTotal = (float) $entries->sum('amount_value');
        $branchTotal = (float) $entries
            ->whereNotNull('branch_amount_value')
            ->sum('branch_amount_value');
        $differenceTotal = (float) $entries
            ->whereNotNull('difference_amount_value')
            ->sum('difference_amount_value');

        $activeCheques = $selectedChequeIds->isNotEmpty()
            ? $allCheques->whereIn('id', $selectedChequeIds->all())->values()
            : $allCheques;

        $rebate = (float) $activeCheques->sum('rebate_value');
        $checkTotal = (float) $activeCheques->sum(
            fn (array $item): float => $item['amount_value'],
        );
        $netAmount = (float) $activeCheques->sum('net_amount_value');

        if ($singleCheque !== null) {
            $rebate = (float) $singleCheque->rebate;
            $checkTotal = (float) $singleCheque->amount > 0
                ? (float) $singleCheque->amount
                : $clientTotal;
            $netAmount = $clientTotal - $rebate;
        }

        $phase = $this->resolvePhase($cheque, $allCheques, $selectedChequeIds);

        return [
            'phase' => $phase,
            'year' => $primary['year'],
            'month' => $primary['month'],
            'periods' => $periods,
            'periodLabel' => $this->periodsLabel($periods),
            'chequeId' => $cheque?->id,
            'selectedChequeIds' => $selectedChequeIds,
            'cheques' => $allCheques,
            'entries' => $entries,
            'clientTotal' => $clientTotal,
            'branchTotal' => $branchTotal,
            'differenceTotal' => $differenceTotal,
            'unresolvedCount' => $entries
                ->filter(fn (array $entry): bool => ! $entry['is_resolved'] && ! ($entry['no_branch_expected'] ?? false))
                ->count(),
            'mismatchCount' => $entries->where('has_difference', true)->count(),
            'cheque' => $cheque,
            'rebate' => $rebate,
            'checkTotal' => $checkTotal,
            'netAmount' => $netAmount,
            'checkNumber' => $cheque?->check_number ?? ($selectedChequeIds->count() > 1
                ? $selectedChequeIds->count().' cheques'
                : ''),
        ];
    }

    /**
     * @param  Collection<int, array{year: int, month: int, label?: string}>  $periods
     * @return Collection<int, array<string, mixed>>
     */
    private function chequesForPeriods(Client $client, Collection $periods): Collection
    {
        $query = ClientAnnexureCheque::query()
            ->where('client_id', $client->id)
            ->withSum('entries as entries_total', 'amount')
            ->withCount('entries');

        if ($periods->isNotEmpty()) {
            $query->where(function ($query) use ($periods): void {
                foreach ($periods as $period) {
                    $query->orWhere(function ($query) use ($period): void {
                        $query->forMonth($period['year'], $period['month']);
                    });
                }
            });
        }

        return $query
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ClientAnnexureCheque $cheque): array => $this->mapChequeSummary($cheque))
            ->values();
    }

    /**
     * @param  Collection<int, int>  $chequeIds
     * @return Collection<int, array<string, mixed>>
     */
    private function entriesForCheques(
        Client $client,
        Collection $chequeIds,
        ?ClientAnnexureCheque $singleCheque = null,
    ): Collection {
        if ($chequeIds->isEmpty()) {
            return collect();
        }

        $entries = ClientAnnexureEntry::query()
            ->with(['branch', 'annexureCheque'])
            ->where('client_id', $client->id)
            ->whereIn('client_annexure_cheque_id', $chequeIds)
            ->get()
            ->sortBy(fn (ClientAnnexureEntry $entry): array => [
                $entry->annexureCheque?->check_number ?? '',
                $entry->branch?->code ?? '',
                $entry->transaction_date->format('Y-m-d'),
                $entry->id,
            ])
            ->values();

        $supplierLookup = $this->comparisonService
            ->supplierAmountLookupForAnnexureEntries($client, $entries);

        return $entries
            ->map(function (ClientAnnexureEntry $entry) use ($supplierLookup, $singleCheque): array {
                $mapped = $this->comparisonService->mapAnnexureEntry($entry, $supplierLookup);
                $cheque = $entry->annexureCheque;

                $mapped['cheque_id'] = $entry->client_annexure_cheque_id;
                $mapped['cheque_number'] = $cheque?->check_number !== ''
                    ? $cheque?->check_number
                    : null;
                $mapped['cheque_period_label'] = $cheque !== null
                    ? Carbon::create($cheque->year, $cheque->month, 1)->format('M Y')
                    : null;

                if ($singleCheque !== null) {
                    $mapped['invoice_date_differs_from_period'] = (
                        $entry->transaction_date->year !== $singleCheque->year
                        || $entry->transaction_date->month !== $singleCheque->month
                    );
                }

                return $mapped;
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cheques
     * @param  Collection<int, int>  $selectedChequeIds
     */
    private function resolvePhase(
        ?ClientAnnexureCheque $cheque,
        Collection $cheques,
        Collection $selectedChequeIds,
    ): string {
        if ($cheque === null) {
            return $cheques->isEmpty() ? 'upload' : 'complete';
        }

        if ($selectedChequeIds->count() > 1) {
            return 'complete';
        }

        if ($cheque->entries()->count() === 0) {
            return 'upload';
        }

        if (! $cheque->review_completed) {
            return 'review';
        }

        if (! $cheque->payment_saved) {
            return 'payment';
        }

        return 'complete';
    }

    /**
     * @return array<string, mixed>
     */
    public function mapChequeSummary(ClientAnnexureCheque $cheque): array
    {
        $clientTotal = (float) ($cheque->entries_total ?? $cheque->entries()->sum('amount'));
        $rebate = (float) $cheque->rebate;
        $amount = (float) $cheque->amount;

        return [
            'id' => $cheque->id,
            'year' => $cheque->year,
            'month' => $cheque->month,
            'period_label' => Carbon::create($cheque->year, $cheque->month, 1)->format('F Y'),
            'cheque_date' => $cheque->cheque_date !== null
                ? StatementDate::format($cheque->cheque_date)
                : StatementDate::format(Carbon::create($cheque->year, $cheque->month, 1)),
            'check_number' => $cheque->check_number !== '' ? $cheque->check_number : '—',
            'amount' => StatementAmount::format($amount > 0 ? $amount : $clientTotal),
            'amount_value' => $amount > 0 ? $amount : $clientTotal,
            'client_total' => StatementAmount::format($clientTotal),
            'client_total_value' => $clientTotal,
            'rebate' => StatementAmount::format($rebate),
            'rebate_value' => $rebate,
            'net_amount' => StatementAmount::format($clientTotal - $rebate),
            'net_amount_value' => $clientTotal - $rebate,
            'entries_count' => (int) ($cheque->entries_count ?? $cheque->entries()->count()),
            'payment_saved' => $cheque->payment_saved,
            'review_completed' => $cheque->review_completed,
        ];
    }

    public function availableMonths(Client $client): Collection
    {
        $chequeMonths = ClientAnnexureCheque::query()
            ->where('client_id', $client->id)
            ->get(['year', 'month'])
            ->map(fn (ClientAnnexureCheque $cheque): array => [
                'year' => $cheque->year,
                'month' => $cheque->month,
                'label' => 'Cheque month · '.Carbon::create($cheque->year, $cheque->month, 1)->format('F Y'),
            ]);

        $merged = $chequeMonths
            ->unique(fn (array $item): string => $item['year'].'-'.$item['month'])
            ->sortByDesc(fn (array $item): string => sprintf('%04d-%02d', $item['year'], $item['month']))
            ->values();

        if ($merged->isNotEmpty()) {
            return $merged;
        }

        $now = now();

        return collect([[
            'year' => $now->year,
            'month' => $now->month,
            'label' => $this->periodLabel($now->year, $now->month),
        ]]);
    }

    public function periodLabel(int $year, int $month): string
    {
        return 'Cheque month · '.Carbon::create($year, $month, 1)->format('F Y');
    }

    /**
     * @param  Collection<int, array{year: int, month: int, label?: string}>  $periods
     */
    public function periodsLabel(Collection $periods): string
    {
        if ($periods->count() === 1) {
            return $this->periodLabel($periods->first()['year'], $periods->first()['month']);
        }

        return $periods->count().' cheque months';
    }

    /**
     * @param  Collection<int, array{year: int, month: int}>  $periods
     * @return array{client_total: float, check_total: float, rebate: float, net_amount: float}
     */
    public function monthTotals(Client $client, int $year, int $month): array
    {
        return $this->periodTotals($client, collect([['year' => $year, 'month' => $month]]));
    }

    /**
     * @param  Collection<int, array{year: int, month: int}>  $periods
     * @return array{client_total: float, check_total: float, rebate: float, net_amount: float}
     */
    public function periodTotals(Client $client, Collection $periods): array
    {
        $cheques = $this->chequesForPeriods($client, $periods);

        $clientTotal = (float) $cheques->sum('client_total_value');
        $checkTotal = (float) $cheques->sum('amount_value');
        $rebate = (float) $cheques->sum('rebate_value');
        $netAmount = (float) $cheques->sum('net_amount_value');

        return compact('clientTotal', 'checkTotal', 'rebate', 'netAmount');
    }
}
