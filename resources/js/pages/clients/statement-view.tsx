import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Download,
    FileSpreadsheet,
    Search,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useSearchFromUrl } from '@/hooks/use-search-from-url';
import { useFilterFromUrl } from '@/hooks/use-reconciliation-filters-from-url';
import StatementEntryController from '@/actions/App/Http/Controllers/StatementEntryController';
import Heading from '@/components/heading';
import { StatsSummaryLine } from '@/components/stats-summary-line';
import { BackToTopButton } from '@/components/back-to-top-button';
import { InvoiceNoLink } from '@/components/invoice-no-link';
import { ReconciliationLegend } from '@/components/reconciliation-legend';
import {
    SIMPLE_RECONCILIATION_LEGEND_STATUSES,
    reconciliationDiffTextClassName,
    reconciliationInvoiceTextClassName,
    reconciliationRowClassName,
    resolveReconciliationRowStatus,
} from '@/lib/reconciliation-row-status';
import {
    AppTable,
    AppTableBodyCell,
    AppTableHeadCell,
    AppTableScroll,
} from '@/components/app-table';
import InputError from '@/components/input-error';
import { PeriodFilterMenu } from '@/components/statement-filter-menus';
import { SortableTableHead } from '@/components/sortable-table-head';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    getDuplicateInvoiceKeys,
    isDuplicateInvoice,
} from '@/lib/duplicate-invoices';
import { buildStatementFilterQuery } from '@/lib/statement-filters';
import { mergeReconciliationQuery } from '@/lib/reconciliation-url';
import {
    compareNumbers,
    compareStrings,
    parseDdMmYyyy,
    toggleSortDirection,
    type SortDirection,
} from '@/lib/table-sort';
import { cn } from '@/lib/utils';
import { generateStatement, show } from '@/routes/clients';
import { show as statementShow } from '@/routes/clients/statement';
import {
    excel as clientExcel,
    pdf as clientPdf,
} from '@/routes/clients/generate-statement/export';
import type {
    BranchOption,
    Client,
    CombinedStatementEntry,
    StatementMonth,
} from '@/types';

type Props = {
    client: Pick<Client, 'id' | 'name'>;
    branches: BranchOption[];
    branchIds: number[];
    entries: CombinedStatementEntry[];
    total: string;
    year: number;
    month: number;
    selectedPeriods: StatementMonth[];
    periodLabel: string;
    availableMonths: StatementMonth[];
    previousPeriod: string;
    nextPeriod: string;
};

type EntrySortColumn =
    | 'branch_code'
    | 'transaction_date'
    | 'invoice_no'
    | 'amount'
    | 'client_amount'
    | 'difference_amount';

type EntryFilter =
    | 'all'
    | 'resolved'
    | 'unresolved'
    | 'mismatches'
    | 'duplicates'
    | 'unique';

const ENTRY_FILTER_VALUES = [
    'all',
    'resolved',
    'unresolved',
    'mismatches',
    'duplicates',
    'unique',
] as const satisfies readonly EntryFilter[];

function formatAmount(value: number | string): string {
    return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
}

export default function ClientsStatementView({
    client,
    branches,
    branchIds,
    entries,
    total,
    year,
    month,
    selectedPeriods,
    periodLabel,
    availableMonths,
    previousPeriod,
    nextPeriod,
}: Props) {
    const pageUrl = usePage().url;
    const [sortColumn, setSortColumn] =
        useState<EntrySortColumn>('branch_code');
    const [sortDirection, setSortDirection] =
        useState<SortDirection>('asc');
    const [search, setSearch] = useSearchFromUrl();
    const [filter, setFilter] = useFilterFromUrl(ENTRY_FILTER_VALUES);
    const [editingEntry, setEditingEntry] =
        useState<CombinedStatementEntry | null>(null);

    const [prevYear, prevMonth] = previousPeriod.split('-').map(Number);
    const [nextYear, nextMonth] = nextPeriod.split('-').map(Number);
    const multiplePeriods = selectedPeriods.length > 1;

    const query = buildStatementFilterQuery(branchIds, selectedPeriods);

    const excelUrl = clientExcel.url(client.id, { query });
    const pdfUrl = clientPdf.url(client.id, { query });

    function handleSort(column: EntrySortColumn) {
        setSortDirection((currentDirection) =>
            toggleSortDirection(sortColumn, column, currentDirection),
        );
        setSortColumn(column);
    }

    const duplicateInvoiceKeys = useMemo(
        () => getDuplicateInvoiceKeys(entries, 'branch-invoice'),
        [entries],
    );

    const hasDuplicateInvoices = duplicateInvoiceKeys.size > 0;

    const unresolvedCount = useMemo(
        () => entries.filter((entry) => !entry.is_resolved).length,
        [entries],
    );

    const mismatchCount = useMemo(
        () => entries.filter((entry) => entry.has_difference).length,
        [entries],
    );

    const hasActiveFilters = search.trim() !== '' || filter !== 'all';

    const filteredEntries = useMemo(() => {
        const query = search.trim().toLowerCase();

        return entries.filter((entry) => {
            const isDuplicate = isDuplicateInvoice(
                entry,
                duplicateInvoiceKeys,
                'branch-invoice',
            );

            const matchesSearch =
                query === '' ||
                entry.branch_code.toLowerCase().includes(query) ||
                entry.branch_name.toLowerCase().includes(query) ||
                entry.transaction_date.includes(query) ||
                entry.invoice_no.toLowerCase().includes(query) ||
                entry.amount.includes(query) ||
                (entry.client_amount?.includes(query) ?? false) ||
                (entry.difference_amount?.includes(query) ?? false);

            const matchesFilter =
                filter === 'all' ||
                (filter === 'resolved' && entry.is_resolved) ||
                (filter === 'unresolved' && !entry.is_resolved) ||
                (filter === 'mismatches' && entry.has_difference) ||
                (filter === 'duplicates' && isDuplicate) ||
                (filter === 'unique' && !isDuplicate);

            return matchesSearch && matchesFilter;
        });
    }, [duplicateInvoiceKeys, entries, filter, search]);

    const displayedEntries = useMemo(() => {
        return [...filteredEntries].sort((left, right) => {
            switch (sortColumn) {
                case 'branch_code':
                    return compareStrings(
                        left.branch_code,
                        right.branch_code,
                        sortDirection,
                    );
                case 'transaction_date':
                    return compareNumbers(
                        parseDdMmYyyy(left.transaction_date),
                        parseDdMmYyyy(right.transaction_date),
                        sortDirection,
                    );
                case 'invoice_no':
                    return compareStrings(
                        left.invoice_no,
                        right.invoice_no,
                        sortDirection,
                    );
                case 'amount':
                    return compareNumbers(
                        Number(left.amount),
                        Number(right.amount),
                        sortDirection,
                    );
                case 'client_amount':
                    return compareNumbers(
                        left.client_amount_value ?? -1,
                        right.client_amount_value ?? -1,
                        sortDirection,
                    );
                case 'difference_amount':
                    return compareNumbers(
                        left.difference_amount_value ?? -1,
                        right.difference_amount_value ?? -1,
                        sortDirection,
                    );
                default:
                    return 0;
            }
        });
    }, [filteredEntries, sortColumn, sortDirection]);

    const filteredTotal = useMemo(
        () =>
            filteredEntries.reduce(
                (sum, entry) => sum + (entry.amount_value ?? Number(entry.amount)),
                0,
            ),
        [filteredEntries],
    );

    const clientAmountTotal = useMemo(
        () =>
            entries.reduce(
                (sum, entry) => sum + (entry.client_amount_value ?? 0),
                0,
            ),
        [entries],
    );

    const differenceTotal = useMemo(
        () =>
            entries.reduce(
                (sum, entry) => sum + (entry.difference_amount_value ?? 0),
                0,
            ),
        [entries],
    );

    const filteredClientAmountTotal = useMemo(
        () =>
            filteredEntries.reduce(
                (sum, entry) => sum + (entry.client_amount_value ?? 0),
                0,
            ),
        [filteredEntries],
    );

    const filteredDifferenceTotal = useMemo(
        () =>
            filteredEntries.reduce(
                (sum, entry) => sum + (entry.difference_amount_value ?? 0),
                0,
            ),
        [filteredEntries],
    );

    const displayTotal = formatAmount(
        hasActiveFilters ? filteredTotal : Number(total),
    );
    const displayClientAmountTotal = formatAmount(
        hasActiveFilters ? filteredClientAmountTotal : clientAmountTotal,
    );
    const displayDifferenceTotal = formatAmount(
        hasActiveFilters ? filteredDifferenceTotal : differenceTotal,
    );

    const footerLabelColSpan = multiplePeriods ? 5 : 4;

    function resolvePeriod(
        yearValue: number,
        monthValue: number,
    ): StatementMonth {
        return (
            availableMonths.find(
                (item) =>
                    item.year === yearValue && item.month === monthValue,
            ) ?? {
                year: yearValue,
                month: monthValue,
                label: `${String(monthValue).padStart(2, '0')}/${yearValue}`,
            }
        );
    }

    function applyPeriods(nextPeriods: StatementMonth[]) {
        router.get(
            statementShow.url(client.id, {
                query: {
                    ...mergeReconciliationQuery(pageUrl),
                    ...buildStatementFilterQuery(branchIds, nextPeriods),
                },
            }),
            {},
            { preserveState: false, preserveScroll: true },
        );
    }

    function changePeriod(nextYearValue: number, nextMonthValue: number) {
        applyPeriods([resolvePeriod(nextYearValue, nextMonthValue)]);
    }

    return (
        <>
            <Head title={`${client.name} - ${periodLabel}`} />

            <div className="flex min-w-0 flex-col gap-4 sm:gap-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`${client.name} — Statement`}
                        description={`${branches.length} branch${branches.length === 1 ? '' : 'es'} · ${periodLabel}`}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a href={excelUrl}>
                                <Download className="size-4" />
                                Export Excel
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={pdfUrl}>
                                <FileSpreadsheet className="size-4" />
                                Export PDF
                            </a>
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0 flex-1">
                            <CardTitle>{periodLabel}</CardTitle>
                            <StatsSummaryLine
                                items={[
                                    hasActiveFilters
                                        ? `${filteredEntries.length} of ${entries.length} entries`
                                        : `${entries.length} entries`,
                                    `Branch ${displayTotal}`,
                                    `Client ${displayClientAmountTotal}`,
                                    `Diff ${displayDifferenceTotal}`,
                                    unresolvedCount > 0
                                        ? `${unresolvedCount} without received match`
                                        : null,
                                    mismatchCount > 0
                                        ? `${mismatchCount} amount mismatch${mismatchCount === 1 ? '' : 'es'}`
                                        : null,
                                    hasDuplicateInvoices
                                        ? 'Duplicate invoices highlighted'
                                        : null,
                                ]}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {!multiplePeriods && (
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={() =>
                                        changePeriod(prevYear, prevMonth)
                                    }
                                >
                                    <ChevronLeft className="size-4" />
                                </Button>
                            )}
                            <PeriodFilterMenu
                                availableMonths={availableMonths}
                                selectedPeriods={selectedPeriods}
                                onChange={applyPeriods}
                            />
                            {!multiplePeriods && (
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={() =>
                                        changePeriod(nextYear, nextMonth)
                                    }
                                >
                                    <ChevronRight className="size-4" />
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {entries.length === 0 ? (
                            <div className="rounded-lg border border-dashed py-12 text-center">
                                <p className="font-medium">
                                    No statement entries for this month
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Upload entries for the selected branches or
                                    choose another month.
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                    <div className="relative w-full lg:max-w-sm">
                                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            value={search}
                                            onChange={(event) =>
                                                setSearch(event.target.value)
                                            }
                                            placeholder="Search branch, date, invoice, amount, difference..."
                                            className="pl-9"
                                        />
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {(
                                            [
                                                ['all', 'All'],
                                                ['resolved', 'Resolved'],
                                                ['unresolved', 'Unresolved'],
                                                ['mismatches', 'Mismatches'],
                                                ['duplicates', 'Duplicates'],
                                                ['unique', 'Unique'],
                                            ] as const
                                        ).map(([value, label]) => (
                                            <Button
                                                key={value}
                                                type="button"
                                                size="sm"
                                                variant={
                                                    filter === value
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                onClick={() => setFilter(value)}
                                            >
                                                {label}
                                            </Button>
                                        ))}
                                        {hasActiveFilters && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    setSearch('');
                                                    setFilter('all');
                                                }}
                                            >
                                                Clear
                                            </Button>
                                        )}
                                    </div>
                                </div>

                                <ReconciliationLegend
                                    compact
                                    statuses={
                                        SIMPLE_RECONCILIATION_LEGEND_STATUSES
                                    }
                                />

                                {displayedEntries.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No entries match your search or filters.
                                    </p>
                                ) : (
                            <AppTableScroll className="rounded-lg border">
                                <AppTable>
                                    <thead>
                                        <tr>
                                            <AppTableHeadCell className="px-4 py-3 text-left font-medium">
                                                Sl
                                            </AppTableHeadCell>
                                            <SortableTableHead
                                                label="Branch ID"
                                                column="branch_code"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as EntrySortColumn,
                                                    )
                                                }
                                            />
                                            {multiplePeriods && (
                                                <AppTableHeadCell className="px-4 py-3 text-left font-medium">
                                                    Month
                                                </AppTableHeadCell>
                                            )}
                                            <SortableTableHead
                                                label="Date"
                                                column="transaction_date"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as EntrySortColumn,
                                                    )
                                                }
                                            />
                                            <SortableTableHead
                                                label="Invoice No"
                                                column="invoice_no"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as EntrySortColumn,
                                                    )
                                                }
                                            />
                                            <SortableTableHead
                                                label="Amount"
                                                column="amount"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as EntrySortColumn,
                                                    )
                                                }
                                                align="right"
                                            />
                                            <SortableTableHead
                                                label="Client Amount"
                                                column="client_amount"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as EntrySortColumn,
                                                    )
                                                }
                                                align="right"
                                            />
                                            <SortableTableHead
                                                label="Difference"
                                                column="difference_amount"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as EntrySortColumn,
                                                    )
                                                }
                                                align="right"
                                            />
                                            <AppTableHeadCell className="px-4 py-3 text-right font-medium">
                                                Actions
                                            </AppTableHeadCell>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {displayedEntries.map((entry, index) => {
                                            const isDuplicate =
                                                isDuplicateInvoice(
                                                    entry,
                                                    duplicateInvoiceKeys,
                                                    'branch-invoice',
                                                );
                                            const differenceValue =
                                                entry.difference_amount_value ??
                                                null;
                                            const rowStatus =
                                                resolveReconciliationRowStatus({
                                                    isDuplicate,
                                                    isResolved:
                                                        entry.is_resolved,
                                                    differenceValue,
                                                });

                                            return (
                                            <tr
                                                key={entry.id}
                                                className={cn(
                                                    'border-t',
                                                    reconciliationRowClassName(
                                                        rowStatus,
                                                    ),
                                                )}
                                            >
                                                <AppTableBodyCell
                                                    label="Sl"
                                                    mobile="skip"
                                                    className="px-4 py-3 text-muted-foreground"
                                                >
                                                    {index + 1}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Branch ID"
                                                    className="px-4 py-3 font-mono"
                                                >
                                                    {entry.branch_code}
                                                </AppTableBodyCell>
                                                {multiplePeriods && (
                                                    <AppTableBodyCell
                                                        label="Month"
                                                        className="px-4 py-3 text-muted-foreground whitespace-nowrap"
                                                    >
                                                        {entry.statement_period ??
                                                            '—'}
                                                    </AppTableBodyCell>
                                                )}
                                                <AppTableBodyCell
                                                    label="Date"
                                                    className="px-4 py-3"
                                                >
                                                    {entry.transaction_date}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Invoice No"
                                                    mobile="primary"
                                                    className={cn(
                                                        'px-4 py-3 font-mono',
                                                        reconciliationInvoiceTextClassName(
                                                            rowStatus,
                                                            isDuplicate,
                                                        ),
                                                    )}
                                                >
                                                    <InvoiceNoLink
                                                        clientId={client.id}
                                                        invoiceNo={
                                                            entry.invoice_no
                                                        }
                                                    />
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Amount"
                                                    className="px-4 py-3 text-right"
                                                >
                                                    {formatAmount(entry.amount)}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Client Amount"
                                                    className="px-4 py-3 text-right text-muted-foreground"
                                                >
                                                    {entry.client_amount
                                                        ? formatAmount(
                                                              entry.client_amount,
                                                          )
                                                        : '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Difference"
                                                    className={cn(
                                                        'px-4 py-3 text-right',
                                                        reconciliationDiffTextClassName(
                                                            differenceValue,
                                                            rowStatus,
                                                        ),
                                                    )}
                                                >
                                                    {entry.difference_amount
                                                        ? formatAmount(
                                                              entry.difference_amount,
                                                          )
                                                        : '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Actions"
                                                    mobile="actions"
                                                    className="px-4 py-3"
                                                >
                                                    <div className="flex flex-nowrap justify-end gap-2">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setEditingEntry(
                                                                    entry,
                                                                )
                                                            }
                                                        >
                                                            Edit
                                                        </Button>
                                                        <Dialog>
                                                            <DialogTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                >
                                                                    Delete
                                                                </Button>
                                                            </DialogTrigger>
                                                            <DialogContent>
                                                                <DialogTitle>
                                                                    Delete entry?
                                                                </DialogTitle>
                                                                <DialogDescription>
                                                                    This will
                                                                    permanently
                                                                    remove
                                                                    invoice{' '}
                                                                    {
                                                                        entry.invoice_no
                                                                    }{' '}
                                                                    from branch{' '}
                                                                    {
                                                                        entry.branch_code
                                                                    }.
                                                                </DialogDescription>
                                                                <Form
                                                                    {...StatementEntryController.destroy.form(
                                                                        entry.id,
                                                                    )}
                                                                    options={{
                                                                        preserveScroll:
                                                                            true,
                                                                    }}
                                                                >
                                                                    {({
                                                                        processing,
                                                                    }) => (
                                                                        <>
                                                                            <input
                                                                                type="hidden"
                                                                                name="year"
                                                                                value={
                                                                                    year
                                                                                }
                                                                            />
                                                                            <input
                                                                                type="hidden"
                                                                                name="month"
                                                                                value={
                                                                                    month
                                                                                }
                                                                            />
                                                                            <input
                                                                                type="hidden"
                                                                                name="client_id"
                                                                                value={
                                                                                    client.id
                                                                                }
                                                                            />
                                                                            {branchIds.map(
                                                                                (
                                                                                    branchId,
                                                                                ) => (
                                                                                    <input
                                                                                        key={
                                                                                            branchId
                                                                                        }
                                                                                        type="hidden"
                                                                                        name="branch_ids[]"
                                                                                        value={
                                                                                            branchId
                                                                                        }
                                                                                    />
                                                                                ),
                                                                            )}
                                                                            <DialogFooter className="gap-2">
                                                                                <DialogClose
                                                                                    asChild
                                                                                >
                                                                                    <Button variant="secondary">
                                                                                        Cancel
                                                                                    </Button>
                                                                                </DialogClose>
                                                                                <Button
                                                                                    variant="destructive"
                                                                                    disabled={
                                                                                        processing
                                                                                    }
                                                                                    asChild
                                                                                >
                                                                                    <button type="submit">
                                                                                        Delete
                                                                                    </button>
                                                                                </Button>
                                                                            </DialogFooter>
                                                                        </>
                                                                    )}
                                                                </Form>
                                                            </DialogContent>
                                                        </Dialog>
                                                    </div>
                                                </AppTableBodyCell>
                                            </tr>
                                            );
                                        })}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t bg-muted/30 font-semibold">
                                            <td
                                                className="px-4 py-3"
                                                colSpan={footerLabelColSpan}
                                            >
                                                {hasActiveFilters
                                                    ? 'Filtered total'
                                                    : 'Total'}
                                            </td>
                                            <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                {displayTotal}
                                            </td>
                                            <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                {displayClientAmountTotal}
                                            </td>
                                            <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                {displayDifferenceTotal}
                                            </td>
                                            <td className="px-4 py-3" />
                                        </tr>
                                    </tfoot>
                                </AppTable>
                            </AppTableScroll>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>

                <Button variant="link" className="w-fit px-0" asChild>
                    <Link href={generateStatement(client.id)}>
                        Back to Generate Statement
                    </Link>
                </Button>
            </div>

            <BackToTopButton />

            <Dialog
                open={editingEntry !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditingEntry(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogTitle>Edit statement entry</DialogTitle>
                    {editingEntry && (
                        <Form
                            {...StatementEntryController.update.form(
                                editingEntry.id,
                            )}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                            onSuccess={() => setEditingEntry(null)}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="year"
                                        value={year}
                                    />
                                    <input
                                        type="hidden"
                                        name="month"
                                        value={month}
                                    />
                                    <input
                                        type="hidden"
                                        name="client_id"
                                        value={client.id}
                                    />
                                    {branchIds.map((branchId) => (
                                        <input
                                            key={branchId}
                                            type="hidden"
                                            name="branch_ids[]"
                                            value={branchId}
                                        />
                                    ))}
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-branch-code">
                                            Branch ID
                                        </Label>
                                        <Input
                                            id="edit-branch-code"
                                            value={editingEntry.branch_code}
                                            disabled
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-transaction-date">
                                            Date
                                        </Label>
                                        <Input
                                            id="edit-transaction-date"
                                            name="transaction_date"
                                            defaultValue={
                                                editingEntry.transaction_date
                                            }
                                            placeholder="dd/mm/yyyy"
                                            required
                                        />
                                        <InputError
                                            message={errors.transaction_date}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-invoice-no">
                                            Invoice No
                                        </Label>
                                        <Input
                                            id="edit-invoice-no"
                                            name="invoice_no"
                                            defaultValue={
                                                editingEntry.invoice_no
                                            }
                                            required
                                        />
                                        <InputError
                                            message={errors.invoice_no}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-amount">
                                            Amount
                                        </Label>
                                        <Input
                                            id="edit-amount"
                                            name="amount"
                                            type="number"
                                            step="0.001"
                                            defaultValue={editingEntry.amount}
                                            required
                                        />
                                        <InputError message={errors.amount} />
                                    </div>
                                    <DialogFooter className="gap-2">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() =>
                                                setEditingEntry(null)
                                            }
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save entry
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

ClientsStatementView.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Clients', href: '#' },
        { title: props.client.name, href: show(props.client.id) },
        { title: 'Statement', href: '#' },
    ],
});
