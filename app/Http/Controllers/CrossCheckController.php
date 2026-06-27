<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientPageSummaryService;
use App\Services\CrossCheckService;
use App\Support\StatementAmount;
use App\Support\StatementRequestFilters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrossCheckController extends Controller
{
    public function __construct(
        private readonly CrossCheckService $crossCheckService,
        private readonly ClientPageSummaryService $pageSummary,
    ) {}

    public function index(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $periods = StatementRequestFilters::optionalPeriods($request);
        $selectedBranchIds = StatementRequestFilters::optionalBranchIds($request);

        $result = $this->crossCheckService->resolve($client, $periods, $selectedBranchIds);
        $availablePeriods = $this->crossCheckService->availablePeriods($client);
        $primary = StatementRequestFilters::primaryPeriod(
            $periods->isNotEmpty()
                ? $periods
                : $availablePeriods,
        );

        return Inertia::render('clients/cross-check/index', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'summary' => $this->pageSummary->forCrossCheck($client, $result, $availablePeriods->count()),
            'rows' => $result['rows'],
            'branchTotal' => StatementAmount::format($result['branchTotal']),
            'receivedTotal' => StatementAmount::format($result['receivedTotal']),
            'annexureTotal' => StatementAmount::format($result['annexureTotal']),
            'matchedCount' => $result['matchedCount'],
            'completeCount' => $result['completeCount'],
            'mismatchCount' => $result['mismatchCount'],
            'incompleteCount' => $result['incompleteCount'],
            'year' => $periods->count() === 1 ? $primary['year'] : null,
            'month' => $periods->count() === 1 ? $primary['month'] : null,
            'selectedPeriods' => $periods->values(),
            'branchId' => $selectedBranchIds->count() === 1 ? $selectedBranchIds->first() : null,
            'selectedBranchIds' => $selectedBranchIds->values(),
            'availablePeriods' => $availablePeriods,
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
