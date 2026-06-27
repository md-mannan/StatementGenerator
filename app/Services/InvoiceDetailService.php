<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAnnexureEntry;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use Carbon\Carbon;

class InvoiceDetailService
{
    public function __construct(
        private readonly CrossCheckService $crossCheckService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolve(Client $client, string $invoiceNo): ?array
    {
        $normalized = trim($invoiceNo);

        if ($normalized === '') {
            return null;
        }

        $summary = $this->crossCheckService->resolveInvoice($client, $normalized);

        if ($summary === null) {
            return null;
        }

        $branchIds = $client->branches()->pluck('id');

        $chequeNumber = $summary['cheque_number'];
        $chequePeriod = $summary['cheque_period'];

        $branchEntries = $branchIds->isEmpty()
            ? collect()
            : StatementEntry::query()
                ->whereIn('branch_id', $branchIds)
                ->whereRaw('TRIM(invoice_no) = ?', [$normalized])
                ->with('branch:id,code,name,client_id')
                ->orderByDesc('transaction_date')
                ->get()
                ->map(fn (StatementEntry $entry): array => $this->mapBranchEntry(
                    $entry,
                    $chequeNumber,
                    $chequePeriod,
                ));

        $receivedEntries = IncomingStatementEntry::query()
            ->where('client_id', $client->id)
            ->whereRaw('TRIM(invoice_no) = ?', [$normalized])
            ->with('branch:id,code,name')
            ->orderByDesc('transaction_date')
            ->get()
            ->map(fn (IncomingStatementEntry $entry): array => $this->mapReceivedEntry(
                $entry,
                $chequeNumber,
                $chequePeriod,
            ));

        $annexureEntries = ClientAnnexureEntry::query()
            ->where('client_id', $client->id)
            ->whereRaw('TRIM(invoice_no) = ?', [$normalized])
            ->with([
                'branch:id,code,name',
                'annexureCheque:id,year,month,check_number,payment_saved,cheque_date',
            ])
            ->orderByDesc('transaction_date')
            ->get()
            ->map(fn (ClientAnnexureEntry $entry): array => $this->mapAnnexureEntry($entry));

        return [
            'invoice_no' => $summary['invoice_no'],
            'invoice_date' => $summary['invoice_date'],
            'statement_period' => $summary['statement_period'],
            'branch_id' => $summary['branch_id'],
            'branch_code' => $summary['branch_code'],
            'status' => $summary['status'],
            'missing_sources' => $summary['missing_sources'],
            'has_amount_mismatch' => $summary['has_amount_mismatch'] ?? false,
            'cheque_issued' => $summary['cheque_issued'] ?? false,
            'invoice_date_differs_from_period' => $summary['invoice_date_differs_from_period'] ?? false,
            'branch_amount' => $summary['branch_amount'],
            'branch_amount_value' => $summary['branch_amount_value'],
            'received_amount' => $summary['received_amount'],
            'received_amount_value' => $summary['received_amount_value'],
            'annexure_amount' => $summary['annexure_amount'],
            'annexure_amount_value' => $summary['annexure_amount_value'],
            'cheque_number' => $summary['cheque_number'],
            'cheque_period' => $summary['cheque_period'],
            'branch_entries' => $branchEntries->values()->all(),
            'received_entries' => $receivedEntries->values()->all(),
            'annexure_entries' => $annexureEntries->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBranchEntry(
        StatementEntry $entry,
        ?string $chequeNumber = null,
        ?string $chequePeriod = null,
    ): array {
        $year = (int) ($entry->statement_year ?? $entry->transaction_date->year);
        $month = (int) ($entry->statement_month ?? $entry->transaction_date->month);

        return [
            'id' => $entry->id,
            'branch_id' => $entry->branch_id,
            'branch_code' => $entry->branch?->code,
            'branch_name' => $entry->branch?->name,
            'transaction_date' => StatementDate::format($entry->transaction_date),
            'statement_period' => Carbon::create($year, $month, 1)->format('M Y'),
            'invoice_no' => trim($entry->invoice_no),
            'amount' => StatementAmount::format($entry->amount),
            'amount_value' => (float) $entry->amount,
            'cheque_number' => $chequeNumber,
            'cheque_period' => $chequePeriod,
            'source_url' => route('branches.statements.index', $entry->branch_id).'?'.http_build_query([
                'year' => $entry->transaction_date->year,
                'month' => $entry->transaction_date->month,
                'search' => trim($entry->invoice_no),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapReceivedEntry(
        IncomingStatementEntry $entry,
        ?string $chequeNumber = null,
        ?string $chequePeriod = null,
    ): array {
        return [
            'id' => $entry->id,
            'branch_id' => $entry->branch_id,
            'branch_code' => $entry->branch?->code,
            'branch_name' => $entry->branch?->name,
            'transaction_date' => StatementDate::format($entry->transaction_date),
            'invoice_no' => trim($entry->invoice_no),
            'amount' => StatementAmount::format($entry->amount),
            'amount_value' => (float) $entry->amount,
            'cheque_number' => $chequeNumber,
            'cheque_period' => $chequePeriod,
            'source_url' => route('clients.received-statements.index', $entry->client_id).'?'.http_build_query([
                'year' => $entry->transaction_date->year,
                'month' => $entry->transaction_date->month,
                'search' => trim($entry->invoice_no),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAnnexureEntry(ClientAnnexureEntry $entry): array
    {
        $cheque = $entry->annexureCheque;
        $chequeYear = $cheque?->year ?? $entry->transaction_date->year;
        $chequeMonth = $cheque?->month ?? $entry->transaction_date->month;

        return [
            'id' => $entry->id,
            'branch_id' => $entry->branch_id,
            'branch_code' => $entry->branch?->code,
            'branch_name' => $entry->branch?->name,
            'transaction_date' => StatementDate::format($entry->transaction_date),
            'invoice_no' => trim($entry->invoice_no),
            'amount' => StatementAmount::format($entry->amount),
            'amount_value' => (float) $entry->amount,
            'cheque_id' => $entry->client_annexure_cheque_id,
            'cheque_number' => $cheque?->check_number,
            'cheque_period' => $chequeYear > 0 && $chequeMonth > 0
                ? Carbon::create($chequeYear, $chequeMonth, 1)->format('M Y')
                : null,
            'payment_saved' => (bool) ($cheque?->payment_saved ?? false),
            'source_url' => route('clients.annexure.index', $entry->client_id).'?'.http_build_query(array_filter([
                'year' => $chequeYear,
                'month' => $chequeMonth,
                'cheque' => $entry->client_annexure_cheque_id,
                'search' => trim($entry->invoice_no),
            ])),
        ];
    }
}
