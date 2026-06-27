<?php

namespace App\Http\Controllers;

use App\Exports\IncomingMonthlyStatementExport;
use App\Models\Client;
use App\Models\IncomingStatementEntry;
use App\Services\IncomingStatementComparisonService;
use App\Services\IncomingStatementService;
use App\Support\StatementRequestFilters;
use App\Support\StatementPdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class IncomingStatementExportController extends Controller
{
    public function __construct(
        private readonly IncomingStatementService $incomingStatementService,
        private readonly IncomingStatementComparisonService $comparisonService,
    ) {}

    /**
     * @return array{
     *     periods: \Illuminate\Support\Collection<int, array{year: int, month: int, label: string}>,
     *     periodLabel: string,
     *     mappedEntries: \Illuminate\Support\Collection<int, array<string, mixed>>,
     *     total: float,
     *     branchTotal: float,
     *     totalDifference: float,
     *     isFiltered: bool,
     * }
     */
    private function resolveMapped(Request $request, Client $client): array
    {
        $resolved = $this->incomingStatementService->resolve($request, $client);
        $periods = $resolved['periods'];
        $supplierLookup = $this->comparisonService->supplierAmountLookupByInvoiceMonth(
            $client,
            $periods->all(),
        );
        $branchLookups = $this->comparisonService->branchIdLookup($client);
        $branchCodeById = $client->branches()->pluck('code', 'id')->all();
        $branchNameById = $client->branches()->pluck('name', 'id')->all();

        $mappedEntries = $resolved['entries']
            ->map(fn (IncomingStatementEntry $entry): array => $this->comparisonService->mapEntry(
                $entry,
                $supplierLookup,
                $branchLookups['byPeriod'],
                $branchLookups['byInvoice'],
                $branchCodeById,
                $branchNameById,
            ))
            ->values();

        $entryIds = collect($request->input('entry_ids', []))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $isFiltered = $entryIds->isNotEmpty();

        if ($isFiltered) {
            $lookup = $mappedEntries->keyBy('id');

            $mappedEntries = $entryIds
                ->map(fn (int $id): ?array => $lookup->get($id))
                ->filter()
                ->values();
        }

        $totals = $this->summarizeMappedEntries($mappedEntries);

        return [
            'periods' => $periods,
            'periodLabel' => $resolved['periodLabel'],
            'mappedEntries' => $mappedEntries,
            'total' => $totals['amount'],
            'branchTotal' => $totals['branch'],
            'totalDifference' => $totals['difference'],
            'isFiltered' => $isFiltered,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $mappedEntries
     * @return array{amount: float, branch: float, difference: float}
     */
    private function summarizeMappedEntries($mappedEntries): array
    {
        return [
            'amount' => $mappedEntries->sum(
                fn (array $entry): float => (float) ($entry['amount_value'] ?? 0),
            ),
            'branch' => $mappedEntries->sum(
                fn (array $entry): float => (float) ($entry['branch_amount_value'] ?? 0),
            ),
            'difference' => $mappedEntries->sum(
                fn (array $entry): float => (float) ($entry['difference_amount_value'] ?? 0),
            ),
        ];
    }

    private function exportFilename(Client $client, array $resolved): string
    {
        $periods = $resolved['periods'];
        $primary = StatementRequestFilters::primaryPeriod($periods);
        $periodSegment = $periods->count() === 1
            ? sprintf('%s-%02d', $primary['year'], $primary['month'])
            : $periods->count().'-months';

        return sprintf(
            'received-statement-%s-%s%s',
            str($client->name)->slug(),
            $periodSegment,
            $resolved['isFiltered'] ? '-filtered' : '',
        );
    }

    public function excel(Request $request, Client $client): BinaryFileResponse
    {
        $this->authorize('view', $client);

        $resolved = $this->resolveMapped($request, $client);
        $primary = StatementRequestFilters::primaryPeriod($resolved['periods']);

        return Excel::download(
            new IncomingMonthlyStatementExport(
                $resolved['mappedEntries'],
                $primary['year'],
                $primary['month'],
                $resolved['total'],
                $resolved['branchTotal'],
                $resolved['totalDifference'],
            ),
            $this->exportFilename($client, $resolved).'.xlsx',
        );
    }

    public function pdf(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $resolved = $this->resolveMapped($request, $client);

        return StatementPdf::download(
            'statements.incoming-pdf',
            [
                'client' => $client,
                'entries' => $resolved['mappedEntries'],
                'total' => $resolved['total'],
                'branchStatementTotal' => $resolved['branchTotal'],
                'totalDifference' => $resolved['totalDifference'],
                'periodLabel' => $resolved['periodLabel'].($resolved['isFiltered'] ? ' (filtered)' : ''),
            ],
            $this->exportFilename($client, $resolved).'.pdf',
            $resolved['mappedEntries']->count(),
        );
    }
}
