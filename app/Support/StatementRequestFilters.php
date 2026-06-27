<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class StatementRequestFilters
{
    /**
     * @return Collection<int, int>
     */
    public static function branchIds(Request $request, int $defaultBranchId): Collection
    {
        $branchIds = collect($request->input('branch_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($branchIds->isEmpty()) {
            return collect([$defaultBranchId]);
        }

        return $branchIds;
    }

    /**
     * @return Collection<int, array{year: int, month: int, label: string}>
     */
    public static function periods(Request $request): Collection
    {
        $periods = self::parsePeriodInput($request);

        if ($periods->isNotEmpty()) {
            return $periods;
        }

        if ($request->has('periods')) {
            return collect();
        }

        $year = (int) $request->integer('year', now()->year);
        $month = (int) $request->integer('month', now()->month);

        return collect([[
            'year' => $year,
            'month' => $month,
            'label' => Carbon::create($year, $month, 1)->format('F Y'),
        ]]);
    }

    /**
     * @return Collection<int, array{year: int, month: int, label: string}>
     */
    public static function periodsOrAll(Request $request): Collection
    {
        if ($request->has('periods')) {
            return self::parsePeriodInput($request);
        }

        if ($request->filled('year') || $request->filled('month')) {
            return self::periods($request);
        }

        return collect();
    }

    /**
     * @return Collection<int, array{year: int, month: int, label: string}>
     */
    private static function parsePeriodInput(Request $request): Collection
    {
        return collect($request->input('periods', []))
            ->map(function (mixed $value): ?array {
                if (! is_string($value) || ! str_contains($value, '-')) {
                    return null;
                }

                [$year, $month] = array_pad(explode('-', $value, 2), 2, null);

                $year = (int) $year;
                $month = (int) $month;

                if ($year < 1 || $month < 1 || $month > 12) {
                    return null;
                }

                return [
                    'year' => $year,
                    'month' => $month,
                    'label' => Carbon::create($year, $month, 1)->format('F Y'),
                ];
            })
            ->filter()
            ->unique(fn (array $period): string => $period['year'].'-'.$period['month'])
            ->sortBy(fn (array $period): int => $period['year'] * 100 + $period['month'])
            ->values();
    }

    /**
     * @return Collection<int, array{year: int, month: int, label: string}>
     */
    public static function optionalPeriods(Request $request): Collection
    {
        if ($request->has('periods')) {
            return collect($request->input('periods', []))
                ->map(function (mixed $value): ?array {
                    if (! is_string($value) || ! str_contains($value, '-')) {
                        return null;
                    }

                    [$year, $month] = array_pad(explode('-', $value, 2), 2, null);

                    $year = (int) $year;
                    $month = (int) $month;

                    if ($year < 1 || $month < 1 || $month > 12) {
                        return null;
                    }

                    return [
                        'year' => $year,
                        'month' => $month,
                        'label' => Carbon::create($year, $month, 1)->format('F Y'),
                    ];
                })
                ->filter()
                ->unique(fn (array $period): string => $period['year'].'-'.$period['month'])
                ->sortBy(fn (array $period): int => $period['year'] * 100 + $period['month'])
                ->values();
        }

        if ($request->filled('year') && $request->filled('month')) {
            $year = (int) $request->integer('year');
            $month = (int) $request->integer('month');

            return collect([[
                'year' => $year,
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('F Y'),
            ]]);
        }

        return collect();
    }

    /**
     * @return Collection<int, int>
     */
    public static function optionalBranchIds(Request $request): Collection
    {
        $branchIds = collect($request->input('branch_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($branchIds->isNotEmpty()) {
            return $branchIds;
        }

        if ($request->filled('branch')) {
            return collect([(int) $request->integer('branch')]);
        }

        return collect();
    }

    /**
     * @param  Collection<int, array{year: int, month: int, label: string}>  $periods
     */
    public static function periodLabel(Collection $periods): string
    {
        if ($periods->isEmpty()) {
            return 'All months';
        }

        if ($periods->count() === 1) {
            return $periods->first()['label'];
        }

        return $periods->count().' months';
    }

    /**
     * @param  Collection<int, array{year: int, month: int, label: string}>  $periods
     * @param  Collection<int, array{year: int, month: int, label: string}>|null  $availableMonths
     * @return array{year: int, month: int}
     */
    public static function primaryPeriod(Collection $periods, ?Collection $availableMonths = null): array
    {
        $primary = $periods->first() ?? $availableMonths?->first();

        if ($primary === null) {
            return [
                'year' => now()->year,
                'month' => now()->month,
            ];
        }

        return [
            'year' => $primary['year'],
            'month' => $primary['month'],
        ];
    }
}
