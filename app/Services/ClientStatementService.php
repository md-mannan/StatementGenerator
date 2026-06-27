<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Client;
use App\Models\StatementEntry;
use App\Support\StatementAvailableMonths;
use App\Support\StatementRequestFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ClientStatementService
{
    /**
     * @return array{
     *     periods: Collection<int, array{year: int, month: int, label: string}>,
     *     primaryYear: int,
     *     primaryMonth: int,
     *     periodLabel: string,
     *     branches: Collection<int, Branch>,
     *     entries: Collection<int, StatementEntry>,
     *     total: float,
     * }
     */
    public function resolve(Request $request, Client $client): array
    {
        $branchIds = collect($request->input('branch_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($branchIds->isEmpty()) {
            $branchIds = $client->branches()->pluck('id');
        }

        abort_if($branchIds->isEmpty(), 422, __('Select at least one branch.'));

        $branches = $client->branches()
            ->whereIn('id', $branchIds)
            ->orderBy('code')
            ->get();

        abort_if($branches->isEmpty(), 422, __('Select at least one branch.'));

        $availableMonths = StatementAvailableMonths::forBranchIds($branches->pluck('id'));
        $periods = StatementRequestFilters::periodsOrAll($request);
        $primary = StatementRequestFilters::primaryPeriod($periods, $availableMonths);
        $periodLabel = StatementRequestFilters::periodLabel($periods);

        $entries = StatementEntry::query()
            ->with('branch')
            ->whereIn('branch_id', $branches->pluck('id'))
            ->forInvoiceMonths($periods)
            ->get()
            ->sortBy(fn (StatementEntry $entry): array => [
                $entry->transaction_date->format('Y-m'),
                $entry->branch->code,
                $entry->transaction_date->format('Y-m-d'),
                $entry->id,
            ])
            ->values();

        $total = (float) $entries->sum('amount');

        return [
            'periods' => $periods,
            'primaryYear' => $primary['year'],
            'primaryMonth' => $primary['month'],
            'periodLabel' => $periodLabel,
            'branches' => $branches,
            'entries' => $entries,
            'total' => $total,
        ];
    }
}
