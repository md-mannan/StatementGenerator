<?php

namespace App\Http\Controllers;

use App\Exports\BranchStatementExport;
use App\Exports\MultiBranchMonthlyStatementExport;
use App\Models\Branch;
use App\Models\Client;
use App\Services\BranchStatementService;
use App\Services\ClientStatementService;
use App\Support\StatementAmount;
use App\Support\StatementExportFilters;
use App\Support\StatementPdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class StatementExportController extends Controller
{
    public function __construct(
        private readonly ClientStatementService $clientStatementService,
        private readonly BranchStatementService $branchStatementService,
    ) {}

    public function excel(Request $request, Branch $branch): BinaryFileResponse
    {
        $this->authorize('export', $branch);

        $payload = $this->buildBranchExportPayload($request, $branch);

        $filename = sprintf(
            'statement-%s-%s-%s.xlsx',
            str($payload['client']->name)->slug(),
            $payload['multipleBranches']
                ? $payload['branches']->count().'-branches'
                : $payload['branch']->code,
            str($payload['periodLabel'])->slug(),
        );

        return Excel::download(
            new BranchStatementExport(
                $payload['entries'],
                $payload['periodLabel'],
                $payload['branchTotal'],
                $payload['clientStatementTotal'],
                $payload['clientDifferenceTotal'],
                $payload['chequeReceivedTotal'],
                $payload['differenceTotal'],
                $payload['multipleBranches'],
            ),
            $filename,
        );
    }

    public function pdf(Request $request, Branch $branch): Response
    {
        $this->authorize('export', $branch);

        $payload = $this->buildBranchExportPayload($request, $branch);
        $multipleBranches = $payload['multipleBranches'];
        $rows = StatementPdf::branchStatementRows($payload['entries'], $multipleBranches);

        $filename = sprintf(
            'statement-%s-%s-%s.pdf',
            str($payload['client']->name)->slug(),
            $multipleBranches
                ? $payload['branches']->count().'-branches'
                : $payload['branch']->code,
            str($payload['periodLabel'])->slug(),
        );

        return StatementPdf::download(
            'statements.branch-statement-pdf',
            [
                'clientName' => $payload['client']->name,
                'branchLabel' => $multipleBranches
                    ? $payload['branches']->count().' branches'
                    : $payload['branch']->code.' — '.$payload['branch']->name,
                'rows' => $rows,
                'branchTotal' => StatementAmount::format($payload['branchTotal']),
                'clientStatementTotal' => StatementAmount::format($payload['clientStatementTotal']),
                'clientDifferenceTotal' => StatementAmount::format($payload['clientDifferenceTotal']),
                'chequeReceivedTotal' => StatementAmount::format($payload['chequeReceivedTotal']),
                'differenceTotal' => StatementAmount::format($payload['differenceTotal']),
                'periodLabel' => $payload['periodLabel'].($payload['isFiltered'] ? ' (filtered)' : ''),
                'multipleBranches' => $multipleBranches,
                'columnCount' => $multipleBranches ? 11 : 9,
                'totalLabelSpan' => $multipleBranches ? 5 : 3,
            ],
            $filename,
            count($rows),
        );
    }

    public function clientExcel(Request $request, Client $client): BinaryFileResponse
    {
        $this->authorize('view', $client);

        $resolved = $this->clientStatementService->resolve($request, $client);

        $filename = sprintf(
            'statement-%s-%s.xlsx',
            str($client->name)->slug(),
            str($resolved['periodLabel'])->slug(),
        );

        return Excel::download(
            new MultiBranchMonthlyStatementExport(
                $client,
                $resolved['entries'],
                $resolved['periodLabel'],
                $resolved['total'],
            ),
            $filename,
        );
    }

    public function clientPdf(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $resolved = $this->clientStatementService->resolve($request, $client);

        $filename = sprintf(
            'statement-%s-%s.pdf',
            str($client->name)->slug(),
            str($resolved['periodLabel'])->slug(),
        );

        return StatementPdf::download(
            'statements.multi-branch-pdf',
            [
                'client' => $client,
                'entries' => $resolved['entries'],
                'total' => $resolved['total'],
                'periodLabel' => $resolved['periodLabel'],
            ],
            $filename,
            $resolved['entries']->count(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBranchExportPayload(Request $request, Branch $branch): array
    {
        $branch->load('client');
        $resolved = $this->branchStatementService->resolve($request, $branch);
        $multipleBranches = $resolved['branches']->count() > 1;
        $filtered = StatementExportFilters::applyEntryIds(
            $resolved['entries'],
            StatementExportFilters::entryIds($request),
        );

        return [
            'branch' => $branch,
            'client' => $branch->client,
            'branches' => $resolved['branches'],
            'entries' => $filtered['entries'],
            'periodLabel' => $resolved['periodLabel'],
            'branchTotal' => $filtered['branchTotal'],
            'chequeReceivedTotal' => $filtered['chequeReceivedTotal'],
            'differenceTotal' => $filtered['differenceTotal'],
            'clientStatementTotal' => $filtered['clientStatementTotal'],
            'clientDifferenceTotal' => $filtered['clientDifferenceTotal'],
            'multipleBranches' => $multipleBranches,
            'isFiltered' => $filtered['isFiltered'],
        ];
    }
}
