<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientAnnexureService;
use App\Services\ClientPageSummaryService;
use App\Support\StatementAmount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientAnnexureController extends Controller
{
    public function __construct(
        private readonly ClientAnnexureService $annexureService,
        private readonly ClientPageSummaryService $pageSummary,
    ) {}

    public function index(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $resolved = $this->annexureService->resolve($request, $client);
        $periods = $resolved['periods'];
        $primary = $periods->first() ?? ['year' => $resolved['year'], 'month' => $resolved['month']];

        return Inertia::render('clients/annexure/index', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'summary' => $this->pageSummary->forAnnexure($client, $resolved),
            'phase' => $resolved['phase'],
            'chequeId' => $resolved['chequeId'],
            'selectedChequeIds' => $resolved['selectedChequeIds']->values(),
            'cheques' => $resolved['cheques']->values()->all(),
            'entries' => $resolved['entries']->values()->all(),
            'branches' => $client->branches()
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn ($branch): array => [
                    'id' => $branch->id,
                    'code' => $branch->code,
                    'name' => $branch->name,
                ])
                ->values(),
            'clientTotal' => StatementAmount::format($resolved['clientTotal']),
            'branchTotal' => StatementAmount::format($resolved['branchTotal']),
            'differenceTotal' => StatementAmount::format($resolved['differenceTotal']),
            'rebate' => StatementAmount::format($resolved['rebate']),
            'checkTotal' => StatementAmount::format($resolved['checkTotal']),
            'netAmount' => StatementAmount::format($resolved['netAmount']),
            'checkNumber' => $resolved['checkNumber'],
            'unresolvedCount' => $resolved['unresolvedCount'],
            'mismatchCount' => $resolved['mismatchCount'],
            'year' => $primary['year'],
            'month' => $primary['month'],
            'selectedPeriods' => $periods->values(),
            'periodLabel' => $resolved['periodLabel'],
            'availableMonths' => $this->annexureService->availableMonths($client),
            'previousPeriod' => '',
            'nextPeriod' => '',
        ]);
    }
}
