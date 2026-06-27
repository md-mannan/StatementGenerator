<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class StatementExportFilters
{
    /**
     * @return Collection<int, int>
     */
    public static function entryIds(Request $request): Collection
    {
        return collect($request->input('entry_ids', []))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array{
     *     entries: Collection<int, array<string, mixed>>,
     *     isFiltered: bool,
     *     branchTotal: float,
     *     chequeReceivedTotal: float,
     *     differenceTotal: float,
     *     clientStatementTotal: float,
     *     clientDifferenceTotal: float,
     * }
     */
    public static function applyEntryIds(
        Collection $entries,
        Collection $entryIds,
    ): array {
        if ($entryIds->isEmpty()) {
            return [
                'entries' => $entries,
                'isFiltered' => false,
                'branchTotal' => (float) $entries->sum('amount_value'),
                'chequeReceivedTotal' => (float) $entries
                    ->whereNotNull('cheque_received_amount_value')
                    ->sum('cheque_received_amount_value'),
                'differenceTotal' => (float) $entries
                    ->whereNotNull('difference_amount_value')
                    ->sum('difference_amount_value'),
                'clientStatementTotal' => (float) $entries
                    ->whereNotNull('client_statement_amount_value')
                    ->sum('client_statement_amount_value'),
                'clientDifferenceTotal' => (float) $entries
                    ->whereNotNull('client_difference_amount_value')
                    ->sum('client_difference_amount_value'),
            ];
        }

        $lookup = $entries->keyBy('id');

        $filtered = $entryIds
            ->map(fn (int $id): ?array => $lookup->get($id))
            ->filter()
            ->values();

        return [
            'entries' => $filtered,
            'isFiltered' => true,
            'branchTotal' => (float) $filtered->sum('amount_value'),
            'chequeReceivedTotal' => (float) $filtered
                ->whereNotNull('cheque_received_amount_value')
                ->sum('cheque_received_amount_value'),
            'differenceTotal' => (float) $filtered
                ->whereNotNull('difference_amount_value')
                ->sum('difference_amount_value'),
            'clientStatementTotal' => (float) $filtered
                ->whereNotNull('client_statement_amount_value')
                ->sum('client_statement_amount_value'),
            'clientDifferenceTotal' => (float) $filtered
                ->whereNotNull('client_difference_amount_value')
                ->sum('client_difference_amount_value'),
        ];
    }
}
