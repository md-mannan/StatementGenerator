<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\IncomingStatementEntry;
use App\Services\ClientPageSummaryService;
use App\Services\IncomingStatementComparisonService;
use App\Services\IncomingStatementService;
use App\Support\StatementAmount;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IncomingStatementController extends Controller
{
    public function __construct(
        private readonly IncomingStatementService $incomingStatementService,
        private readonly IncomingStatementComparisonService $comparisonService,
        private readonly ClientPageSummaryService $pageSummary,
    ) {}

    public function index(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $resolved = $this->incomingStatementService->resolve($request, $client);
        $periods = $resolved['periods'];
        $year = $resolved['primaryYear'];
        $month = $resolved['primaryMonth'];
        $entries = $resolved['entries'];
        $total = $resolved['total'];
        $periodLabel = $resolved['periodLabel'];

        $availableMonths = $this->incomingStatementService
            ->availableInvoiceMonths($client);

        if ($availableMonths->isEmpty()) {
            $availableMonths = collect([[
                'year' => $year,
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('F Y'),
            ]]);
        }

        $period = Carbon::create($year, $month, 1);
        $supplierLookup = $this->comparisonService->supplierAmountLookupByInvoiceMonth(
            $client,
            $periods->all(),
        );
        $branchLookups = $this->comparisonService->branchIdLookup($client);
        $branchCodeById = $client->branches()->pluck('code', 'id')->all();
        $branchNameById = $client->branches()->pluck('name', 'id')->all();

        $mappedEntries = $entries
            ->map(fn (IncomingStatementEntry $entry): array => $this->comparisonService->mapEntry(
                $entry,
                $supplierLookup,
                $branchLookups['byPeriod'],
                $branchLookups['byInvoice'],
                $branchCodeById,
                $branchNameById,
            ))
            ->values();

        $unresolvedCount = $mappedEntries
            ->filter(fn (array $entry): bool => ! $entry['is_resolved'] && ! ($entry['no_branch_expected'] ?? false))
            ->count();
        $mismatchCount = $mappedEntries->where('has_difference', true)->count();
        $branchStatementTotal = $mappedEntries
            ->whereNotNull('branch_amount_value')
            ->sum('branch_amount_value');
        $totalDifference = $mappedEntries
            ->whereNotNull('difference_amount_value')
            ->sum('difference_amount_value');

        return Inertia::render('clients/received-statements/index', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'summary' => $this->pageSummary->forReceivedStatementsFromMapped(
                $periodLabel,
                $mappedEntries,
                $total,
            ),
            'entries' => $mappedEntries,
            'total' => StatementAmount::format($total),
            'branchStatementTotal' => StatementAmount::format($branchStatementTotal),
            'totalDifference' => StatementAmount::format($totalDifference),
            'unresolvedCount' => $unresolvedCount,
            'mismatchCount' => $mismatchCount,
            'year' => $year,
            'month' => $month,
            'selectedPeriods' => $periods->values(),
            'periodLabel' => $periodLabel,
            'availableMonths' => $availableMonths,
            'previousPeriod' => $period->copy()->subMonth()->format('Y-n'),
            'nextPeriod' => $period->copy()->addMonth()->format('Y-n'),
            'branches' => $client->branches()
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn ($branch): array => [
                    'id' => $branch->id,
                    'code' => $branch->code,
                    'name' => $branch->name,
                ])
                ->values(),
        ]);
    }
}
