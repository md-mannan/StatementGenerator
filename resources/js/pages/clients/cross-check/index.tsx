import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useSearchFromUrl } from '@/hooks/use-search-from-url';
import { useStatusFiltersFromUrl } from '@/hooks/use-reconciliation-filters-from-url';
import { BackToTopButton } from '@/components/back-to-top-button';
import { InvoiceNoLink } from '@/components/invoice-no-link';
import { ReconciliationLegend } from '@/components/reconciliation-legend';
import {
    BranchFilterMenu,
    OptionFilterMenu,
    PeriodFilterMenu,
} from '@/components/statement-filter-menus';
import { SortableTableHead } from '@/components/sortable-table-head';
import {
    AppTable,
    AppTableBodyCell,
    AppTableHeadCell,
    AppTableScroll,
} from '@/components/app-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { periodKey } from '@/lib/statement-filters';
import {
    compareNumbers,
    compareStrings,
    parseDdMmYyyy,
    toggleSortDirection,
    type SortDirection,
} from '@/lib/table-sort';
import { cn } from '@/lib/utils';
import { index as crossCheckIndex } from '@/routes/clients/cross-check';
import type {
    BranchOption,
    Client,
    ClientSummary,
    CrossCheckRow,
    StatementMonth,
} from '@/types';

type Props = {
    client: Pick<Client, 'id' | 'name'>;
    summary: ClientSummary;
    rows: CrossCheckRow[];
    branchTotal: string;
    receivedTotal: string;
    annexureTotal: string;
    matchedCount: number;
    completeCount: number;
    mismatchCount: number;
    incompleteCount: number;
    year: number | null;
    month: number | null;
    selectedPeriods: StatementMonth[];
    branchId: number | null;
    selectedBranchIds: number[];
    availablePeriods: StatementMonth[];
    branches: BranchOption[];
};

type RowSortColumn =
    | 'statement_period'
    | 'invoice_date'
    | 'branch_code'
    | 'invoice_no'
    | 'branch_amount'
    | 'received_amount'
    | 'annexure_amount'
    | 'cheque_number'
    | 'status';

type RowFilter =
    | 'all'
    | 'matched'
    | 'complete'
    | 'mismatch'
    | 'incomplete'
    | 'missing_branch'
    | 'missing_received'
    | 'missing_annexure';

const STATUS_FILTER_OPTIONS: Exclude<RowFilter, 'all'>[] = [
    'matched',
    'complete',
    'mismatch',
    'incomplete',
    'missing_branch',
    'missing_received',
    'missing_annexure',
];

function statusFilterLabel(filter: Exclude<RowFilter, 'all'>): string {
    switch (filter) {
        case 'matched':
            return 'Matched';
        case 'complete':
            return 'Complete';
        case 'mismatch':
            return 'Mismatches';
        case 'incomplete':
            return 'Incomplete';
        case 'missing_branch':
            return 'Missing branch';
        case 'missing_received':
            return 'Missing received';
        case 'missing_annexure':
            return 'Missing annexure';
    }
}

function rowMatchesStatusFilter(
    row: CrossCheckRow,
    filter: RowFilter,
): boolean {
    if (filter === 'all') {
        return true;
    }

    switch (filter) {
        case 'matched':
            return row.status === 'matched';
        case 'complete':
            return row.status === 'complete';
        case 'mismatch':
            return row.status === 'mismatch';
        case 'incomplete':
            return row.status === 'incomplete';
        case 'missing_branch':
            return row.missing_sources.includes('branch');
        case 'missing_received':
            return row.missing_sources.includes('received');
        case 'missing_annexure':
            return row.missing_sources.includes('annexure');
    }
}

function rowMatchesStatusFilters(
    row: CrossCheckRow,
    filters: Exclude<RowFilter, 'all'>[],
): boolean {
    if (filters.length === 0) {
        return true;
    }

    return filters.some((filter) => rowMatchesStatusFilter(row, filter));
}

function statusLabel(status: CrossCheckRow['status']): string {
    switch (status) {
        case 'matched':
            return 'Matched';
        case 'complete':
            return 'Complete';
        case 'mismatch':
            return 'Mismatch';
        case 'incomplete':
            return 'Incomplete';
    }
}

function statusVariant(
    status: CrossCheckRow['status'],
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'matched':
            return 'default';
        case 'complete':
            return 'outline';
        case 'mismatch':
            return 'destructive';
        case 'incomplete':
            return 'secondary';
    }
}

function amountValue(row: CrossCheckRow, field: keyof CrossCheckRow): number {
    const valueKey = `${field}_value` as keyof CrossCheckRow;
    const raw = row[valueKey];

    if (typeof raw === 'number') {
        return raw;
    }

    const formatted = row[field];

    return typeof formatted === 'string' ? Number(formatted) : -1;
}

export default function ClientsCrossCheckIndex({
    client,
    rows,
    branchTotal,
    receivedTotal,
    annexureTotal,
    matchedCount,
    completeCount,
    mismatchCount,
    incompleteCount,
    year,
    month,
    selectedPeriods,
    branchId,
    selectedBranchIds,
    availablePeriods,
    branches,
}: Props) {
    const [sortColumn, setSortColumn] =
        useState<RowSortColumn>('statement_period');
    const [sortDirection, setSortDirection] =
        useState<SortDirection>('desc');
    const [search, setSearch] = useSearchFromUrl();
    const [statusFilters, setStatusFilters] = useStatusFiltersFromUrl(
        STATUS_FILTER_OPTIONS,
    );

    const statusFilterOptions = useMemo(() => {
        return STATUS_FILTER_OPTIONS.filter((value) =>
            rows.some((row) => rowMatchesStatusFilter(row, value)),
        ).map((value) => ({
            value,
            label: statusFilterLabel(value),
        }));
    }, [rows]);

    useEffect(() => {
        setStatusFilters((current) =>
            current.filter((filter) =>
                statusFilterOptions.some((option) => option.value === filter),
            ),
        );
    }, [statusFilterOptions]);

    const filterBranchIds =
        selectedBranchIds.length === 0
            ? branches.map((branch) => branch.id)
            : selectedBranchIds;

    function handleSort(column: RowSortColumn) {
        setSortDirection((currentDirection) =>
            toggleSortDirection(sortColumn, column, currentDirection),
        );
        setSortColumn(column);
    }

    function applyServerFilters(next: {
        periods?: StatementMonth[];
        branchIds?: number[];
    }) {
        const periods = next.periods ?? selectedPeriods;
        const branchIds = next.branchIds ?? selectedBranchIds;
        const query: Record<string, number[] | string[]> = {};

        if (periods.length > 0) {
            query.periods = periods.map(periodKey);
        }

        if (branchIds.length > 0) {
            query.branch_ids = branchIds;
        }

        router.get(crossCheckIndex.url(client.id, { query }), {}, {
            preserveState: true,
        });
    }

    const filteredRows = useMemo(() => {
        const query = search.trim().toLowerCase();

        return rows.filter((row) => {
            const matchesSearch =
                query === '' ||
                row.invoice_no.toLowerCase().includes(query) ||
                (row.branch_code?.toLowerCase().includes(query) ?? false) ||
                (row.invoice_date?.includes(query) ?? false) ||
                row.statement_period.toLowerCase().includes(query) ||
                (row.cheque_number?.toLowerCase().includes(query) ?? false) ||
                (row.cheque_period?.toLowerCase().includes(query) ?? false) ||
                (row.branch_amount?.includes(query) ?? false) ||
                (row.received_amount?.includes(query) ?? false) ||
                (row.annexure_amount?.includes(query) ?? false);

            const matchesFilter = rowMatchesStatusFilters(row, statusFilters);

            return matchesSearch && matchesFilter;
        });
    }, [rows, search, statusFilters]);

    const displayedRows = useMemo(() => {
        return [...filteredRows].sort((left, right) => {
            switch (sortColumn) {
                case 'statement_period':
                    return compareNumbers(
                        left.statement_year * 100 + left.statement_month,
                        right.statement_year * 100 + right.statement_month,
                        sortDirection,
                    );
                case 'invoice_date':
                    return compareNumbers(
                        left.invoice_date
                            ? parseDdMmYyyy(left.invoice_date)
                            : 0,
                        right.invoice_date
                            ? parseDdMmYyyy(right.invoice_date)
                            : 0,
                        sortDirection,
                    );
                case 'branch_code':
                    return compareStrings(
                        left.branch_code ?? 'ZZZ',
                        right.branch_code ?? 'ZZZ',
                        sortDirection,
                    );
                case 'invoice_no':
                    return compareStrings(
                        left.invoice_no,
                        right.invoice_no,
                        sortDirection,
                    );
                case 'branch_amount':
                    return compareNumbers(
                        amountValue(left, 'branch_amount'),
                        amountValue(right, 'branch_amount'),
                        sortDirection,
                    );
                case 'received_amount':
                    return compareNumbers(
                        amountValue(left, 'received_amount'),
                        amountValue(right, 'received_amount'),
                        sortDirection,
                    );
                case 'annexure_amount':
                    return compareNumbers(
                        amountValue(left, 'annexure_amount'),
                        amountValue(right, 'annexure_amount'),
                        sortDirection,
                    );
                case 'cheque_number':
                    return compareStrings(
                        left.cheque_number ?? '',
                        right.cheque_number ?? '',
                        sortDirection,
                    );
                case 'status':
                    return compareStrings(
                        left.status,
                        right.status,
                        sortDirection,
                    );
                default:
                    return 0;
            }
        });
    }, [filteredRows, sortColumn, sortDirection]);

    const hasActiveFilters =
        search.trim() !== '' ||
        statusFilters.length > 0 ||
        selectedPeriods.length > 0 ||
        selectedBranchIds.length > 0;

    const displayedBranchTotal = useMemo(
        () =>
            displayedRows.reduce(
                (sum, row) => sum + (row.branch_amount_value ?? 0),
                0,
            ),
        [displayedRows],
    );

    const displayedReceivedTotal = useMemo(
        () =>
            displayedRows.reduce(
                (sum, row) => sum + (row.received_amount_value ?? 0),
                0,
            ),
        [displayedRows],
    );

    const displayedAnnexureTotal = useMemo(
        () =>
            displayedRows.reduce(
                (sum, row) => sum + (row.annexure_amount_value ?? 0),
                0,
            ),
        [displayedRows],
    );

    const footerBranchTotal =
        search.trim() !== '' || statusFilters.length > 0
            ? displayedBranchTotal.toLocaleString(undefined, {
                  minimumFractionDigits: 3,
                  maximumFractionDigits: 3,
              })
            : branchTotal;

    const footerReceivedTotal =
        search.trim() !== '' || statusFilters.length > 0
            ? displayedReceivedTotal.toLocaleString(undefined, {
                  minimumFractionDigits: 3,
                  maximumFractionDigits: 3,
              })
            : receivedTotal;

    const footerAnnexureTotal =
        search.trim() !== '' || statusFilters.length > 0
            ? displayedAnnexureTotal.toLocaleString(undefined, {
                  minimumFractionDigits: 3,
                  maximumFractionDigits: 3,
              })
            : annexureTotal;

    return (
        <>
            <Head title={`All Invoices — ${client.name}`} />

            <div className="flex flex-col gap-6">
                <Card>
                    <CardHeader>
                        <CardTitle>All Invoices</CardTitle>
                        <CardDescription>
                            Search any invoice to see branch, received, and
                            annexure amounts in one place. Click an invoice
                            number for full details.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <div className="flex flex-wrap gap-3">
                            <PeriodFilterMenu
                                availableMonths={availablePeriods}
                                selectedPeriods={selectedPeriods}
                                emptyLabel="All months"
                                allowEmpty
                                onChange={(periods) =>
                                    applyServerFilters({ periods })
                                }
                            />

                            <BranchFilterMenu
                                branches={branches}
                                branchIds={filterBranchIds}
                                onChange={(branchIds) =>
                                    applyServerFilters({
                                        branchIds:
                                            branchIds.length === branches.length
                                                ? []
                                                : branchIds,
                                    })
                                }
                            />

                            <OptionFilterMenu
                                options={statusFilterOptions}
                                selectedValues={statusFilters}
                                onChange={(values) =>
                                    setStatusFilters(
                                        values as Exclude<RowFilter, 'all'>[],
                                    )
                                }
                                emptyLabel="All statuses"
                            />

                            <div className="relative min-w-0 w-full flex-1 sm:min-w-[12rem]">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search invoice, branch, cheque..."
                                    className="pl-9"
                                />
                            </div>

                            {hasActiveFilters && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setSearch('');
                                        setStatusFilters([]);
                                        if (
                                            selectedPeriods.length > 0 ||
                                            selectedBranchIds.length > 0
                                        ) {
                                            router.get(
                                                crossCheckIndex.url(client.id),
                                            );
                                        }
                                    }}
                                >
                                    Clear filters
                                </Button>
                            )}
                        </div>

                        <div className="flex flex-wrap gap-4 text-sm text-muted-foreground">
                            <span>
                                Showing {displayedRows.length} of {rows.length}{' '}
                                invoices
                            </span>
                            <span>{matchedCount} matched</span>
                            <span>{completeCount} complete</span>
                            <span>{mismatchCount} mismatches</span>
                            <span>{incompleteCount} incomplete</span>
                        </div>
                        <ReconciliationLegend className="mt-3" compact />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <AppTableScroll>
                            <AppTable>
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <AppTableHeadCell className="px-4 py-3 font-medium">
                                            Sl
                                        </AppTableHeadCell>
                                        <SortableTableHead
                                            label="Invoice month"
                                            column="statement_period"
                                            activeColumn={sortColumn}
                                            direction={sortDirection}
                                            onSort={(column) =>
                                                handleSort(column as RowSortColumn)
                                            }
                                            className="px-4 py-3"
                                        />
                                        <SortableTableHead
                                            label="Invoice date"
                                            column="invoice_date"
                                            activeColumn={sortColumn}
                                            direction={sortDirection}
                                            onSort={(column) =>
                                                handleSort(column as RowSortColumn)
                                            }
                                            className="px-4 py-3"
                                        />
                                        <SortableTableHead
                                            label="Branch"
                                            column="branch_code"
                                            activeColumn={sortColumn}
                                            direction={sortDirection}
                                            onSort={(column) =>
                                                handleSort(column as RowSortColumn)
                                            }
                                            className="px-4 py-3"
                                        />
                                        <SortableTableHead
                                            label="Invoice"
                                            column="invoice_no"
                                            activeColumn={sortColumn}
                                            direction={sortDirection}
                                            onSort={(column) =>
                                                handleSort(column as RowSortColumn)
                                            }
                                            className="px-4 py-3"
                                        />
                                        <SortableTableHead
                                            label="Branch amount"
                                            column="branch_amount"
                                            activeColumn={sortColumn}
                                            direction={sortDirection}
                                            onSort={(column) =>
                                                handleSort(column as RowSortColumn)
                                            }
                                            align="right"
                                            className="px-4 py-3"
                                        />
                                        <SortableTableHead
                                            label="Received"
                                            column="received_amount"
                                            activeColumn={sortColumn}
                                            direction={sortDirection}
                                            onSort={(column) =>
                                                handleSort(column as RowSortColumn)
                                            }
                                            align="right"
                                            className="px-4 py-3"
                                        />
                                        <SortableTableHead
                                            label="Annexure"
                                            column="annexure_amount"
                                            activeColumn={sortColumn}
                                            direction={sortDirection}
                                            onSort={(column) =>
                                                handleSort(column as RowSortColumn)
                                            }
                                            align="right"
                                            className="px-4 py-3"
                                        />
                                        <SortableTableHead
                                            label="Cheque"
                                            column="cheque_number"
                                            activeColumn={sortColumn}
                                            direction={sortDirection}
                                            onSort={(column) =>
                                                handleSort(column as RowSortColumn)
                                            }
                                            className="px-4 py-3"
                                        />
                                        <AppTableHeadCell className="px-4 py-3 font-medium">
                                            Cheque month
                                        </AppTableHeadCell>
                                        <SortableTableHead
                                            label="Status"
                                            column="status"
                                            activeColumn={sortColumn}
                                            direction={sortDirection}
                                            onSort={(column) =>
                                                handleSort(column as RowSortColumn)
                                            }
                                            className="px-4 py-3"
                                        />
                                    </tr>
                                </thead>
                                <tbody>
                                    {displayedRows.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={11}
                                                className="px-4 py-10 text-center text-muted-foreground"
                                            >
                                                No transactions match your
                                                filters.
                                            </td>
                                        </tr>
                                    ) : (
                                        displayedRows.map((row, index) => (
                                            <tr
                                                key={row.key}
                                                className={cn(
                                                    'border-b transition-colors hover:bg-muted/30',
                                                    row.status ===
                                                        'mismatch' &&
                                                        'bg-destructive/5',
                                                    row.status ===
                                                        'incomplete' &&
                                                        'bg-amber-500/5',
                                                    (row.status ===
                                                        'matched' ||
                                                        row.status ===
                                                            'complete') &&
                                                        'bg-emerald-500/5',
                                                    row.invoice_date_differs_from_period &&
                                                        'ring-1 ring-inset ring-blue-500/20',
                                                )}
                                            >
                                                <AppTableBodyCell
                                                    label="Sl"
                                                    mobile="skip"
                                                    className="px-4 py-2.5 text-muted-foreground"
                                                >
                                                    {index + 1}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Invoice month"
                                                    className="px-4 py-2.5 whitespace-nowrap"
                                                >
                                                    {row.statement_period}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Invoice date"
                                                    className={cn(
                                                        'px-4 py-2.5 whitespace-nowrap',
                                                        row.invoice_date_differs_from_period &&
                                                            'font-medium text-blue-700 dark:text-blue-300',
                                                    )}
                                                >
                                                    {row.invoice_date ?? '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Branch"
                                                    className="px-4 py-2.5 whitespace-nowrap"
                                                >
                                                    {row.branch_code ?? '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Invoice"
                                                    mobile="primary"
                                                    className="px-4 py-2.5"
                                                >
                                                    <InvoiceNoLink
                                                        clientId={client.id}
                                                        invoiceNo={row.invoice_no}
                                                    />
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Branch amount"
                                                    className="px-4 py-2.5 text-right tabular-nums"
                                                >
                                                    {row.branch_amount ?? '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Received"
                                                    className="px-4 py-2.5 text-right tabular-nums"
                                                >
                                                    {row.received_amount ?? '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Annexure"
                                                    className="px-4 py-2.5 text-right tabular-nums"
                                                >
                                                    {row.annexure_amount ?? '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Cheque"
                                                    className="px-4 py-2.5 whitespace-nowrap"
                                                >
                                                    {row.cheque_number ?? '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Cheque month"
                                                    className="px-4 py-2.5 whitespace-nowrap"
                                                >
                                                    {row.cheque_period ?? '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Status"
                                                    className="px-4 py-2.5"
                                                >
                                                    <Badge
                                                        variant={statusVariant(
                                                            row.status,
                                                        )}
                                                    >
                                                        {statusLabel(
                                                            row.status,
                                                        )}
                                                    </Badge>
                                                </AppTableBodyCell>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                                {displayedRows.length > 0 && (
                                    <tfoot>
                                        <tr className="border-t bg-muted/40 font-medium">
                                            <td
                                                colSpan={5}
                                                className="px-4 py-3"
                                            >
                                                {hasActiveFilters
                                                    ? 'Filtered totals'
                                                    : 'Totals'}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {footerBranchTotal}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {footerReceivedTotal}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {footerAnnexureTotal}
                                            </td>
                                            <td colSpan={3} />
                                        </tr>
                                    </tfoot>
                                )}
                            </AppTable>
                        </AppTableScroll>
                    </CardContent>
                </Card>
            </div>

            <BackToTopButton />
        </>
    );
}
