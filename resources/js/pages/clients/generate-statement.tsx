import { Head, Link } from '@inertiajs/react';
import { ChevronDown, Download, FileSpreadsheet } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    MonthFilterMenu,
    YearFilterMenu,
} from '@/components/statement-filter-menus';
import { Button } from '@/components/ui/button';
import {
    AppTable,
    AppTableHeadCell,
    AppTableScroll,
} from '@/components/app-table';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import {
    buildStatementFilterQuery,
    periodKey,
    periodSelectionLabel,
} from '@/lib/statement-filters';
import { cn } from '@/lib/utils';
import { excel, pdf } from '@/routes/branches/statements/export';
import { index, show } from '@/routes/clients';
import { show as statementShow } from '@/routes/clients/statement';
import {
    excel as clientExcel,
    pdf as clientPdf,
} from '@/routes/clients/generate-statement/export';
import type {
    BranchMonthStat,
    BranchOption,
    Client,
    ClientSummary,
    StatementMonth,
} from '@/types';

const MONTHS = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' },
];

function parseAmount(value: string): number {
    return Number.parseFloat(value.replace(/,/g, '')) || 0;
}

function formatAmount(value: number): string {
    return value.toFixed(3);
}

function statsForBranches(
    branchMonthStats: BranchMonthStat[],
    branchIds: string[],
): BranchMonthStat[] {
    return branchMonthStats.filter((stat) =>
        branchIds.includes(String(stat.branch_id)),
    );
}

function availableYearsFromStats(
    branchMonthStats: BranchMonthStat[],
    branchIds: string[],
): number[] {
    const yearSet = new Set<number>();

    statsForBranches(branchMonthStats, branchIds).forEach((stat) => {
        yearSet.add(stat.year);
    });

    return Array.from(yearSet).sort((left, right) => right - left);
}

function availableMonthsFromStats(
    branchMonthStats: BranchMonthStat[],
    branchIds: string[],
    selectedYears: number[],
): { value: number; label: string }[] {
    const monthSet = new Set<number>();

    statsForBranches(branchMonthStats, branchIds)
        .filter((stat) => selectedYears.includes(stat.year))
        .forEach((stat) => monthSet.add(stat.month));

    return MONTHS.filter((item) => monthSet.has(item.value));
}

function latestPeriodFromStats(
    branchMonthStats: BranchMonthStat[],
    branchIds: string[],
): { years: number[]; months: number[] } {
    const stats = statsForBranches(branchMonthStats, branchIds).sort(
        (left, right) =>
            right.year * 100 +
            right.month -
            (left.year * 100 + left.month),
    );

    if (stats.length === 0) {
        return { years: [], months: [] };
    }

    const latest = stats[0];

    return { years: [latest.year], months: [latest.month] };
}

function effectivePeriodsFromSelection(
    branchMonthStats: BranchMonthStat[],
    branchIds: string[],
    selectedYears: number[],
    selectedMonths: number[],
): StatementMonth[] {
    const periods = new Map<string, StatementMonth>();

    statsForBranches(branchMonthStats, branchIds)
        .filter(
            (stat) =>
                selectedYears.includes(stat.year) &&
                selectedMonths.includes(stat.month),
        )
        .forEach((stat) => {
            periods.set(periodKey(stat), {
                year: stat.year,
                month: stat.month,
                label: stat.label,
            });
        });

    return Array.from(periods.values()).sort(
        (left, right) =>
            left.year * 100 + left.month - (right.year * 100 + right.month),
    );
}

export default function ClientsGenerateStatement({
    client,
    branches,
    branchMonthStats,
}: {
    client: Pick<Client, 'id' | 'name'>;
    summary: ClientSummary;
    branches: BranchOption[];
    branchMonthStats: BranchMonthStat[];
}) {
    const initialBranchIds = branches.map((branch) => String(branch.id));
    const initialPeriod = latestPeriodFromStats(
        branchMonthStats,
        initialBranchIds,
    );

    const [branchIds, setBranchIds] = useState<string[]>(initialBranchIds);
    const [selectedYears, setSelectedYears] = useState<number[]>(
        initialPeriod.years,
    );
    const [selectedMonths, setSelectedMonths] = useState<number[]>(
        initialPeriod.months,
    );

    const availableYears = useMemo(
        () => availableYearsFromStats(branchMonthStats, branchIds),
        [branchIds, branchMonthStats],
    );

    const availableMonths = useMemo(
        () =>
            availableMonthsFromStats(
                branchMonthStats,
                branchIds,
                selectedYears,
            ),
        [branchIds, branchMonthStats, selectedYears],
    );

    useEffect(() => {
        setSelectedYears((current) => {
            const next = current.filter((year) =>
                availableYears.includes(year),
            );

            if (next.length > 0) {
                return next;
            }

            return latestPeriodFromStats(branchMonthStats, branchIds).years;
        });
    }, [availableYears, branchIds, branchMonthStats]);

    useEffect(() => {
        setSelectedMonths((current) => {
            const next = current.filter((month) =>
                availableMonths.some((item) => item.value === month),
            );

            if (next.length > 0) {
                return next;
            }

            const latest = latestPeriodFromStats(
                branchMonthStats,
                branchIds,
            ).months.filter((month) =>
                availableMonths.some((item) => item.value === month),
            );

            if (latest.length > 0) {
                return latest;
            }

            return availableMonths[0] ? [availableMonths[0].value] : [];
        });
    }, [availableMonths, branchIds, branchMonthStats]);

    const selectedBranches = useMemo(
        () =>
            branches.filter((branch) =>
                branchIds.includes(String(branch.id)),
            ),
        [branchIds, branches],
    );

    const selectedPeriods = useMemo(
        () =>
            effectivePeriodsFromSelection(
                branchMonthStats,
                branchIds,
                selectedYears,
                selectedMonths,
            ),
        [branchIds, branchMonthStats, selectedMonths, selectedYears],
    );

    const branchStatsById = useMemo(() => {
        const byBranch = new Map<
            number,
            { entries_count: number; total_amount: number }
        >();
        const selectedPeriodKeys = new Set(
            selectedPeriods.map((period) => periodKey(period)),
        );

        statsForBranches(branchMonthStats, branchIds)
            .filter((stat) => selectedPeriodKeys.has(periodKey(stat)))
            .forEach((stat) => {
                const existing = byBranch.get(stat.branch_id);

                if (existing) {
                    existing.entries_count += stat.entries_count;
                    existing.total_amount += parseAmount(stat.total_amount);
                    return;
                }

                byBranch.set(stat.branch_id, {
                    entries_count: stat.entries_count,
                    total_amount: parseAmount(stat.total_amount),
                });
            });

        return byBranch;
    }, [branchIds, branchMonthStats, selectedPeriods]);

    const availablePeriods = useMemo(() => {
        const periods = new Map<
            string,
            {
                year: number;
                month: number;
                label: string;
                entries_count: number;
                total_amount: number;
            }
        >();

        statsForBranches(branchMonthStats, branchIds).forEach((stat) => {
            const key = periodKey(stat);
            const existing = periods.get(key);

            if (existing) {
                existing.entries_count += stat.entries_count;
                existing.total_amount += parseAmount(stat.total_amount);
                return;
            }

            periods.set(key, {
                year: stat.year,
                month: stat.month,
                label: stat.label,
                entries_count: stat.entries_count,
                total_amount: parseAmount(stat.total_amount),
            });
        });

        return Array.from(periods.values()).sort((left, right) => {
            if (left.year !== right.year) {
                return right.year - left.year;
            }

            return right.month - left.month;
        });
    }, [branchIds, branchMonthStats]);

    const totalEntries = useMemo(
        () =>
            Array.from(branchStatsById.values()).reduce(
                (sum, stat) => sum + stat.entries_count,
                0,
            ),
        [branchStatsById],
    );

    const totalAmount = useMemo(
        () =>
            Array.from(branchStatsById.values()).reduce(
                (sum, stat) => sum + stat.total_amount,
                0,
            ),
        [branchStatsById],
    );

    const canGenerate =
        selectedBranches.length > 0 && selectedPeriods.length > 0;

    const branchLabel =
        branchIds.length === 0
            ? 'Select branches'
            : branchIds.length === branches.length
              ? 'All branches'
              : branchIds.length === 1
                ? `${selectedBranches[0]?.code} — ${selectedBranches[0]?.name}`
                : `${branchIds.length} branches selected`;

    const periodLabel = periodSelectionLabel(selectedPeriods);

    const exportQuery = buildStatementFilterQuery(
        branchIds.map(Number),
        selectedPeriods,
    );

    const excelUrl =
        branchIds.length === 1
            ? excel.url(Number(branchIds[0]), {
                  query: buildStatementFilterQuery(
                      [Number(branchIds[0])],
                      selectedPeriods,
                  ),
              })
            : clientExcel.url(client.id, { query: exportQuery });

    const pdfUrl =
        branchIds.length === 1
            ? pdf.url(Number(branchIds[0]), {
                  query: buildStatementFilterQuery(
                      [Number(branchIds[0])],
                      selectedPeriods,
                  ),
              })
            : clientPdf.url(client.id, { query: exportQuery });

    const toggleBranch = (branchId: string, checked: boolean) => {
        setBranchIds((current) => {
            if (checked) {
                return current.includes(branchId)
                    ? current
                    : [...current, branchId];
            }

            return current.filter((id) => id !== branchId);
        });
    };

    const toggleAllBranches = (checked: boolean) => {
        setBranchIds(
            checked ? branches.map((branch) => String(branch.id)) : [],
        );
    };

    function handleYearsChange(nextYears: number[]) {
        setSelectedYears(nextYears);

        const monthsInYears = availableMonthsFromStats(
            branchMonthStats,
            branchIds,
            nextYears,
        ).map((item) => item.value);

        setSelectedMonths((current) => {
            const next = current.filter((month) =>
                monthsInYears.includes(month),
            );

            if (next.length > 0) {
                return next;
            }

            return monthsInYears.length > 0 ? [monthsInYears[0]] : [];
        });
    }

    function toggleAvailablePeriod(periodYear: number, periodMonth: number) {
        const key = `${periodYear}-${periodMonth}`;
        const isSelected = selectedPeriods.some(
            (period) => periodKey(period) === key,
        );

        if (isSelected) {
            const remaining = selectedPeriods.filter(
                (period) => periodKey(period) !== key,
            );

            if (remaining.length === 0) {
                setSelectedYears([periodYear]);
                setSelectedMonths([periodMonth]);
                return;
            }

            setSelectedYears(
                Array.from(new Set(remaining.map((period) => period.year))).sort(
                    (left, right) => right - left,
                ),
            );
            setSelectedMonths(
                Array.from(
                    new Set(remaining.map((period) => period.month)),
                ).sort((left, right) => left - right),
            );
            return;
        }

        setSelectedYears((current) =>
            Array.from(new Set([...current, periodYear])).sort(
                (left, right) => right - left,
            ),
        );
        setSelectedMonths((current) =>
            Array.from(new Set([...current, periodMonth])).sort(
                (left, right) => left - right,
            ),
        );
    }

    return (
        <>
            <Head title={`Generate Statement - ${client.name}`} />

            <Card>
                <CardHeader>
                    <CardTitle>Generate statement</CardTitle>
                    <CardDescription>
                        Select one or more branches and invoice month(s) to
                        generate statements with Branch ID, then view or export
                        them.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    {branches.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Add a branch first before generating statements.
                        </p>
                    ) : availableYears.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No invoice data found yet. Upload branch statements
                            first.
                        </p>
                    ) : (
                        <>
                            <div className="grid gap-4 md:grid-cols-3">
                                <div className="grid gap-2">
                                    <Label htmlFor="branches">Branches</Label>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                id="branches"
                                                variant="outline"
                                                className={cn(
                                                    'h-9 w-full justify-between font-normal',
                                                    branchIds.length === 0 &&
                                                        'text-muted-foreground',
                                                )}
                                            >
                                                <span className="truncate">
                                                    {branchLabel}
                                                </span>
                                                <ChevronDown className="size-4 opacity-50" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="start"
                                            className="w-[var(--radix-dropdown-menu-trigger-width)] p-0"
                                        >
                                            <div className="sticky top-0 z-10 border-b bg-popover p-1">
                                                <DropdownMenuCheckboxItem
                                                    checked={
                                                        branchIds.length ===
                                                        branches.length
                                                            ? true
                                                            : branchIds.length ===
                                                                0
                                                              ? false
                                                              : 'indeterminate'
                                                    }
                                                    onCheckedChange={
                                                        toggleAllBranches
                                                    }
                                                    onSelect={(event) =>
                                                        event.preventDefault()
                                                    }
                                                >
                                                    Select all
                                                </DropdownMenuCheckboxItem>
                                            </div>
                                            <div className="max-h-64 overflow-y-auto p-1">
                                                {branches.map((branch) => (
                                                    <DropdownMenuCheckboxItem
                                                        key={branch.id}
                                                        checked={branchIds.includes(
                                                            String(branch.id),
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            toggleBranch(
                                                                String(
                                                                    branch.id,
                                                                ),
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                        onSelect={(event) =>
                                                            event.preventDefault()
                                                        }
                                                    >
                                                        {branch.code} —{' '}
                                                        {branch.name}
                                                    </DropdownMenuCheckboxItem>
                                                ))}
                                            </div>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="years">Years</Label>
                                    <YearFilterMenu
                                        years={availableYears}
                                        selectedYears={selectedYears}
                                        onChange={handleYearsChange}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="months">Months</Label>
                                    <MonthFilterMenu
                                        months={availableMonths}
                                        selectedMonths={selectedMonths}
                                        onChange={setSelectedMonths}
                                    />
                                </div>
                            </div>

                            {selectedBranches.length > 0 && (
                                <div className="space-y-4 rounded-lg border bg-muted/30 p-4">
                                    <div className="grid gap-2 text-sm md:grid-cols-2">
                                        <p>
                                            <span className="text-muted-foreground">
                                                Period:
                                            </span>{' '}
                                            {periodLabel}
                                        </p>
                                        <p>
                                            <span className="text-muted-foreground">
                                                Branches:
                                            </span>{' '}
                                            {selectedBranches.length}
                                        </p>
                                        <p>
                                            <span className="text-muted-foreground">
                                                Total entries:
                                            </span>{' '}
                                            {totalEntries}
                                        </p>
                                        <p>
                                            <span className="text-muted-foreground">
                                                Total amount:
                                            </span>{' '}
                                            <span className="font-mono font-medium">
                                                {formatAmount(totalAmount)}
                                            </span>
                                        </p>
                                    </div>

                                    <AppTableScroll className="rounded-md border bg-background">
                                        <AppTable>
                                            <thead>
                                                <tr className="border-b text-left text-muted-foreground">
                                                    <AppTableHeadCell className="bg-background px-3 py-2 font-medium">
                                                        Branch ID
                                                    </AppTableHeadCell>
                                                    <AppTableHeadCell className="bg-background px-3 py-2 font-medium">
                                                        Branch
                                                    </AppTableHeadCell>
                                                    <AppTableHeadCell className="bg-background px-3 py-2 font-medium">
                                                        Entries
                                                    </AppTableHeadCell>
                                                    <AppTableHeadCell className="bg-background px-3 py-2 text-right font-medium">
                                                        Amount
                                                    </AppTableHeadCell>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {selectedBranches.map(
                                                    (branch) => {
                                                        const stat =
                                                            branchStatsById.get(
                                                                branch.id,
                                                            );

                                                        return (
                                                            <tr
                                                                key={branch.id}
                                                                className="border-b last:border-0"
                                                            >
                                                                <td className="px-3 py-2 font-mono">
                                                                    {
                                                                        branch.code
                                                                    }
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    {
                                                                        branch.name
                                                                    }
                                                                </td>
                                                                <td className="px-3 py-2">
                                                                    {stat?.entries_count ??
                                                                        0}
                                                                </td>
                                                                <td className="px-3 py-2 text-right font-mono">
                                                                    {stat
                                                                        ? formatAmount(
                                                                              stat.total_amount,
                                                                          )
                                                                        : '0.000'}
                                                                </td>
                                                            </tr>
                                                        );
                                                    },
                                                )}
                                            </tbody>
                                            <tfoot>
                                                <tr className="border-t bg-muted/50 font-medium">
                                                    <td
                                                        className="px-3 py-2"
                                                        colSpan={2}
                                                    >
                                                        Total
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        {totalEntries}
                                                    </td>
                                                    <td className="px-3 py-2 text-right font-mono">
                                                        {formatAmount(
                                                            totalAmount,
                                                        )}
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </AppTable>
                                    </AppTableScroll>
                                </div>
                            )}

                            {availablePeriods.length > 0 && (
                                <div className="space-y-2">
                                    <p className="text-sm font-medium">
                                        Available invoice months
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {availablePeriods.map((period) => {
                                            const isSelected =
                                                selectedPeriods.some(
                                                    (item) =>
                                                        periodKey(item) ===
                                                        periodKey(period),
                                                );

                                            return (
                                                <Button
                                                    key={periodKey(period)}
                                                    type="button"
                                                    size="sm"
                                                    variant={
                                                        isSelected
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    onClick={() =>
                                                        toggleAvailablePeriod(
                                                            period.year,
                                                            period.month,
                                                        )
                                                    }
                                                >
                                                    {period.label}
                                                </Button>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            <div className="flex flex-wrap gap-3">
                                <Button asChild disabled={!canGenerate}>
                                    <Link
                                        href={statementShow.url(client.id, {
                                            query: exportQuery,
                                        })}
                                    >
                                        <FileSpreadsheet className="size-4" />
                                        View statement
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    asChild
                                    disabled={!canGenerate}
                                >
                                    <a href={excelUrl}>
                                        <Download className="size-4" />
                                        Export Excel
                                    </a>
                                </Button>
                                <Button
                                    variant="outline"
                                    asChild
                                    disabled={!canGenerate}
                                >
                                    <a href={pdfUrl}>
                                        <FileSpreadsheet className="size-4" />
                                        Export PDF
                                    </a>
                                </Button>
                            </div>

                            {totalEntries === 0 && canGenerate && (
                                <p className="text-sm text-muted-foreground">
                                    No statement data found for the selected
                                    branches and invoice month(s). Pick
                                    available month(s) above.
                                </p>
                            )}
                        </>
                    )}
                </CardContent>
            </Card>
        </>
    );
}

ClientsGenerateStatement.layout = (props: {
    client: Pick<Client, 'id' | 'name'>;
}) => ({
    breadcrumbs: [
        { title: 'Clients', href: index() },
        { title: props.client.name, href: show(props.client.id) },
        { title: 'Generate Statement', href: '#' },
    ],
});
