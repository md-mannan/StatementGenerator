<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Client;
use App\Models\ClientAnnexureEntry;
use App\Models\IncomingStatementEntry;
use App\Models\StatementEntry;
use App\Support\StatementAmount;
use App\Support\StatementAvailableMonths;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private readonly CrossCheckService $crossCheckService,
    ) {}

    /**
     * @return array{
     *     overview: array<string, mixed>,
     *     reconciliation: array<string, mixed>,
     *     clients: list<array<string, mixed>>,
     *     recent_uploads: list<array<string, mixed>>,
     * }
     */
    public function resolve(int $userId): array
    {
        $clients = Client::query()
            ->where('user_id', $userId)
            ->withCount('branches')
            ->orderBy('name')
            ->get();

        $clientIds = $clients->pluck('id');
        $branchIds = Branch::query()
            ->whereIn('client_id', $clientIds)
            ->pluck('id');

        $branchEntriesQuery = StatementEntry::query()->where('user_id', $userId);
        $receivedEntriesQuery = IncomingStatementEntry::query()->where('user_id', $userId);
        $annexureEntriesQuery = ClientAnnexureEntry::query()->where('user_id', $userId);

        $branchTotal = (float) (clone $branchEntriesQuery)->sum('amount');
        $receivedTotal = (float) (clone $receivedEntriesQuery)->sum('amount');
        $annexureTotal = (float) (clone $annexureEntriesQuery)->sum('amount');

        $statementMonths = $branchIds->isEmpty()
            ? 0
            : StatementEntry::query()
                ->whereIn('branch_id', $branchIds)
                ->selectRaw('DISTINCT statement_year, statement_month')
                ->get()
                ->count();

        $overview = [
            'clients' => $clients->count(),
            'branches' => (int) $clients->sum('branches_count'),
            'branch_entries' => (clone $branchEntriesQuery)->count(),
            'received_entries' => (clone $receivedEntriesQuery)->count(),
            'annexure_entries' => (clone $annexureEntriesQuery)->count(),
            'branch_total' => StatementAmount::format($branchTotal),
            'received_total' => StatementAmount::format($receivedTotal),
            'annexure_total' => StatementAmount::format($annexureTotal),
            'statement_months' => $statementMonths,
            'invoice_scans' => (clone $branchEntriesQuery)
                ->whereNotNull('invoice_scan_path')
                ->count(),
        ];

        $reconciliation = [
            'invoices' => 0,
            'matched_count' => 0,
            'complete_count' => 0,
            'mismatch_count' => 0,
            'incomplete_count' => 0,
            'branch_total' => StatementAmount::format(0),
            'received_total' => StatementAmount::format(0),
            'annexure_total' => StatementAmount::format(0),
        ];

        $branchTotalSum = 0.0;
        $receivedTotalSum = 0.0;
        $annexureTotalSum = 0.0;

        $clientSummaries = $clients->map(function (Client $client) use (
            &$reconciliation,
            &$branchTotalSum,
            &$receivedTotalSum,
            &$annexureTotalSum,
        ): array {
            $clientBranchIds = $client->branches()->pluck('id');

            $branchEntries = $clientBranchIds->isEmpty()
                ? 0
                : StatementEntry::query()->whereIn('branch_id', $clientBranchIds)->count();

            $receivedEntries = IncomingStatementEntry::query()
                ->where('client_id', $client->id)
                ->count();

            $annexureEntries = ClientAnnexureEntry::query()
                ->where('client_id', $client->id)
                ->count();

            $clientBranchTotal = $clientBranchIds->isEmpty()
                ? 0.0
                : (float) StatementEntry::query()->whereIn('branch_id', $clientBranchIds)->sum('amount');

            $lastUpload = $clientBranchIds->isEmpty()
                ? null
                : StatementEntry::query()
                    ->whereIn('branch_id', $clientBranchIds)
                    ->max('created_at');

            $crossCheck = $this->crossCheckService->resolve(
                $client,
                collect(),
                collect(),
            );

            $reconciliation['invoices'] += $crossCheck['rows']->count();
            $reconciliation['matched_count'] += $crossCheck['matchedCount'];
            $reconciliation['complete_count'] += $crossCheck['completeCount'];
            $reconciliation['mismatch_count'] += $crossCheck['mismatchCount'];
            $reconciliation['incomplete_count'] += $crossCheck['incompleteCount'];

            $branchTotalSum += $crossCheck['branchTotal'];
            $receivedTotalSum += $crossCheck['receivedTotal'];
            $annexureTotalSum += $crossCheck['annexureTotal'];

            $statementMonths = $clientBranchIds->isEmpty()
                ? 0
                : StatementAvailableMonths::forBranchIds($clientBranchIds)->count();

            return [
                'id' => $client->id,
                'name' => $client->name,
                'branches_count' => $client->branches_count,
                'branch_entries' => $branchEntries,
                'received_entries' => $receivedEntries,
                'annexure_entries' => $annexureEntries,
                'branch_total' => StatementAmount::format($clientBranchTotal),
                'branch_total_value' => $clientBranchTotal,
                'cross_check_invoices' => $crossCheck['rows']->count(),
                'matched_count' => $crossCheck['matchedCount'],
                'mismatch_count' => $crossCheck['mismatchCount'],
                'incomplete_count' => $crossCheck['incompleteCount'],
                'statement_months' => $statementMonths,
                'last_upload_at' => $lastUpload !== null
                    ? Carbon::parse($lastUpload)->format('d/m/Y H:i')
                    : null,
            ];
        })->values()->all();

        $reconciliation['branch_total'] = StatementAmount::format($branchTotalSum);
        $reconciliation['received_total'] = StatementAmount::format($receivedTotalSum);
        $reconciliation['annexure_total'] = StatementAmount::format($annexureTotalSum);

        return [
            'overview' => $overview,
            'reconciliation' => $reconciliation,
            'clients' => $clientSummaries,
            'recent_uploads' => $this->recentUploads($userId, $branchIds),
        ];
    }

    /**
     * @param  Collection<int, int>  $branchIds
     * @return list<array<string, mixed>>
     */
    private function recentUploads(int $userId, Collection $branchIds): array
    {
        if ($branchIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('statement_entries')
            ->where('user_id', $userId)
            ->whereIn('branch_id', $branchIds)
            ->select('branch_id')
            ->selectRaw('MAX(created_at) as last_upload_at')
            ->selectRaw('COUNT(*) as entries_count')
            ->groupBy('branch_id')
            ->orderByDesc('last_upload_at')
            ->limit(8)
            ->get();

        $branches = Branch::query()
            ->with('client:id,name')
            ->whereIn('id', $rows->pluck('branch_id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function (object $row) use ($branches): array {
                $branch = $branches->get($row->branch_id);

                return [
                    'client_id' => $branch?->client?->id,
                    'client_name' => $branch?->client?->name ?? '—',
                    'branch_id' => $branch?->id,
                    'branch_code' => $branch?->code ?? '—',
                    'branch_name' => $branch?->name ?? '—',
                    'entries_count' => (int) $row->entries_count,
                    'uploaded_at' => $row->last_upload_at !== null
                        ? Carbon::parse($row->last_upload_at)->format('d/m/Y H:i')
                        : null,
                ];
            })
            ->values()
            ->all();
    }
}
