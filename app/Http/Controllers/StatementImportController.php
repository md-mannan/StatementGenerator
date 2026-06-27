<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportStatementRequest;
use App\Models\Branch;
use App\Services\StatementImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatementImportController extends Controller
{
    public function create(Request $request, Branch $branch): Response
    {
        $this->authorize('import', $branch);

        $branch->load('client');

        return Inertia::render('statements/import', [
            'branch' => $branch,
            'client' => $branch->client,
            'year' => $request->integer('year') ?: null,
            'month' => $request->integer('month') ?: null,
        ]);
    }

    public function store(ImportStatementRequest $request, Branch $branch, StatementImportService $importService): RedirectResponse
    {
        $this->authorize('import', $branch);

        $file = $request->file('file');
        $path = $file->getRealPath();

        if ($path === false) {
            return back()->withErrors(['file' => __('Unable to read the uploaded file.')]);
        }

        $result = $importService->import(
            $branch,
            $request->user(),
            $path,
            $request->integer('year') ?: null,
            $request->integer('month') ?: null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':imported entries imported. :skipped rows skipped.', [
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
            ]),
        ]);

        return to_route('branches.statements.index', [
            'branch' => $branch,
            'year' => $result['year'],
            'month' => $result['month'],
        ]);
    }
}
