<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Services\ClientPageSummaryService;
use App\Services\ClientStatementService;
use App\Support\StatementAvailableMonths;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use App\Support\StatementPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientStatementController extends Controller
{
    public function __construct(
        private readonly ClientStatementService $clientStatementService,
        private readonly ClientPageSummaryService $pageSummary,
    ) {}

    public function show(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $resolved = $this->clientStatementService->resolve($request, $client);

        $periods = $resolved['periods'];
        $year = $resolved['primaryYear'];
        $month = $resolved['primaryMonth'];
        $branches = $resolved['branches'];
        $entries = $resolved['entries'];
        $total = $resolved['total'];
        $periodLabel = $resolved['periodLabel'];

        $branchIds = $branches->pluck('id');

        $incomingLookup = IncomingStatementEntry::query()
            ->where('client_id', $client->id)
            ->forInvoiceMonths($periods)
            ->get()
            ->groupBy(fn (IncomingStatementEntry $entry): string => trim($entry->invoice_no))
            ->map(fn ($group): float => (float) $group->sum('amount'))
            ->all();

        $availableMonths = StatementAvailableMonths::forBranchIds($branchIds);

        if ($availableMonths->isEmpty()) {
            $availableMonths = collect([[
                'year' => $year,
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('F Y'),
            ]]);
        }

        $period = Carbon::create($year, $month, 1);

        $mappedEntries = $entries->map(function (StatementEntry $entry) use ($incomingLookup): array {
                $invoiceNo = trim($entry->invoice_no);
                $clientAmount = $incomingLookup[$invoiceNo] ?? null;
                $difference = $clientAmount !== null ? $clientAmount - (float) $entry->amount : null;

                return [
                    'id' => $entry->id,
                    'branch_id' => $entry->branch_id,
                    'branch_code' => $entry->branch->code,
                    'branch_name' => $entry->branch->name,
                    'transaction_date' => StatementDate::format($entry->transaction_date),
                    'invoice_no' => $invoiceNo,
                    'amount' => StatementAmount::format($entry->amount),
                    'amount_value' => (float) $entry->amount,
                    'statement_period' => Carbon::create(
                        $entry->transaction_date->year,
                        $entry->transaction_date->month,
                        1,
                    )->format('M Y'),
                    'client_amount' => $clientAmount !== null ? StatementAmount::format($clientAmount) : null,
                    'client_amount_value' => $clientAmount,
                    'difference_amount' => $difference !== null ? StatementAmount::format($difference) : null,
                    'difference_amount_value' => $difference,
                    'is_resolved' => $clientAmount !== null,
                    'has_difference' => $difference !== null && abs($difference) >= 0.0005,
                    'invoice_date_differs_from_period' => StatementPeriod::invoiceDateDiffersFromPeriod(
                        $entry->transaction_date,
                        $entry->statement_year ?? $entry->transaction_date->year,
                        $entry->statement_month ?? $entry->transaction_date->month,
                    ),
                ];
            })->values();

        return Inertia::render('clients/statement-view', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'summary' => $this->pageSummary->forStatementView(
                $periodLabel,
                $mappedEntries,
                $total,
            ),
            'branches' => $branches->map(fn ($branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ])->values(),
            'branchIds' => $branchIds->values(),
            'entries' => $mappedEntries,
            'total' => StatementAmount::format($total),
            'year' => $year,
            'month' => $month,
            'selectedPeriods' => $periods->values(),
            'periodLabel' => $periodLabel,
            'availableMonths' => $availableMonths,
            'previousPeriod' => $period->copy()->subMonth()->format('Y-n'),
            'nextPeriod' => $period->copy()->addMonth()->format('Y-n'),
        ]);
    }
}
