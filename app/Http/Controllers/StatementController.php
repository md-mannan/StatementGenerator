<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\BranchStatementService;
use App\Support\StatementAmount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatementController extends Controller
{
    public function __construct(
        private readonly BranchStatementService $branchStatementService,
    ) {}

    public function index(Request $request, Branch $branch): Response
    {
        $this->authorize('view', $branch);

        $branch->load('client');

        $resolved = $this->branchStatementService->resolve($request, $branch);

        $period = \Carbon\Carbon::create(
            $resolved['primaryYear'],
            $resolved['primaryMonth'],
            1,
        );

        return Inertia::render('statements/index', [
            'branch' => $branch,
            'client' => $branch->client,
            'branches' => $branch->client->branches()
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Branch $item): array => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                ])
                ->values(),
            'branchIds' => $resolved['branchIds']->values(),
            'selectedPeriods' => $resolved['periods']->values(),
            'entries' => $resolved['entries']->values(),
            'total' => StatementAmount::format($resolved['total']),
            'chequeReceivedTotal' => StatementAmount::format($resolved['chequeReceivedTotal']),
            'differenceTotal' => StatementAmount::format($resolved['differenceTotal']),
            'clientStatementTotal' => StatementAmount::format($resolved['clientStatementTotal']),
            'clientDifferenceTotal' => StatementAmount::format($resolved['clientDifferenceTotal']),
            'year' => $resolved['primaryYear'],
            'month' => $resolved['primaryMonth'],
            'periodLabel' => $resolved['periodLabel'],
            'availableMonths' => $resolved['availableMonths']->values(),
            'previousPeriod' => $period->copy()->subMonth()->format('Y-n'),
            'nextPeriod' => $period->copy()->addMonth()->format('Y-n'),
        ]);
    }
}
