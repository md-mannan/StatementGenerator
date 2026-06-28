import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Download,
    FileSpreadsheet,
    RefreshCw,
    Search,
    Table,
    Trash2,
    Upload,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useSearchFromUrl } from '@/hooks/use-search-from-url';
import { useFilterFromUrl } from '@/hooks/use-reconciliation-filters-from-url';
import IncomingStatementEntryController from '@/actions/App/Http/Controllers/IncomingStatementEntryController';
import { BackToTopButton } from '@/components/back-to-top-button';
import { InvoiceNoLink } from '@/components/invoice-no-link';
import { ReconciliationLegend } from '@/components/reconciliation-legend';
import {
    COMPARISON_LEGEND_STATUSES,
    reconciliationDiffTextClassName,
    reconciliationInvoiceTextClassName,
    reconciliationRowClassName,
    resolveReconciliationRowStatus,
} from '@/lib/reconciliation-row-status';
import { toolbarScrollClassName } from '@/lib/page-layout';
import {
    AppTable,
    AppTableBodyCell,
    AppTableHeadCell,
    AppTableScroll,
} from '@/components/app-table';
import InputError from '@/components/input-error';
import { PeriodFilterMenu, BranchFilterMenu } from '@/components/statement-filter-menus';
import { StatementEntryGrid } from '@/components/statement-entry-grid';
import { SortableTableHead } from '@/components/sortable-table-head';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    compareNumbers,
    compareStrings,
    parseDdMmYyyy,
    toggleSortDirection,
    type SortDirection,
} from '@/lib/table-sort';
import { cn } from '@/lib/utils';
import { periodKey, sortBranchesByCode } from '@/lib/statement-filters';
import { readReconciliationQuery } from '@/lib/reconciliation-url';
import { index as receivedStatementsIndex } from '@/routes/clients/received-statements';
import { importMethod as receivedStatementsImport } from '@/routes/clients/received-statements';
import { noBranchExpected } from '@/routes/incoming-statement-entries';
import {
    excel,
    pdf,
} from '@/routes/clients/received-statements/export';
import type {
    BranchOption,
    Client,
    ClientSummary,
    IncomingStatementEntry,
    StatementMonth,
} from '@/types';
import {
    createEmptySpreadsheetRows,
    isSpreadsheetRowComplete,
    validateCompleteSpreadsheetRows,
    type SpreadsheetRow,
} from '@/lib/spreadsheet-paste';

type Props = {
    client: Pick<Client, 'id' | 'name'>;
    summary: ClientSummary;
    entries: IncomingStatementEntry[];
    branches: BranchOption[];
    total: string;
    branchStatementTotal: string;
    totalDifference: string;
    unresolvedCount: number;
    mismatchCount: number;
    year: number;
    month: number;
    selectedPeriods: StatementMonth[];
    periodLabel: string;
    availableMonths: StatementMonth[];
    previousPeriod: string;
    nextPeriod: string;
};

type EntrySortColumn =
    | 'transaction_date'
    | 'branch_code'
    | 'invoice_no'
    | 'amount'
    | 'branch_amount'
    | 'difference_amount';

type EntryFilter =
    | 'all'
    | 'unresolved'
    | 'resolved'
    | 'supplier'
    | 'duplicates'
    | 'unique'
    | 'mismatches';

const ENTRY_FILTER_VALUES = [
    'all',
    'unresolved',
    'resolved',
    'supplier',
    'duplicates',
    'unique',
    'mismatches',
] as const satisfies readonly EntryFilter[];

function formatAmount(value: number | string): string {
    return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
}

function formatBranchLabel(
    code: string | null | undefined,
    name: string | null | undefined,
): string {
    if (code && name) {
        return `${code} — ${name}`;
    }

    return code ?? name ?? '—';
}

function mapGridRowErrors(
    errors: Record<string, string | undefined>,
): Record<number, Partial<Record<keyof SpreadsheetRow, string>>> {
    const mapped: Record<
        number,
        Partial<Record<keyof SpreadsheetRow, string>>
    > = {};

    for (const [key, message] of Object.entries(errors)) {
        if (!message) {
            continue;
        }

        const match = key.match(/^entries\.(\d+)\.(\w+)$/);

        if (!match) {
            continue;
        }

        const index = Number(match[1]);
        const field = match[2] as keyof SpreadsheetRow;

        mapped[index] ??= {};
        mapped[index][field] = message;
    }

    return mapped;
}

export default function ClientsReceivedStatementsIndex({
    client,
    entries,
    branches,
    total,
    branchStatementTotal,
    totalDifference,
    unresolvedCount: _unresolvedCount,
    mismatchCount,
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
        useState<EntrySortColumn>('transaction_date');
    const [sortDirection, setSortDirection] =
        useState<SortDirection>('asc');
    const [search, setSearch] = useSearchFromUrl();
    const [filter, setFilter] = useFilterFromUrl(ENTRY_FILTER_VALUES);
    const [selectedBranchFilterIds, setSelectedBranchFilterIds] = useState<
        number[]
    >([]);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [editingEntry, setEditingEntry] =
        useState<IncomingStatementEntry | null>(null);
    const [addingMultipleEntries, setAddingMultipleEntries] = useState(false);
    const [gridRows, setGridRows] = useState<SpreadsheetRow[]>(() =>
        createEmptySpreadsheetRows(8),
    );
    function openMultipleEntryDialog() {
        setGridRows(createEmptySpreadsheetRows(8));
        setAddingMultipleEntries(true);
    }

    const bulkCompleteRows = useMemo(
        () => gridRows.filter(isSpreadsheetRowComplete),
        [gridRows],
    );

    const bulkRowValidation = useMemo(
        () => validateCompleteSpreadsheetRows(gridRows),
        [gridRows],
    );

    const bulkSubmitPeriod = { year, month };

    const bulkHasDateIssues =
        bulkCompleteRows.length > 0 &&
        bulkRowValidation.invalidRowNumbers.length > 0;

    const [prevYear, prevMonth] = previousPeriod.split('-').map(Number);
    const [nextYear, nextMonth] = nextPeriod.split('-').map(Number);
    const multiplePeriods = selectedPeriods.length > 1;

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
            receivedStatementsIndex.url(client.id, {
                query: {
                    periods: nextPeriods.map(periodKey),
                },
            }),
            {},
            { preserveState: false, preserveScroll: true },
        );
    }

    function changePeriod(nextYearValue: number, nextMonthValue: number) {
        applyPeriods([resolvePeriod(nextYearValue, nextMonthValue)]);
    }

    function handleSort(column: EntrySortColumn) {
        setSortDirection((currentDirection) =>
            toggleSortDirection(sortColumn, column, currentDirection),
        );
        setSortColumn(column);
    }

    function toggleNoBranchExpected(
        entry: IncomingStatementEntry,
        expected: boolean,
    ) {
        router.patch(
            noBranchExpected.url(entry.id),
            { no_branch_expected: expected },
            { preserveScroll: true },
        );
    }

    function clearAllFilters() {
        setSearch('');
        setFilter('all');
        setSelectedBranchFilterIds([]);
        setSelectedIds([]);

        router.get(
            receivedStatementsIndex.url(client.id),
            {},
            { preserveState: false, preserveScroll: true },
        );
    }

    function refreshPage() {
        router.reload({ preserveScroll: true });
    }

    const duplicateInvoiceKeys = useMemo(
        () => getDuplicateInvoiceKeys(entries, 'invoice'),
        [entries],
    );

    const hasDuplicateInvoices = duplicateInvoiceKeys.size > 0;

    const branchFilterOptions = useMemo(
        () => {
            const codesInEntries = new Set(
                entries
                    .map((entry) => entry.branch_code)
                    .filter((code): code is string => Boolean(code)),
            );

            return sortBranchesByCode(
                branches.filter((branch) => codesInEntries.has(branch.code)),
            );
        },
        [branches, entries],
    );

    const entryBranchIds = useMemo(
        () => branchFilterOptions.map((branch) => branch.id),
        [branchFilterOptions],
    );

    const branchFilterActive =
        selectedBranchFilterIds.length > 0 &&
        selectedBranchFilterIds.length < entryBranchIds.length;

    const selectedBranchCodes = useMemo(
        () =>
            new Set(
                branchFilterOptions
                    .filter((branch) =>
                        selectedBranchFilterIds.includes(branch.id),
                    )
                    .map((branch) => branch.code),
            ),
        [branchFilterOptions, selectedBranchFilterIds],
    );

    const hasActiveFilters =
        search.trim() !== '' ||
        filter !== 'all' ||
        branchFilterActive;

    const urlQuery = readReconciliationQuery(pageUrl);
    const hasClearableFilters =
        hasActiveFilters ||
        multiplePeriods ||
        (urlQuery.periods?.length ?? 0) > 0 ||
        pageUrl.includes('month=') ||
        pageUrl.includes('year=');

    const displayedUnresolvedCount = useMemo(
        () =>
            entries.filter(
                (entry) =>
                    !entry.is_resolved &&
                    !(entry.no_branch_expected ?? false),
            ).length,
        [entries],
    );

    const filteredEntries = useMemo(() => {
        const query = search.trim().toLowerCase();
        const activeBranchIds = branchFilterActive
            ? selectedBranchFilterIds
            : null;
        const activeBranchCodes = branchFilterActive
            ? selectedBranchCodes
            : null;

        return entries.filter((entry) => {
            const isDuplicate = isDuplicateInvoice(
                entry,
                duplicateInvoiceKeys,
                'invoice',
            );

            const matchesSearch =
                query === '' ||
                entry.invoice_no.toLowerCase().includes(query) ||
                (entry.branch_name?.toLowerCase().includes(query) ?? false) ||
                (entry.branch_code?.toLowerCase().includes(query) ?? false) ||
                entry.transaction_date.includes(query) ||
                entry.amount.includes(query) ||
                (entry.branch_amount?.includes(query) ?? false) ||
                (entry.difference_amount?.includes(query) ?? false);

            const matchesFilter =
                filter === 'all' ||
                (filter === 'unresolved' &&
                    !entry.is_resolved &&
                    !(entry.no_branch_expected ?? false)) ||
                (filter === 'resolved' && entry.is_resolved) ||
                (filter === 'supplier' &&
                    (entry.no_branch_expected ?? false)) ||
                (filter === 'duplicates' && isDuplicate) ||
                (filter === 'unique' && !isDuplicate) ||
                (filter === 'mismatches' && entry.has_difference);

            const matchesBranch =
                activeBranchIds === null ||
                (entry.branch_id !== null &&
                    activeBranchIds.includes(entry.branch_id)) ||
                (entry.branch_code !== null &&
                    (activeBranchCodes?.has(entry.branch_code) ?? false));

            return matchesSearch && matchesFilter && matchesBranch;
        });
    }, [
        branchFilterActive,
        duplicateInvoiceKeys,
        entries,
        filter,
        search,
        selectedBranchCodes,
        selectedBranchFilterIds,
    ]);

    const displayedEntries = useMemo(() => {
        return [...filteredEntries].sort((left, right) => {
            switch (sortColumn) {
                case 'transaction_date':
                    return compareNumbers(
                        parseDdMmYyyy(left.transaction_date),
                        parseDdMmYyyy(right.transaction_date),
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
                case 'amount':
                    return compareNumbers(
                        entryAmountValue(left),
                        entryAmountValue(right),
                        sortDirection,
                    );
                case 'branch_amount':
                    return compareNumbers(
                        left.branch_amount_value ?? -1,
                        right.branch_amount_value ?? -1,
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

    const exportQuery = useMemo(() => {
        const baseQuery: {
            periods: string[];
            entry_ids?: number[];
        } = {
            periods: selectedPeriods.map(periodKey),
        };

        if (hasActiveFilters) {
            baseQuery.entry_ids = displayedEntries.map((entry) => entry.id);
        }

        return baseQuery;
    }, [displayedEntries, hasActiveFilters, selectedPeriods]);

    const excelUrl = excel.url(client.id, { query: exportQuery });
    const pdfUrl = pdf.url(client.id, { query: exportQuery });

    function entryAmountValue(entry: IncomingStatementEntry): number {
        return entry.amount_value ?? Number(entry.amount);
    }

    const filteredTotal = useMemo(
        () =>
            filteredEntries.reduce(
                (sum, entry) => sum + entryAmountValue(entry),
                0,
            ),
        [filteredEntries],
    );

    const filteredBranchTotal = useMemo(
        () =>
            filteredEntries.reduce(
                (sum, entry) => sum + (entry.branch_amount_value ?? 0),
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

    const displayedEntryIds = useMemo(
        () => displayedEntries.map((entry) => entry.id),
        [displayedEntries],
    );

    const allDisplayedSelected =
        displayedEntryIds.length > 0 &&
        displayedEntryIds.every((id) => selectedIds.includes(id));

    function toggleSelectAll() {
        if (allDisplayedSelected) {
            setSelectedIds((current) =>
                current.filter((id) => !displayedEntryIds.includes(id)),
            );

            return;
        }

        setSelectedIds((current) =>
            Array.from(new Set([...current, ...displayedEntryIds])),
        );
    }

    function toggleEntry(entryId: number, checked: boolean) {
        setSelectedIds((current) => {
            if (checked) {
                return current.includes(entryId)
                    ? current
                    : [...current, entryId];
            }

            return current.filter((id) => id !== entryId);
        });
    }

    return (
        <>
            <Head title={`Received Statement - ${client.name}`} />

            <Card>
                <CardHeader className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="min-w-0 flex-1">
                        <CardTitle>Received statement</CardTitle>
                        <CardDescription>
                            Client-submitted entries for {periodLabel}. Amounts
                            are compared with your branch statements for the same
                            invoice month by branch and invoice number.
                        </CardDescription>
                    </div>
                    <div className={`${toolbarScrollClassName} shrink-0`}>
                        <Button asChild className="whitespace-nowrap">
                            <Link
                                href={receivedStatementsImport.url(client.id, {
                                    query: { year, month },
                                })}
                            >
                                <Upload className="size-4" />
                                Upload
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            className="whitespace-nowrap"
                            onClick={openMultipleEntryDialog}
                        >
                            <Table className="size-4" />
                            Add entries
                        </Button>
                        <Button variant="outline" asChild className="whitespace-nowrap">
                            <a href={excelUrl}>
                                <Download className="size-4" />
                                Export Excel
                            </a>
                        </Button>
                        <Button variant="outline" asChild className="whitespace-nowrap">
                            <a href={pdfUrl}>
                                <FileSpreadsheet className="size-4" />
                                PDF
                            </a>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent className="space-y-6">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="font-medium">{periodLabel}</p>
                            <p className="text-sm text-muted-foreground">
                                {hasActiveFilters
                                    ? `${filteredEntries.length} of ${entries.length} entries`
                                    : `${entries.length} entries`}{' '}
                                · Total{' '}
                                {formatAmount(
                                    hasActiveFilters ? filteredTotal : total,
                                )}
                                {hasActiveFilters &&
                                    ` (filtered from ${formatAmount(total)})`}
                                {displayedUnresolvedCount > 0 &&
                                    ` · ${displayedUnresolvedCount} without branch match`}
                                {mismatchCount > 0 &&
                                    ` · ${mismatchCount} amount mismatch${mismatchCount === 1 ? '' : 'es'}`}
                                {hasDuplicateInvoices &&
                                    ' · Duplicate invoices highlighted'}
                            </p>
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
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={clearAllFilters}
                                disabled={!hasClearableFilters}
                            >
                                <X className="size-4" />
                                Clear
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                onClick={refreshPage}
                                aria-label="Refresh received statement"
                            >
                                <RefreshCw className="size-4" />
                            </Button>
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
                    </div>

                    {entries.length > 0 && (
                        <>
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="relative w-full lg:max-w-sm">
                                <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search date, branch, invoice, amount, difference..."
                                    className="pl-9"
                                />
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {branchFilterOptions.length > 0 && (
                                    <BranchFilterMenu
                                        branches={branchFilterOptions}
                                        branchIds={
                                            selectedBranchFilterIds.length > 0
                                                ? selectedBranchFilterIds
                                                : entryBranchIds
                                        }
                                        onChange={setSelectedBranchFilterIds}
                                    />
                                )}
                                {(
                                    [
                                        ['all', 'All'],
                                        ['resolved', 'Resolved'],
                                        ['unresolved', 'Unresolved'],
                                        ['supplier', 'Supplier'],
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
                            </div>
                            </div>
                            <ReconciliationLegend
                                compact
                                statuses={COMPARISON_LEGEND_STATUSES}
                            />
                        </>
                    )}

                    {entries.length === 0 ? (
                        <div className="rounded-lg border border-dashed py-12 text-center">
                            <p className="font-medium">
                                No received statement for this month
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Upload the client statement with Date, Invoice
                                No, and Amount columns.
                            </p>
                            <Button className="mt-4" asChild>
                                <Link
                                href={receivedStatementsImport.url(client.id, {
                                    query: { year, month },
                                })}
                            >
                                    <Upload className="size-4" />
                                    Upload statement
                                </Link>
                            </Button>
                        </div>
                    ) : displayedEntries.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No entries match your search or filters.
                        </p>
                    ) : (
                        <>
                            {selectedIds.length > 0 && (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 px-4 py-3">
                                    <p className="text-sm font-medium">
                                        {selectedIds.length} selected
                                    </p>
                                    <Dialog>
                                        <DialogTrigger asChild>
                                            <Button
                                                size="sm"
                                                variant="destructive"
                                            >
                                                <Trash2 className="size-4" />
                                                Delete selected
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogTitle>
                                                Delete selected entries?
                                            </DialogTitle>
                                            <DialogDescription>
                                                This will permanently remove{' '}
                                                {selectedIds.length} received
                                                statement{' '}
                                                {selectedIds.length === 1
                                                    ? 'entry'
                                                    : 'entries'}
                                                .
                                            </DialogDescription>
                                            <Form
                                                {...IncomingStatementEntryController.bulkDestroy.form(
                                                    client.id,
                                                )}
                                                options={{ preserveScroll: true }}
                                                onSuccess={() =>
                                                    setSelectedIds([])
                                                }
                                            >
                                                {({ processing }) => (
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
                                                        {selectedIds.map(
                                                            (entryId) => (
                                                                <input
                                                                    key={
                                                                        entryId
                                                                    }
                                                                    type="hidden"
                                                                    name="entry_ids[]"
                                                                    value={
                                                                        entryId
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
                                                                    selected
                                                                </button>
                                                            </Button>
                                                        </DialogFooter>
                                                    </>
                                                )}
                                            </Form>
                                        </DialogContent>
                                    </Dialog>
                                </div>
                            )}

                            <AppTableScroll className="rounded-lg border">
                            <AppTable>
                                <thead>
                                    <tr>
                                        <AppTableHeadCell className="px-4 py-3 text-left">
                                            <Checkbox
                                                checked={allDisplayedSelected}
                                                onCheckedChange={
                                                    toggleSelectAll
                                                }
                                                aria-label="Select all visible entries"
                                            />
                                        </AppTableHeadCell>
                                        <AppTableHeadCell className="px-4 py-3 text-left font-medium">
                                            Sl
                                        </AppTableHeadCell>
                                        {multiplePeriods && (
                                            <AppTableHeadCell className="px-4 py-3 text-left font-medium">
                                                Month
                                            </AppTableHeadCell>
                                        )}
                                        <SortableTableHead
                                            label="Invoice Date"
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
                                            label="Branch"
                                            column="branch_code"
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
                                            label="Branch Amount"
                                            column="branch_amount"
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
                                        const isDuplicate = isDuplicateInvoice(
                                            entry,
                                            duplicateInvoiceKeys,
                                            'invoice',
                                        );
                                        const differenceValue =
                                            entry.difference_amount_value ?? null;
                                        const noBranchExpectedFlag =
                                            entry.no_branch_expected ?? false;
                                        const rowStatus =
                                            resolveReconciliationRowStatus({
                                                isDuplicate,
                                                isResolved: entry.is_resolved,
                                                noBranchExpected:
                                                    noBranchExpectedFlag,
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
                                                    mobile="skip"
                                                    className="px-4 py-3"
                                                >
                                                    <Checkbox
                                                        checked={selectedIds.includes(
                                                            entry.id,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            toggleEntry(
                                                                entry.id,
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                        aria-label={`Select invoice ${entry.invoice_no}`}
                                                    />
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Sl"
                                                    mobile="skip"
                                                    className="px-4 py-3 text-muted-foreground"
                                                >
                                                    {index + 1}
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
                                                    label="Invoice Date"
                                                    className={cn(
                                                        'px-4 py-3',
                                                        entry.invoice_date_differs_from_period &&
                                                            'font-medium text-sky-700 dark:text-sky-400',
                                                    )}
                                                    title={
                                                        entry.invoice_date_differs_from_period
                                                            ? 'Invoice issued in a different month than this statement'
                                                            : undefined
                                                    }
                                                >
                                                    {entry.transaction_date}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Branch"
                                                    className={cn(
                                                        'px-4 py-3 font-mono',
                                                        !entry.is_resolved &&
                                                            !noBranchExpectedFlag &&
                                                            !entry.suggested_branch_id &&
                                                            'font-semibold text-amber-700 dark:text-amber-400',
                                                        entry.suggested_branch_id &&
                                                            'text-muted-foreground italic',
                                                    )}
                                                    title={
                                                        entry.suggested_branch_id
                                                            ? 'Auto-matched from branch statement'
                                                            : undefined
                                                    }
                                                >
                                                    {formatBranchLabel(
                                                        entry.branch_code,
                                                        entry.branch_name,
                                                    )}
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
                                                        className={cn(
                                                            reconciliationInvoiceTextClassName(
                                                                rowStatus,
                                                                isDuplicate,
                                                            ),
                                                        )}
                                                    />
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Amount"
                                                    className="px-4 py-3 text-right"
                                                >
                                                    {formatAmount(entry.amount)}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Branch Amount"
                                                    className="px-4 py-3 text-right text-muted-foreground"
                                                >
                                                    {entry.branch_amount
                                                        ? formatAmount(
                                                              entry.branch_amount,
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
                                                        {!entry.is_resolved &&
                                                            !noBranchExpectedFlag && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        toggleNoBranchExpected(
                                                                            entry,
                                                                            true,
                                                                        )
                                                                    }
                                                                >
                                                                    Supplier
                                                                </Button>
                                                            )}
                                                        {noBranchExpectedFlag && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    toggleNoBranchExpected(
                                                                        entry,
                                                                        false,
                                                                    )
                                                                }
                                                            >
                                                                Expect branch
                                                            </Button>
                                                        )}
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
                                                                    from this
                                                                    received
                                                                    statement.
                                                                </DialogDescription>
                                                                <Form
                                                                    {...IncomingStatementEntryController.destroy.form(
                                                                        entry.id,
                                                                    )}
                                                                    options={{
                                                                        preserveScroll:
                                                                            true,
                                                                    }}
                                                                    onSuccess={() =>
                                                                        setSelectedIds(
                                                                            (
                                                                                current,
                                                                            ) =>
                                                                                current.filter(
                                                                                    (
                                                                                        id,
                                                                                    ) =>
                                                                                        id !==
                                                                                        entry.id,
                                                                                ),
                                                                        )
                                                                    }
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
                                            colSpan={
                                                multiplePeriods ? 6 : 5
                                            }
                                        >
                                            {hasActiveFilters
                                                ? 'Filtered total'
                                                : 'Total'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {formatAmount(
                                                hasActiveFilters
                                                    ? filteredTotal
                                                    : total,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {formatAmount(
                                                hasActiveFilters
                                                    ? filteredBranchTotal
                                                    : branchStatementTotal,
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {formatAmount(
                                                hasActiveFilters
                                                    ? filteredDifferenceTotal
                                                    : totalDifference,
                                            )}
                                        </td>
                                        <td className="px-4 py-3" />
                                    </tr>
                                </tfoot>
                            </AppTable>
                        </AppTableScroll>
                        </>
                    )}
                </CardContent>
            </Card>

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
                    <DialogTitle>Edit received statement entry</DialogTitle>
                    {editingEntry && (
                        <Form
                            {...IncomingStatementEntryController.update.form(
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
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-branch-id">
                                            Branch
                                        </Label>
                                        <select
                                            id="edit-branch-id"
                                            name="branch_id"
                                            defaultValue={
                                                editingEntry.branch_id
                                                    ? String(
                                                          editingEntry.branch_id,
                                                      )
                                                    : editingEntry.suggested_branch_id
                                                      ? String(
                                                            editingEntry.suggested_branch_id,
                                                        )
                                                      : ''
                                            }
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        >
                                            <option value="">
                                                Unassigned
                                            </option>
                                            {branches.map((branch) => (
                                                <option
                                                    key={branch.id}
                                                    value={branch.id}
                                                >
                                                    {formatBranchLabel(
                                                    branch.code,
                                                    branch.name,
                                                )}
                                                </option>
                                            ))}
                                        </select>
                                        {editingEntry.branch_id === null &&
                                            editingEntry.suggested_branch_id !==
                                                null &&
                                            editingEntry.suggested_branch_id !==
                                                undefined && (
                                                <p className="text-xs text-muted-foreground">
                                                    Auto-matched from branch
                                                    statement — save to confirm.
                                                </p>
                                            )}
                                        <InputError
                                            message={errors.branch_id}
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
                                            Save changes
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </DialogContent>
            </Dialog>

        <Dialog
            open={addingMultipleEntries}
            onOpenChange={(open) => {
                if (!open) {
                    setAddingMultipleEntries(false);
                }
            }}
        >
            <DialogContent className="max-h-[90vh] w-[90vw] max-w-[90vw] overflow-y-auto sm:max-w-[90vw]">
                <DialogTitle>Add received statement entries</DialogTitle>
                <DialogDescription>
                    Enter rows like a spreadsheet for {periodLabel}. Paste
                    invoice date, invoice no, and amount. Entries appear under
                    the invoice month from each invoice date.
                </DialogDescription>
                {bulkHasDateIssues && (
                    <p className="text-sm font-medium text-destructive">
                        {`Invalid date format on row${bulkRowValidation.invalidRowNumbers.length === 1 ? '' : 's'} ${bulkRowValidation.invalidRowNumbers.join(', ')}. Use dd/mm/yyyy and make sure invoice numbers are not in the date column.`}
                    </p>
                )}

                <Form
                    {...IncomingStatementEntryController.bulkStore.form(client.id)}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                    onSuccess={() => {
                        setAddingMultipleEntries(false);
                        setGridRows(createEmptySpreadsheetRows(8));
                    }}
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="year"
                                value={bulkSubmitPeriod.year}
                            />
                            <input
                                type="hidden"
                                name="month"
                                value={bulkSubmitPeriod.month}
                            />

                            <StatementEntryGrid
                                rows={gridRows}
                                onChange={setGridRows}
                                rowErrors={mapGridRowErrors(errors)}
                            />

                            <InputError message={errors.entries} />

                            {bulkCompleteRows.map((row, index) => (
                                <div key={index} className="hidden">
                                    <input
                                        type="hidden"
                                        name={`entries[${index}][transaction_date]`}
                                        value={row.transaction_date}
                                    />
                                    <input
                                        type="hidden"
                                        name={`entries[${index}][invoice_no]`}
                                        value={row.invoice_no}
                                    />
                                    <input
                                        type="hidden"
                                        name={`entries[${index}][amount]`}
                                        value={row.amount}
                                    />
                                </div>
                            ))}

                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() =>
                                        setAddingMultipleEntries(false)
                                    }
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        bulkCompleteRows.length === 0 ||
                                        bulkHasDateIssues
                                    }
                                >
                                    Save {bulkCompleteRows.length}{' '}
                                    {bulkCompleteRows.length === 1
                                        ? 'entry'
                                        : 'entries'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
        </>
    );
}

ClientsReceivedStatementsIndex.layout = () => ({
    breadcrumbs: [{ title: 'Received Statement', href: '#' }],
});
