<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportStatementRequest;
use App\Models\Client;
use App\Services\ClientAnnexureImportService;
use App\Services\ClientAnnexureService;
use App\Services\ClientPageSummaryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClientAnnexureImportController extends Controller
{
    public function __construct(
        private readonly ClientAnnexureService $annexureService,
        private readonly ClientPageSummaryService $pageSummary,
    ) {}

    public function create(Client $client): Response
    {
        $this->authorize('view', $client);

        return Inertia::render('clients/annexure/import', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'summary' => $this->pageSummary->forAnnexureImport($client),
        ]);
    }

    public function store(
        ImportStatementRequest $request,
        Client $client,
        ClientAnnexureImportService $importService,
    ): RedirectResponse {
        $this->authorize('view', $client);

        $file = $request->file('file');
        $path = $file->getRealPath();

        if ($path === false) {
            return back()->withErrors(['file' => __('Unable to read the uploaded file.')]);
        }

        $result = $importService->import($client, $request->user(), $path);

        Inertia::flash('toast', [
            'type' => $result['imported'] > 0 ? 'success' : 'error',
            'message' => $result['imported'] > 0
                ? __(':imported annexure entries imported. :skipped rows skipped. :unresolved without branch match.', [
                    'imported' => $result['imported'],
                    'skipped' => $result['skipped'],
                    'unresolved' => $result['unresolved'],
                ])
                : __('No annexure entries imported. :skipped rows skipped. Check that the file has Date, Invoice, and Amount columns with a header row.', [
                    'skipped' => $result['skipped'],
                ]),
        ]);

        return to_route('clients.annexure.index', [
            'client' => $client,
            'year' => $result['year'],
            'month' => $result['month'],
            'cheque' => $result['cheque_id'],
        ]);
    }
}
