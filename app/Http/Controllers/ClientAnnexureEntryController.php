<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkStoreClientAnnexureEntriesRequest;
use App\Http\Requests\UpdateClientAnnexureEntryNoBranchRequest;
use App\Http\Requests\UpdateClientAnnexureEntryRequest;
use App\Models\Client;
use App\Models\ClientAnnexureEntry;
use App\Services\ClientAnnexureImportService;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientAnnexureEntryController extends Controller
{
    public function bulkStore(
        BulkStoreClientAnnexureEntriesRequest $request,
        Client $client,
        ClientAnnexureImportService $importService,
    ): RedirectResponse {
        $this->authorize('view', $client);

        $validated = $request->validated();
        $period = $request->resolvedPeriod();

        $result = $importService->storeManualEntries(
            $client,
            $request->user(),
            $validated['entries'],
            $period['year'],
            $period['month'],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':count annexure entries added successfully. :unresolved without branch match.', [
                'count' => $result['imported'],
                'unresolved' => $result['unresolved'],
            ]),
        ]);

        return to_route('clients.annexure.index', [
            'client' => $client,
            'year' => $result['year'],
            'month' => $result['month'],
            'cheque' => $result['cheque_id'],
        ]);
    }

    public function update(
        UpdateClientAnnexureEntryRequest $request,
        ClientAnnexureEntry $clientAnnexureEntry,
    ): RedirectResponse {
        $this->authorize('update', $clientAnnexureEntry);

        $validated = $request->validated();
        $branchId = $validated['branch_id'] ?? null;

        if ($branchId === null) {
            $branchId = app(ClientAnnexureImportService::class)
                ->branchIdForInvoice($clientAnnexureEntry->client, $validated['invoice_no']);
        }

        $clientAnnexureEntry->update([
            'branch_id' => $branchId,
            'transaction_date' => StatementDate::parse($validated['transaction_date'])->toDateString(),
            'invoice_no' => $validated['invoice_no'],
            'amount' => StatementAmount::parse($validated['amount']),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Annexure entry updated successfully.'),
        ]);

        return to_route('clients.annexure.index', [
            'client' => $clientAnnexureEntry->client_id,
            'year' => $validated['year'] ?? $clientAnnexureEntry->transaction_date->year,
            'month' => $validated['month'] ?? $clientAnnexureEntry->transaction_date->month,
        ]);
    }

    public function updateNoBranchExpected(
        UpdateClientAnnexureEntryNoBranchRequest $request,
        ClientAnnexureEntry $clientAnnexureEntry,
    ): RedirectResponse {
        $this->authorize('update', $clientAnnexureEntry);

        $validated = $request->validated();

        $clientAnnexureEntry->update([
            'no_branch_expected' => $validated['no_branch_expected'],
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['no_branch_expected']
                ? __('Invoice marked as supplier statement (no branch expected).')
                : __('Invoice marked as expecting a branch match again.'),
        ]);

        return back();
    }

    public function destroy(Request $request, ClientAnnexureEntry $clientAnnexureEntry): RedirectResponse
    {
        $this->authorize('delete', $clientAnnexureEntry);

        $clientId = $clientAnnexureEntry->client_id;
        $year = $request->integer('year', $clientAnnexureEntry->transaction_date->year);
        $month = $request->integer('month', $clientAnnexureEntry->transaction_date->month);

        $clientAnnexureEntry->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Annexure entry deleted successfully.'),
        ]);

        return to_route('clients.annexure.index', [
            'client' => $clientId,
            'year' => $year,
            'month' => $month,
        ]);
    }
}
