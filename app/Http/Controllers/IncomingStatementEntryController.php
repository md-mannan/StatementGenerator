<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkDestroyIncomingStatementEntriesRequest;
use App\Http\Requests\BulkStoreIncomingStatementEntriesRequest;
use App\Http\Requests\UpdateIncomingStatementEntryNoBranchRequest;
use App\Http\Requests\UpdateIncomingStatementEntryRequest;
use App\Models\Client;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use App\Support\StatementPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class IncomingStatementEntryController extends Controller
{
    public function bulkStore(
        BulkStoreIncomingStatementEntriesRequest $request,
        Client $client,
    ): RedirectResponse {
        $this->authorize('view', $client);

        $validated = $request->validated();
        $period = StatementPeriod::resolve(
            $validated['year'] ?? null,
            $validated['month'] ?? null,
        );

        $branchLookup = StatementEntry::query()
            ->whereHas('branch', fn ($query) => $query->where('client_id', $client->id))
            ->with('branch')
            ->get()
            ->groupBy(fn (StatementEntry $entry): string => $this->normalizeInvoiceNo($entry->invoice_no))
            ->mapWithKeys(function ($entries, string $invoiceNo): array {
                $uniqueBranches = $entries
                    ->unique('branch_id')
                    ->sortBy(fn (StatementEntry $entry): string => $entry->branch->code)
                    ->values();

                if ($uniqueBranches->isEmpty()) {
                    return [];
                }

                return [$invoiceNo => $uniqueBranches->first()->branch_id];
            })
            ->all();

        $imported = DB::transaction(function () use ($client, $request, $validated, $branchLookup, $period): int {
            $count = 0;

            foreach ($validated['entries'] as $entry) {
                $invoiceNo = $this->normalizeInvoiceNo($entry['invoice_no']);
                $branchId = $branchLookup[$invoiceNo] ?? null;
                $transactionDate = StatementDate::parse($entry['transaction_date']);

                IncomingStatementEntry::query()->create([
                    'client_id' => $client->id,
                    'user_id' => $request->user()->id,
                    'branch_id' => $branchId,
                    'transaction_date' => $transactionDate->toDateString(),
                    'statement_year' => $period['statement_year'],
                    'statement_month' => $period['statement_month'],
                    'invoice_no' => $invoiceNo,
                    'amount' => StatementAmount::parse($entry['amount']),
                ]);

                $count++;
            }

            return $count;
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':count received statement entries added successfully.', [
                'count' => $imported,
            ]),
        ]);

        $period = $request->resolvedPeriod();

        return to_route('clients.received-statements.index', [
            'client' => $client,
            'year' => $period['year'],
            'month' => $period['month'],
        ]);
    }

    public function update(
        UpdateIncomingStatementEntryRequest $request,
        IncomingStatementEntry $incomingStatementEntry,
    ): RedirectResponse {
        $this->authorize('update', $incomingStatementEntry);

        $validated = $request->validated();

        $incomingStatementEntry->update([
            'branch_id' => $validated['branch_id'] ?? null,
            'transaction_date' => StatementDate::parse($validated['transaction_date'])->toDateString(),
            'invoice_no' => $validated['invoice_no'],
            'amount' => StatementAmount::parse($validated['amount']),
        ]);

        $transactionDate = StatementDate::parse($validated['transaction_date']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Received statement entry updated successfully.'),
        ]);

        return to_route('clients.received-statements.index', [
            'client' => $incomingStatementEntry->client_id,
            'year' => $transactionDate->year,
            'month' => $transactionDate->month,
        ]);
    }

    public function updateNoBranchExpected(
        UpdateIncomingStatementEntryNoBranchRequest $request,
        IncomingStatementEntry $incomingStatementEntry,
    ): RedirectResponse {
        $this->authorize('update', $incomingStatementEntry);

        $validated = $request->validated();

        $incomingStatementEntry->update([
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

    public function destroy(Request $request, IncomingStatementEntry $incomingStatementEntry): RedirectResponse
    {
        $this->authorize('delete', $incomingStatementEntry);

        $clientId = $incomingStatementEntry->client_id;
        $year = $request->integer('year', $incomingStatementEntry->transaction_date->year);
        $month = $request->integer('month', $incomingStatementEntry->transaction_date->month);

        $incomingStatementEntry->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Received statement entry deleted successfully.'),
        ]);

        return to_route('clients.received-statements.index', [
            'client' => $clientId,
            'year' => $year,
            'month' => $month,
        ]);
    }

    public function bulkDestroy(
        BulkDestroyIncomingStatementEntriesRequest $request,
        Client $client,
    ): RedirectResponse {
        $this->authorize('view', $client);

        $validated = $request->validated();
        $entryIds = collect($validated['entry_ids'])->unique()->values();

        $deletedCount = IncomingStatementEntry::query()
            ->where('client_id', $client->id)
            ->whereIn('id', $entryIds)
            ->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':count received statement entries deleted successfully.', [
                'count' => $deletedCount,
            ]),
        ]);

        return to_route('clients.received-statements.index', [
            'client' => $client,
            'year' => $validated['year'] ?? now()->year,
            'month' => $validated['month'] ?? now()->month,
        ]);
    }

    private function normalizeInvoiceNo(string $invoiceNo): string
    {
        return trim($invoiceNo);
    }
}
