<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateClientAnnexureChequeRequest;
use App\Models\ClientAnnexureCheque;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClientAnnexureChequeController extends Controller
{
    public function completeReview(Request $request, ClientAnnexureCheque $clientAnnexureCheque): RedirectResponse
    {
        $this->authorize('update', $clientAnnexureCheque);

        $clientAnnexureCheque->update(['review_completed' => true]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Review completed. Enter cheque number and rebate.'),
        ]);

        return to_route('clients.annexure.index', [
            'client' => $clientAnnexureCheque->client_id,
            'year' => $clientAnnexureCheque->year,
            'month' => $clientAnnexureCheque->month,
            'cheque' => $clientAnnexureCheque->id,
        ]);
    }

    public function update(
        UpdateClientAnnexureChequeRequest $request,
        ClientAnnexureCheque $clientAnnexureCheque,
    ): RedirectResponse {
        $this->authorize('update', $clientAnnexureCheque);

        $validated = $request->validated();
        $chequeDate = StatementDate::parse($validated['cheque_date']);

        $clientAnnexureCheque->update([
            'check_number' => trim($validated['check_number']),
            'amount' => StatementAmount::parse($validated['amount']) ?? 0,
            'rebate' => StatementAmount::parse($validated['rebate']) ?? 0,
            'year' => (int) $validated['year'],
            'month' => (int) $validated['month'],
            'cheque_date' => $chequeDate?->toDateString(),
            'review_completed' => true,
            'payment_saved' => true,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Cheque saved successfully.'),
        ]);

        return to_route('clients.annexure.index', array_filter([
            'client' => $clientAnnexureCheque->client_id,
            'year' => (int) $validated['year'],
            'month' => (int) $validated['month'],
            'cheque' => $request->boolean('stay_on_cheque')
                ? $clientAnnexureCheque->id
                : null,
        ], fn ($value) => $value !== null));
    }

    public function destroy(Request $request, ClientAnnexureCheque $clientAnnexureCheque): RedirectResponse
    {
        $this->authorize('delete', $clientAnnexureCheque);

        $clientId = $clientAnnexureCheque->client_id;
        $year = $request->integer('year', $clientAnnexureCheque->year);
        $month = $request->integer('month', $clientAnnexureCheque->month);

        $clientAnnexureCheque->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Cheque and annexure entries deleted successfully.'),
        ]);

        return to_route('clients.annexure.index', [
            'client' => $clientId,
            'year' => $year,
            'month' => $month,
        ]);
    }
}
