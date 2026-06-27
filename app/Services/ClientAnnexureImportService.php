<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAnnexureCheque;
use App\Models\ClientAnnexureEntry;
use App\Models\StatementEntry;
use App\Models\User;
use App\Support\StatementAmount;
use App\Support\StatementDate;
use App\Support\StatementSpreadsheet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;

class ClientAnnexureImportService
{
    /**
     * @return array{imported: int, skipped: int, unresolved: int, year: int, month: int, cheque_id: int|null}
     */
    public function import(Client $client, User $user, string $filePath): array
    {
        $rows = Excel::toCollection(new ClientAnnexureRowsImport, $filePath)->first() ?? collect();
        $branchLookup = $this->buildBranchLookup($client);

        $imported = 0;
        $skipped = 0;
        $unresolved = 0;
        $monthCounts = [];
        $entriesByMonth = [];

        foreach ($rows as $row) {
            $parsed = $this->parseRow($row);

            if ($parsed === null) {
                $skipped++;

                continue;
            }

            $branchId = $branchLookup[$parsed['invoice_no']] ?? null;

            if ($branchId === null) {
                $unresolved++;
            }

            $periodKey = Carbon::parse($parsed['transaction_date'])->format('Y-n');
            $monthCounts[$periodKey] = ($monthCounts[$periodKey] ?? 0) + 1;

            $entriesByMonth[$periodKey][] = [
                'branch_id' => $branchId,
                'transaction_date' => $parsed['transaction_date'],
                'invoice_no' => $parsed['invoice_no'],
                'amount' => $parsed['amount'],
            ];

            $imported++;
        }

        $chequeId = null;

        DB::transaction(function () use ($client, $user, $entriesByMonth, &$chequeId): void {
            foreach ($entriesByMonth as $periodKey => $entries) {
                [$year, $month] = array_map(intval(...), explode('-', (string) $periodKey));

                $cheque = ClientAnnexureCheque::query()->create([
                    'client_id' => $client->id,
                    'user_id' => $user->id,
                    'year' => $year,
                    'month' => $month,
                    'check_number' => '',
                    'amount' => 0,
                    'rebate' => 0,
                    'review_completed' => false,
                    'payment_saved' => false,
                ]);

                $chequeId = $cheque->id;

                foreach ($entries as $entry) {
                    ClientAnnexureEntry::query()->create([
                        'client_id' => $client->id,
                        'user_id' => $user->id,
                        'client_annexure_cheque_id' => $cheque->id,
                        ...$entry,
                    ]);
                }
            }
        });

        [$year, $month] = $this->resolveRedirectPeriod($monthCounts);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'unresolved' => $unresolved,
            'year' => $year,
            'month' => $month,
            'cheque_id' => $chequeId,
        ];
    }

    /**
     * @param  array<int, array{transaction_date: string, invoice_no: string, amount: mixed}>  $entries
     * @return array{imported: int, unresolved: int, year: int, month: int, cheque_id: int}
     */
    public function storeManualEntries(
        Client $client,
        User $user,
        array $entries,
        int $year,
        int $month,
    ): array {
        $branchLookup = $this->buildBranchLookup($client);
        $unresolved = 0;
        $imported = 0;

        $chequeId = DB::transaction(function () use (
            $client,
            $user,
            $entries,
            $year,
            $month,
            $branchLookup,
            &$unresolved,
            &$imported,
        ): int {
            $cheque = ClientAnnexureCheque::query()->create([
                'client_id' => $client->id,
                'user_id' => $user->id,
                'year' => $year,
                'month' => $month,
                'check_number' => '',
                'amount' => 0,
                'rebate' => 0,
                'review_completed' => false,
                'payment_saved' => false,
            ]);

            foreach ($entries as $entry) {
                $invoiceNo = $this->normalizeInvoiceNo($entry['invoice_no']);
                $branchId = $branchLookup[$invoiceNo] ?? null;

                if ($branchId === null) {
                    $unresolved++;
                }

                ClientAnnexureEntry::query()->create([
                    'client_id' => $client->id,
                    'user_id' => $user->id,
                    'client_annexure_cheque_id' => $cheque->id,
                    'branch_id' => $branchId,
                    'transaction_date' => StatementDate::parse($entry['transaction_date'])->toDateString(),
                    'invoice_no' => $invoiceNo,
                    'amount' => StatementAmount::parse($entry['amount']),
                ]);

                $imported++;
            }

            return $cheque->id;
        });

        return [
            'imported' => $imported,
            'unresolved' => $unresolved,
            'year' => $year,
            'month' => $month,
            'cheque_id' => $chequeId,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function buildBranchLookup(Client $client): array
    {
        return StatementEntry::query()
            ->whereHas('branch', fn ($query) => $query->where('client_id', $client->id))
            ->with('branch')
            ->get()
            ->groupBy(fn (StatementEntry $entry): string => $this->normalizeInvoiceNo($entry->invoice_no))
            ->mapWithKeys(function (Collection $entries, string $invoiceNo): array {
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
    }

    public function branchIdForInvoice(Client $client, string $invoiceNo): ?int
    {
        $lookup = $this->buildBranchLookup($client);

        return $lookup[$this->normalizeInvoiceNo($invoiceNo)] ?? null;
    }

    private function parseRow(Collection $row): ?array
    {
        return StatementSpreadsheet::parseEntryRow($row);
    }

    private function normalizeInvoiceNo(string $invoiceNo): string
    {
        return trim($invoiceNo);
    }

    /**
     * @param  array<string, int>  $monthCounts
     * @return array{0: int, 1: int}
     */
    private function resolveRedirectPeriod(array $monthCounts): array
    {
        if ($monthCounts === []) {
            return [now()->year, now()->month];
        }

        arsort($monthCounts);
        $period = (string) array_key_first($monthCounts);
        [$year, $month] = array_map(intval(...), explode('-', (string) $period));

        return [$year, $month];
    }
}

class ClientAnnexureRowsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $collection): Collection
    {
        return $collection;
    }
}
