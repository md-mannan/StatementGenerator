<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkDestroyStatementEntriesRequest;
use App\Http\Requests\BulkStoreStatementEntriesRequest;
use App\Http\Requests\StoreStatementEntryRequest;
use App\Http\Requests\UpdateStatementEntryNoBillRequest;
use App\Http\Requests\UpdateStatementEntryRequest;
use App\Models\Branch;
use App\Models\StatementEntry;
use App\Services\StatementInvoiceScanService;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use App\Support\StatementPeriod;
use Illuminate\Http\RedirectResponse;
use App\Support\StatementRequestFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StatementEntryController extends Controller
{
    public function store(StoreStatementEntryRequest $request, Branch $branch): RedirectResponse
    {
        $this->authorize('update', $branch);

        $validated = $request->validated();
        $transactionDate = StatementDate::parse($validated['transaction_date']);
        $period = StatementPeriod::resolve(
            $validated['year'] ?? null,
            $validated['month'] ?? null,
            $transactionDate,
        );

        $statementEntry = StatementEntry::query()->create([
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'transaction_date' => $transactionDate->toDateString(),
            'statement_year' => $period['statement_year'],
            'statement_month' => $period['statement_month'],
            'invoice_no' => $validated['invoice_no'],
            'amount' => StatementAmount::parse($validated['amount']),
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Statement entry added successfully.'),
        ]);

        return $this->redirectToStatement(
            $statementEntry,
            $validated['year'],
            $validated['month'],
        );
    }

    public function bulkStore(
        BulkStoreStatementEntriesRequest $request,
        Branch $branch,
    ): RedirectResponse {
        $this->authorize('update', $branch);

        $validated = $request->validated();
        $period = StatementPeriod::resolve(
            $validated['year'] ?? null,
            $validated['month'] ?? null,
        );

        $imported = DB::transaction(function () use ($request, $branch, $validated, $period): int {
            $count = 0;

            foreach ($validated['entries'] as $entry) {
                $transactionDate = StatementDate::parse($entry['transaction_date']);

                StatementEntry::query()->create([
                    'branch_id' => $branch->id,
                    'user_id' => $request->user()->id,
                    'transaction_date' => $transactionDate->toDateString(),
                    'statement_year' => $period['statement_year'],
                    'statement_month' => $period['statement_month'],
                    'invoice_no' => $entry['invoice_no'],
                    'amount' => StatementAmount::parse($entry['amount']),
                ]);

                $count++;
            }

            return $count;
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':count statement entries added successfully.', [
                'count' => $imported,
            ]),
        ]);

        $period = $request->resolvedPeriod();

        return to_route('branches.statements.index', [
            'branch' => $branch,
            'year' => $period['year'],
            'month' => $period['month'],
        ]);
    }

    public function update(UpdateStatementEntryRequest $request, StatementEntry $statementEntry, StatementInvoiceScanService $scanService): RedirectResponse
    {
        $this->authorize('update', $statementEntry);

        $validated = $request->validated();
        $previousInvoiceNo = $statementEntry->invoice_no;

        $statementEntry->update([
            'transaction_date' => StatementDate::parse($validated['transaction_date'])->toDateString(),
            'invoice_no' => $validated['invoice_no'],
            'amount' => StatementAmount::parse($validated['amount']),
        ]);

        if ($previousInvoiceNo !== $validated['invoice_no']) {
            $scanService->syncFilenameAfterInvoiceChange($statementEntry->fresh());
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Statement entry updated successfully.'),
        ]);

        return $this->redirectAfterChange(
            $request,
            $statementEntry,
            $validated['year'],
            $validated['month'],
        );
    }

    public function updateNoBillExpected(
        UpdateStatementEntryNoBillRequest $request,
        StatementEntry $statementEntry,
    ): RedirectResponse {
        $this->authorize('update', $statementEntry);

        $validated = $request->validated();

        $statementEntry->update([
            'no_bill_expected' => $validated['no_bill_expected'],
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['no_bill_expected']
                ? __('Invoice marked as no bill expected.')
                : __('Invoice marked as expecting a bill again.'),
        ]);

        return back();
    }

    public function destroy(Request $request, StatementEntry $statementEntry): RedirectResponse
    {
        $this->authorize('delete', $statementEntry);

        $year = $request->integer('year', $statementEntry->transaction_date->year);
        $month = $request->integer('month', $statementEntry->transaction_date->month);

        $statementEntry->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Statement entry deleted successfully.'),
        ]);

        return $this->redirectAfterChange(
            $request,
            $statementEntry,
            $year,
            $month,
        );
    }

    public function bulkDestroy(
        BulkDestroyStatementEntriesRequest $request,
        Branch $branch,
    ): RedirectResponse {
        $this->authorize('update', $branch);

        $validated = $request->validated();
        $entryIds = collect($validated['entry_ids'])->unique()->values();
        $clientBranchIds = $branch->client->branches()->pluck('id');

        $entries = StatementEntry::query()
            ->with('branch')
            ->whereIn('id', $entryIds)
            ->whereIn('branch_id', $clientBranchIds)
            ->get();

        abort_if($entries->count() !== $entryIds->count(), 403);

        foreach ($entries as $entry) {
            $this->authorize('delete', $entry);
        }

        $deletedCount = StatementEntry::query()
            ->whereIn('id', $entries->pluck('id'))
            ->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':count statement entries deleted successfully.', [
                'count' => $deletedCount,
            ]),
        ]);

        return $this->redirectAfterBulkDestroy($request, $branch, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function redirectAfterBulkDestroy(
        Request $request,
        Branch $branch,
        array $validated,
    ): RedirectResponse {
        $params = ['branch' => $branch];

        $branchIds = StatementRequestFilters::optionalBranchIds($request);

        if ($branchIds->isNotEmpty()) {
            $params['branch_ids'] = $branchIds->all();
        }

        if ($request->has('periods')) {
            $periods = StatementRequestFilters::optionalPeriods($request);

            if ($periods->isNotEmpty()) {
                $params['periods'] = $periods
                    ->map(fn (array $period): string => $period['year'].'-'.$period['month'])
                    ->all();
            }
        } elseif (isset($validated['year'], $validated['month'])) {
            $params['year'] = $validated['year'];
            $params['month'] = $validated['month'];
        }

        return to_route('branches.statements.index', $params);
    }

    private function redirectAfterChange(
        Request $request,
        StatementEntry $statementEntry,
        ?int $year,
        ?int $month,
    ): RedirectResponse {
        $transactionDate = $statementEntry->transaction_date;
        $year ??= $transactionDate->year;
        $month ??= $transactionDate->month;

        $clientId = $request->input('client_id');
        $branchIds = $request->input('branch_ids');

        if ($clientId && is_array($branchIds) && $branchIds !== []) {
            return to_route('clients.statement.show', [
                'client' => $clientId,
                'year' => $year,
                'month' => $month,
                'branch_ids' => array_values($branchIds),
            ]);
        }

        return to_route('branches.statements.index', [
            'branch' => $statementEntry->branch_id,
            'year' => $year,
            'month' => $month,
        ]);
    }

    private function redirectToStatement(
        StatementEntry $statementEntry,
        ?int $year,
        ?int $month,
    ): RedirectResponse {
        $transactionDate = $statementEntry->transaction_date;

        return to_route('branches.statements.index', [
            'branch' => $statementEntry->branch_id,
            'year' => $year ?? $transactionDate->year,
            'month' => $month ?? $transactionDate->month,
        ]);
    }
}
