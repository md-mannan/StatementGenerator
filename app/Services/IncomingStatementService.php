<?php

namespace App\Services;

use App\Models\Client;
use App\Models\IncomingStatementEntry;
use App\Support\StatementRequestFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class IncomingStatementService
{
    /**
     * @return array{
     *     periods: Collection<int, array{year: int, month: int, label: string}>,
     *     primaryYear: int,
     *     primaryMonth: int,
     *     periodLabel: string,
     *     entries: Collection<int, IncomingStatementEntry>,
     *     total: float,
     * }
     */
    public function resolve(Request $request, Client $client): array
    {
        $periods = $this->resolvePeriods($request, $client);
        $primary = StatementRequestFilters::primaryPeriod($periods);
        $periodLabel = StatementRequestFilters::periodLabel($periods);

        $entries = IncomingStatementEntry::query()
            ->with('branch')
            ->where('client_id', $client->id)
            ->forInvoiceMonths($periods)
            ->get()
            ->sortBy(fn (IncomingStatementEntry $entry): array => [
                $entry->transaction_date->format('Y-m'),
                $entry->transaction_date->format('Y-m-d'),
                $entry->branch?->code ?? 'ZZZ',
                $entry->id,
            ])
            ->values();

        return [
            'periods' => $periods,
            'primaryYear' => $primary['year'],
            'primaryMonth' => $primary['month'],
            'periodLabel' => $periodLabel,
            'entries' => $entries,
            'total' => (float) $entries->sum('amount'),
        ];
    }

    /**
     * @return Collection<int, array{year: int, month: int, label: string}>
     */
    public function availableInvoiceMonths(Client $client): Collection
    {
        return IncomingStatementEntry::query()
            ->where('client_id', $client->id)
            ->orderByDesc('transaction_date')
            ->get(['transaction_date'])
            ->map(fn (IncomingStatementEntry $entry): array => [
                'year' => $entry->transaction_date->year,
                'month' => $entry->transaction_date->month,
                'label' => Carbon::create(
                    $entry->transaction_date->year,
                    $entry->transaction_date->month,
                    1,
                )->format('F Y'),
            ])
            ->unique(fn (array $item): string => $item['year'].'-'.$item['month'])
            ->sortByDesc(fn (array $item): int => $item['year'] * 100 + $item['month'])
            ->values();
    }

    /**
     * @return Collection<int, array{year: int, month: int, label: string}>
     */
    private function resolvePeriods(Request $request, Client $client): Collection
    {
        if ($request->has('periods') || ($request->filled('year') && $request->filled('month'))) {
            return StatementRequestFilters::periods($request);
        }

        $default = $this->defaultPeriod($client);

        return collect([[
            'year' => $default['year'],
            'month' => $default['month'],
            'label' => Carbon::create($default['year'], $default['month'], 1)->format('F Y'),
        ]]);
    }

    /**
     * @return array{year: int, month: int}
     */
    private function defaultPeriod(Client $client): array
    {
        $latestDate = IncomingStatementEntry::query()
            ->where('client_id', $client->id)
            ->orderByDesc('transaction_date')
            ->value('transaction_date');

        if ($latestDate !== null) {
            $date = Carbon::parse($latestDate);

            return [
                'year' => $date->year,
                'month' => $date->month,
            ];
        }

        return [
            'year' => now()->year,
            'month' => now()->month,
        ];
    }
}
