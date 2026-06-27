<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStatementInvoiceScanRequest;
use App\Models\StatementEntry;
use App\Services\StatementInvoiceScanService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatementInvoiceScanController extends Controller
{
    public function store(
        StoreStatementInvoiceScanRequest $request,
        StatementEntry $statementEntry,
        StatementInvoiceScanService $scanService,
    ): RedirectResponse {
        $this->authorize('update', $statementEntry);

        try {
            $scanService->store($statementEntry, $request->file('scan'));
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors([
                'scan' => $exception->getMessage(),
            ]);
        }

        $extension = pathinfo($statementEntry->fresh()->invoice_scan_path ?? '', PATHINFO_EXTENSION) ?: 'pdf';

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Invoice scan saved as :name.', [
                'name' => $scanService->filenameFor($statementEntry->invoice_no, $extension),
            ]),
        ]);

        return back();
    }

    public function show(
        StatementEntry $statementEntry,
        StatementInvoiceScanService $scanService,
    ): StreamedResponse {
        $this->authorize('update', $statementEntry);

        return $scanService->show($statementEntry);
    }

    public function destroy(
        StatementEntry $statementEntry,
        StatementInvoiceScanService $scanService,
    ): RedirectResponse {
        $this->authorize('update', $statementEntry);

        $scanService->delete($statementEntry);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Invoice scan removed.'),
        ]);

        return back();
    }
}
