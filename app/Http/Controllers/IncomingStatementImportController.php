<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportStatementRequest;
use App\Models\Client;
use App\Services\ClientPageSummaryService;
use App\Services\IncomingStatementImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IncomingStatementImportController extends Controller
{
    public function __construct(
        private readonly ClientPageSummaryService $pageSummary,
    ) {}

    public function create(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        return Inertia::render('clients/received-statements/import', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'summary' => $this->pageSummary->forReceivedStatementsImport($client),
            'year' => $request->integer('year') ?: null,
            'month' => $request->integer('month') ?: null,
        ]);
    }

    public function store(
        ImportStatementRequest $request,
        Client $client,
        IncomingStatementImportService $importService,
    ): RedirectResponse {
        $this->authorize('view', $client);

        $file = $request->file('file');
        $path = $file->getRealPath();

        if ($path === false) {
            return back()->withErrors(['file' => __('Unable to read the uploaded file.')]);
        }

        $result = $importService->import(
            $client,
            $request->user(),
            $path,
            $request->integer('year') ?: null,
            $request->integer('month') ?: null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':imported entries imported. :skipped rows skipped. :unresolved without branch match.', [
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'unresolved' => $result['unresolved'],
            ]),
        ]);

        return to_route('clients.received-statements.index', [
            'client' => $client,
            'year' => $result['year'],
            'month' => $result['month'],
        ]);
    }
}
