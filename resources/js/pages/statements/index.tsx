import { Form, Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Download,
    FileSpreadsheet,
    Pencil,
    Plus,
    RefreshCw,
    ScanLine,
    Search,
    Table,
    Trash2,
    Upload,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useSearchFromUrl } from '@/hooks/use-search-from-url';
import { useFilterFromUrl } from '@/hooks/use-reconciliation-filters-from-url';
import StatementEntryController from '@/actions/App/Http/Controllers/StatementEntryController';
import Heading from '@/components/heading';
import { BackToTopButton } from '@/components/back-to-top-button';
import { InvoiceScanDialog } from '@/components/invoice-scan-dialog';
import { InvoiceNoLink } from '@/components/invoice-no-link';
import { ReconciliationLegend } from '@/components/reconciliation-legend';
import { StatsSummaryLine } from '@/components/stats-summary-line';
import {
    BRANCH_STATEMENT_LEGEND_STATUSES,
    reconciliationDiffTextClassName,
    reconciliationInvoiceTextClassName,
    reconciliationRowClassName,
    resolveReconciliationRowStatus,
} from '@/lib/reconciliation-row-status';
import {
    pageShellClassName,
    toolbarScrollClassName,
} from '@/lib/page-layout';
import {
    AppTable,
    AppTableBodyCell,
    AppTableHeadCell,
    AppTableScroll,
} from '@/components/app-table';
import InputError from '@/components/input-error';
import {
    BranchFilterMenu,
    PeriodFilterMenu,
} from '@/components/statement-filter-menus';
import { StatementEntryGrid } from '@/components/statement-entry-grid';
import { SortableTableHead } from '@/components/sortable-table-head';
import { Checkbox } from '@/components/ui/checkbox';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { show as clientShow } from '@/routes/clients';
import { excel, pdf } from '@/routes/branches/statements/export';
import { noBillExpected } from '@/routes/statement-entries';
import { importMethod as statementsImport } from '@/routes/branches/statements';
import { index as statementsIndex } from '@/routes/branches/statements';
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
import {
    createEmptySpreadsheetRows,
    isSpreadsheetRowComplete,
    validateCompleteSpreadsheetRows,
    type SpreadsheetRow,
} from '@/lib/spreadsheet-paste';
import {
    buildStatementFilterQuery,
    normalizePeriodSelection,
} from '@/lib/statement-filters';
import { cn } from '@/lib/utils';
import type {
    Branch,
    BranchOption,
    Client,
    StatementEntry,
    StatementMonth,
} from '@/types';

type Props = {
    branch: Branch;
    client: Client;
    branches: BranchOption[];
    branchIds: number[];
    entries: StatementEntry[];
    total: number | string;
    chequeReceivedTotal: string;
    differenceTotal: string;
    clientStatementTotal: string;
    clientDifferenceTotal: string;
    year: number;
    month: number;
    periodLabel: string;
    selectedPeriods: StatementMonth[];
    availableMonths: StatementMonth[];
    previousPeriod: string;
    nextPeriod: string;
};

type EntrySortColumn =
    | 'branch_code'
    | 'transaction_date'
    | 'invoice_no'
    | 'amount';

type EntryFilter =
    | 'all'
    | 'resolved'
    | 'unresolved'
    | 'no_bill'
    | 'mismatches'
    | 'duplicates'
    | 'unique';

const ENTRY_FILTER_VALUES = [
    'all',
    'resolved',
    'unresolved',
    'no_bill',
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

function defaultTransactionDate(year: number, month: number): string {
    return `01/${String(month).padStart(2, '0')}/${year}`;
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

export default function StatementsIndex({
    branch,
    client,
    branches,
    branchIds,
    entries,
    total,
    chequeReceivedTotal,
    differenceTotal,
    clientStatementTotal,
    clientDifferenceTotal,
    year,
    month,
    periodLabel,
    selectedPeriods,
    availableMonths,
    previousPeriod,
    nextPeriod,
}: Props) {
    const [sortColumn, setSortColumn] =
        useState<EntrySortColumn>('transaction_date');
    const [sortDirection, setSortDirection] =
        useState<SortDirection>('asc');
    const [search, setSearch] = useSearchFromUrl();
    const [filter, setFilter] = useFilterFromUrl(ENTRY_FILTER_VALUES);
    const [editingEntry, setEditingEntry] = useState<StatementEntry | null>(
        null,
    );
    const [scanEntry, setScanEntry] = useState<StatementEntry | null>(null);
    const [addingEntry, setAddingEntry] = useState(false);
    const [addingMultipleEntries, setAddingMultipleEntries] = useState(false);
    const [gridRows, setGridRows] = useState<SpreadsheetRow[]>(() =>
        createEmptySpreadsheetRows(8),
    );
    const [selectedIds, setSelectedIds] = useState<number[]>([]);

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

    const filterQuery = buildStatementFilterQuery(branchIds, selectedPeriods);
    const multipleBranches = branchIds.length > 1;
    const multiplePeriods =
        selectedPeriods.length > 1 ||
        (selectedPeriods.length === 0 && availableMonths.length > 1);
    const singlePeriodSelected = selectedPeriods.length === 1;
    const duplicateScope = multipleBranches ? 'branch-invoice' : 'invoice';

    function resolvePeriod(yearValue: number, monthValue: number): StatementMonth {
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

    function applyFilters(
        nextBranchIds: number[],
        nextPeriods: StatementMonth[],
    ) {
        const normalizedPeriods = normalizePeriodSelection(
            nextPeriods,
            availableMonths,
        );
        const anchorBranchId = nextBranchIds.includes(branch.id)
            ? branch.id
            : (nextBranchIds[0] ?? branch.id);

        router.get(
            statementsIndex.url(anchorBranchId, {
                query: buildStatementFilterQuery(
                    nextBranchIds,
                    normalizedPeriods,
                ),
            }),
            {},
            { preserveState: false, preserveScroll: true },
        );
    }

    function changePeriod(nextYearValue: number, nextMonthValue: number) {
        applyFilters(branchIds, [resolvePeriod(nextYearValue, nextMonthValue)]);
    }

    function handleSort(column: EntrySortColumn) {
        setSortDirection((currentDirection) =>
            toggleSortDirection(sortColumn, column, currentDirection),
        );
        setSortColumn(column);
    }

    const duplicateInvoiceKeys = useMemo(
        () => getDuplicateInvoiceKeys(entries, duplicateScope),
        [duplicateScope, entries],
    );

    const hasDuplicateInvoices = duplicateInvoiceKeys.size > 0;

    const filteredEntries = useMemo(() => {
        const query = search.trim().toLowerCase();

        return entries.filter((entry) => {
            const isDuplicate = isDuplicateInvoice(
                entry,
                duplicateInvoiceKeys,
                duplicateScope,
            );

            const matchesSearch =
                query === '' ||
                entry.invoice_no.toLowerCase().includes(query) ||
                entry.transaction_date.includes(query) ||
                entry.amount.includes(query) ||
                (entry.branch_code?.toLowerCase().includes(query) ?? false) ||
                (entry.cheque_number?.toLowerCase().includes(query) ?? false) ||
                (entry.cheque_received_amount?.includes(query) ?? false) ||
                (entry.client_statement_amount?.includes(query) ?? false) ||
                (entry.difference_amount?.includes(query) ?? false) ||
                (entry.client_difference_amount?.includes(query) ?? false);

            const isResolved = entry.is_resolved ?? false;
            const noBillExpectedFlag = entry.no_bill_expected ?? false;

            const matchesFilter =
                filter === 'all' ||
                (filter === 'resolved' && isResolved) ||
                (filter === 'unresolved' &&
                    !isResolved &&
                    !noBillExpectedFlag) ||
                (filter === 'no_bill' && noBillExpectedFlag) ||
                (filter === 'mismatches' &&
                    (entry.has_difference || entry.has_client_difference)) ||
                (filter === 'duplicates' && isDuplicate) ||
                (filter === 'unique' && !isDuplicate);

            return matchesSearch && matchesFilter;
        });
    }, [duplicateInvoiceKeys, duplicateScope, entries, filter, search]);

    const hasActiveFilters = search.trim() !== '' || filter !== 'all';
    const isDefaultBranchSelection =
        branchIds.length === 1 && branchIds[0] === branch.id;
    const hasClearableFilters =
        hasActiveFilters ||
        !isDefaultBranchSelection ||
        selectedPeriods.length > 0;

    const unresolvedCount = useMemo(
        () =>
            entries.filter(
                (entry) =>
                    !(entry.is_resolved ?? false) &&
                    !(entry.no_bill_expected ?? false),
            ).length,
        [entries],
    );

    const noBillCount = useMemo(
        () =>
            entries.filter((entry) => entry.no_bill_expected ?? false).length,
        [entries],
    );

    const mismatchCount = useMemo(
        () =>
            entries.filter(
                (entry) =>
                    entry.has_difference || entry.has_client_difference,
            ).length,
        [entries],
    );

    const filteredClientStatementTotal = useMemo(
        () =>
            filteredEntries.reduce(
                (sum, entry) =>
                    sum + (entry.client_statement_amount_value ?? 0),
                0,
            ),
        [filteredEntries],
    );

    const filteredClientDifferenceTotal = useMemo(
        () =>
            filteredEntries.reduce(
                (sum, entry) =>
                    sum + (entry.client_difference_amount_value ?? 0),
                0,
            ),
        [filteredEntries],
    );

    const filteredChequeReceivedTotal = useMemo(
        () =>
            filteredEntries.reduce(
                (sum, entry) =>
                    sum + (entry.cheque_received_amount_value ?? 0),
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

    const filteredBranchTotal = useMemo(
        () =>
            filteredEntries.reduce(
                (sum, entry) =>
                    sum + (entry.amount_value ?? Number(entry.amount)),
                0,
            ),
        [filteredEntries],
    );

    const displayTotal = hasActiveFilters
        ? formatAmount(filteredBranchTotal)
        : formatAmount(total);
    const displayChequeReceivedTotal = hasActiveFilters
        ? formatAmount(filteredChequeReceivedTotal)
        : chequeReceivedTotal;
    const displayDifferenceTotal = hasActiveFilters
        ? formatAmount(filteredDifferenceTotal)
        : differenceTotal;
    const displayClientStatementTotal = hasActiveFilters
        ? formatAmount(filteredClientStatementTotal)
        : clientStatementTotal;
    const displayClientDifferenceTotal = hasActiveFilters
        ? formatAmount(filteredClientDifferenceTotal)
        : clientDifferenceTotal;

    const footerLabelColSpan =
        2 +
        (multipleBranches ? 1 : 0) +
        (multiplePeriods ? 1 : 0) +
        2;

    const displayedEntries = useMemo(() => {
        return [...filteredEntries].sort((left, right) => {
            switch (sortColumn) {
                case 'branch_code':
                    return compareStrings(
                        left.branch_code ?? '',
                        right.branch_code ?? '',
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
                default:
                    return 0;
            }
        });
    }, [filteredEntries, sortColumn, sortDirection]);

    const displayedEntryIds = useMemo(
        () => displayedEntries.map((entry) => entry.id),
        [displayedEntries],
    );

    const exportQuery = useMemo(() => {
        const baseQuery: Record<string, string[] | number[]> = {
            ...filterQuery,
        };

        if (hasActiveFilters) {
            baseQuery.entry_ids = displayedEntryIds;
        }

        return baseQuery;
    }, [displayedEntryIds, filterQuery, hasActiveFilters]);

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

    function changeBranch(nextBranchIds: number[]) {
        applyFilters(nextBranchIds, selectedPeriods);
    }

    function toggleNoBillExpected(
        entry: StatementEntry,
        expected: boolean,
    ) {
        router.patch(
            noBillExpected.url(entry.id),
            { no_bill_expected: expected },
            { preserveScroll: true },
        );
    }

    function clearAllFilters() {
        setSearch('');
        setFilter('all');
        setSelectedIds([]);

        router.get(
            statementsIndex.url(branch.id),
            {},
            { preserveState: false, preserveScroll: true },
        );
    }

    function refreshPage() {
        router.reload({ preserveScroll: true });
    }

    return (
        <>
            <Head title={`${client.name} - ${periodLabel}`} />

            <div className={pageShellClassName}>
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-3">
                        <Heading
                            title={`${client.name} — ${branch.name}`}
                            description={`Statement for ${periodLabel}${
                                multipleBranches
                                    ? ` · ${branchIds.length} branches`
                                    : ` · ${branch.code}`
                            }`}
                        />
                        <div className="flex flex-wrap items-center gap-2">
                            <BranchFilterMenu
                                branches={branches}
                                branchIds={branchIds}
                                onChange={changeBranch}
                            />
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    applyFilters(
                                        branches.map((item) => item.id),
                                        selectedPeriods,
                                    )
                                }
                            >
                                All branches
                            </Button>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => setAddingEntry(true)}>
                            <Plus className="size-4" />
                            Add entry
                        </Button>
                        <Button variant="outline" onClick={openMultipleEntryDialog}>
                            <Table className="size-4" />
                            Add entries
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={statementsImport.url(branch.id, {
                                    query: filterQuery,
                                })}
                            >
                                <Upload className="size-4" />
                                Upload Excel
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <a
                                href={excel.url(branch.id, {
                                    query: exportQuery,
                                })}
                            >
                                <Download className="size-4" />
                                Export Excel
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <a
                                href={pdf.url(branch.id, {
                                    query: exportQuery,
                                })}
                            >
                                <FileSpreadsheet className="size-4" />
                                Export PDF
                            </a>
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardHeader className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div className="min-w-0 flex-1">
                            <CardTitle>{periodLabel}</CardTitle>
                            <StatsSummaryLine
                                items={[
                                    hasActiveFilters
                                        ? `${filteredEntries.length} of ${entries.length} entries`
                                        : `${entries.length} entries`,
                                    `Branch ${displayTotal}`,
                                    `Client ${displayClientStatementTotal}`,
                                    `Client diff ${displayClientDifferenceTotal}`,
                                    `Cheque ${displayChequeReceivedTotal}`,
                                    `Cheque diff ${displayDifferenceTotal}`,
                                    unresolvedCount > 0
                                        ? `${unresolvedCount} without client or cheque match`
                                        : null,
                                    noBillCount > 0
                                        ? `${noBillCount} no bill expected`
                                        : null,
                                    mismatchCount > 0
                                        ? `${mismatchCount} mismatches`
                                        : null,
                                    hasDuplicateInvoices
                                        ? 'Duplicate invoices highlighted'
                                        : null,
                                ]}
                            />
                        </div>
                        <div
                            className={`${toolbarScrollClassName} shrink-0 self-stretch sm:self-start`}
                        >
                            {!multiplePeriods && singlePeriodSelected && (
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
                                onChange={(periods) =>
                                    applyFilters(branchIds, periods)
                                }
                                emptyLabel="All months"
                                allowEmpty
                            />
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => applyFilters(branchIds, [])}
                            >
                                All months
                            </Button>
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
                                aria-label="Refresh statement"
                            >
                                <RefreshCw className="size-4" />
                            </Button>
                            {!multiplePeriods && singlePeriodSelected && (
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
                    <CardContent>
                        {entries.length === 0 ? (
                            <div className="rounded-lg border border-dashed py-12 text-center">
                                <p className="font-medium">
                                    No statement entries
                                    {singlePeriodSelected
                                        ? ' for this month'
                                        : ''}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Upload an Excel file, add entries in a
                                    spreadsheet grid, or add a single entry with
                                    date (dd/mm/yyyy), invoice no, and amount.
                                </p>
                                <div className="mt-4 flex flex-wrap justify-center gap-2">
                                    <Button onClick={() => setAddingEntry(true)}>
                                        <Plus className="size-4" />
                                        Add entry
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={openMultipleEntryDialog}
                                    >
                                        <Table className="size-4" />
                                        Add entries
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <Link
                                href={statementsImport.url(branch.id, {
                                    query: filterQuery,
                                })}
                            >
                                            Upload statement
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <>
                                <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                    <div className="relative w-full lg:max-w-sm">
                                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            value={search}
                                            onChange={(event) =>
                                                setSearch(event.target.value)
                                            }
                                            placeholder="Search date, branch, invoice, client, cheque, amount, difference..."
                                            className="pl-9"
                                        />
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {(
                                            [
                                                ['all', 'All'],
                                                ['resolved', 'Resolved'],
                                                ['unresolved', 'Unresolved'],
                                                ['no_bill', 'No bill'],
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
                                    statuses={BRANCH_STATEMENT_LEGEND_STATUSES}
                                />

                                {displayedEntries.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No entries match your search or filters.
                                    </p>
                                ) : (
                            <>
                                {selectedIds.length > 0 && (
                                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 px-4 py-3">
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
                                                    {selectedIds.length}{' '}
                                                    statement{' '}
                                                    {selectedIds.length === 1
                                                        ? 'entry'
                                                        : 'entries'}
                                                    .
                                                </DialogDescription>
                                                <Form
                                                    {...StatementEntryController.bulkDestroy.form(
                                                        branch.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
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
                                                            {branchIds.map((branchId) => (
                                                                <input
                                                                    key={branchId}
                                                                    type="hidden"
                                                                    name="branch_ids[]"
                                                                    value={branchId}
                                                                />
                                                            ))}
                                                            {selectedPeriods.map(
                                                                (period) => (
                                                                    <input
                                                                        key={`${period.year}-${period.month}`}
                                                                        type="hidden"
                                                                        name="periods[]"
                                                                        value={`${period.year}-${period.month}`}
                                                                    />
                                                                ),
                                                            )}
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

                            <AppTableScroll>
                                <AppTable className="w-full md:min-w-[960px] md:text-center">
                                    <thead>
                                        <tr className="border-b text-muted-foreground">
                                            <AppTableHeadCell className="px-4 py-2 text-left">
                                                <Checkbox
                                                    checked={allDisplayedSelected}
                                                    onCheckedChange={
                                                        toggleSelectAll
                                                    }
                                                    aria-label="Select all entries"
                                                />
                                            </AppTableHeadCell>
                                            <AppTableHeadCell className="w-12 px-4 py-2 font-medium">
                                                SI
                                            </AppTableHeadCell>
                                            {multipleBranches && (
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
                                                    align="center"
                                                    className="py-2"
                                                />
                                            )}
                                            {multiplePeriods && (
                                                <AppTableHeadCell className="px-4 py-2 font-medium">
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
                                                align="center"
                                                className="py-2"
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
                                                align="center"
                                                className="py-2"
                                            />
                                            <SortableTableHead
                                                label="Branch amount"
                                                column="amount"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as EntrySortColumn,
                                                    )
                                                }
                                                align="center"
                                                className="py-2"
                                            />
                                            <AppTableHeadCell className="px-4 py-2 font-medium">
                                                Client amount
                                            </AppTableHeadCell>
                                            <AppTableHeadCell className="px-4 py-2 font-medium">
                                                Client diff
                                            </AppTableHeadCell>
                                            <AppTableHeadCell className="px-4 py-2 font-medium">
                                                Cheque No
                                            </AppTableHeadCell>
                                            <AppTableHeadCell className="px-4 py-2 font-medium">
                                                Cheque received
                                            </AppTableHeadCell>
                                            <AppTableHeadCell className="px-4 py-2 font-medium">
                                                Cheque diff
                                            </AppTableHeadCell>
                                            <AppTableHeadCell className="px-4 py-2 font-medium">
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
                                                    duplicateScope,
                                                );
                                            const chequeDifferenceValue =
                                                entry.difference_amount_value ??
                                                null;
                                            const clientDifferenceValue =
                                                entry.client_difference_amount_value ??
                                                null;
                                            const isResolved =
                                                entry.is_resolved ?? false;
                                            const noBillExpectedFlag =
                                                entry.no_bill_expected ?? false;
                                            const rowStatus =
                                                resolveReconciliationRowStatus({
                                                    isDuplicate,
                                                    isResolved,
                                                    noBillExpected:
                                                        noBillExpectedFlag,
                                                    differenceValue:
                                                        chequeDifferenceValue,
                                                    clientDifferenceValue,
                                                });

                                            return (
                                            <tr
                                                key={entry.id}
                                                className={cn(
                                                    'border-b',
                                                    reconciliationRowClassName(
                                                        rowStatus,
                                                    ),
                                                )}
                                            >
                                                <AppTableBodyCell
                                                    mobile="skip"
                                                    className="px-4 py-2 text-left"
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
                                                    label="SI"
                                                    mobile="skip"
                                                    className="px-4 py-2 text-muted-foreground"
                                                >
                                                    {index + 1}
                                                </AppTableBodyCell>
                                                {multipleBranches && (
                                                    <AppTableBodyCell
                                                        label="Branch"
                                                        className="px-4 py-2 font-mono"
                                                    >
                                                        {entry.branch_code ??
                                                            '—'}
                                                    </AppTableBodyCell>
                                                )}
                                                {multiplePeriods && (
                                                    <AppTableBodyCell
                                                        label="Month"
                                                        className="px-4 py-2 text-muted-foreground"
                                                    >
                                                        {entry.statement_period ??
                                                            '—'}
                                                    </AppTableBodyCell>
                                                )}
                                                <AppTableBodyCell
                                                    label="Invoice Date"
                                                    className={cn(
                                                        'px-4 py-2',
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
                                                    label="Invoice No"
                                                    mobile="primary"
                                                    className={cn(
                                                        'px-4 py-2 font-mono',
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
                                                    label="Branch amount"
                                                    className="px-4 py-2 font-mono tabular-nums"
                                                >
                                                    {formatAmount(entry.amount)}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Client amount"
                                                    className="px-4 py-2 font-mono tabular-nums text-muted-foreground"
                                                >
                                                    {entry.client_statement_amount ??
                                                        '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Client diff"
                                                    className={cn(
                                                        'px-4 py-2 font-mono tabular-nums',
                                                        reconciliationDiffTextClassName(
                                                            clientDifferenceValue,
                                                            rowStatus,
                                                        ),
                                                    )}
                                                >
                                                    {entry.client_difference_amount ??
                                                        '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Cheque No"
                                                    className="px-4 py-2 font-mono"
                                                >
                                                    {entry.cheque_number ?? '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Cheque received"
                                                    className="px-4 py-2 font-mono tabular-nums text-muted-foreground"
                                                >
                                                    {entry.cheque_received_amount ??
                                                        '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Cheque diff"
                                                    className={cn(
                                                        'px-4 py-2 font-mono tabular-nums',
                                                        reconciliationDiffTextClassName(
                                                            chequeDifferenceValue,
                                                            rowStatus,
                                                        ),
                                                    )}
                                                >
                                                    {entry.difference_amount ??
                                                        '—'}
                                                </AppTableBodyCell>
                                                <AppTableBodyCell
                                                    label="Actions"
                                                    mobile="actions"
                                                    className="px-4 py-2"
                                                >
                                                    <div className="flex flex-nowrap justify-end gap-1">
                                                        {!isResolved &&
                                                            !noBillExpectedFlag && (
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        toggleNoBillExpected(
                                                                            entry,
                                                                            true,
                                                                        )
                                                                    }
                                                                >
                                                                    No bill
                                                                </Button>
                                                            )}
                                                        {noBillExpectedFlag && (
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    toggleNoBillExpected(
                                                                        entry,
                                                                        false,
                                                                    )
                                                                }
                                                            >
                                                                Expect bill
                                                            </Button>
                                                        )}
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                setScanEntry(
                                                                    entry,
                                                                )
                                                            }
                                                            title={
                                                                entry.has_invoice_scan
                                                                    ? 'View or replace invoice scan'
                                                                    : 'Upload invoice scan'
                                                            }
                                                        >
                                                            <ScanLine
                                                                className={
                                                                    entry.has_invoice_scan
                                                                        ? 'size-4 text-primary'
                                                                        : 'size-4'
                                                                }
                                                            />
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                setEditingEntry(
                                                                    entry,
                                                                )
                                                            }
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                        <Dialog>
                                                            <DialogTrigger
                                                                asChild
                                                            >
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                >
                                                                    <Trash2 className="size-4 text-destructive" />
                                                                </Button>
                                                            </DialogTrigger>
                                                            <DialogContent>
                                                                <DialogTitle>
                                                                    Delete entry?
                                                                </DialogTitle>
                                                                <DialogDescription>
                                                                    This will
                                                                    permanently
                                                                    remove invoice{' '}
                                                                    {
                                                                        entry.invoice_no
                                                                    }{' '}
                                                                    from this
                                                                    statement.
                                                                </DialogDescription>
                                                                <Form
                                                                    {...StatementEntryController.destroy.form(
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
                                        <tr className="font-semibold">
                                            <td
                                                className="px-4 pt-3 text-left"
                                                colSpan={footerLabelColSpan}
                                            >
                                                {hasActiveFilters
                                                    ? 'Filtered total'
                                                    : 'Total'}
                                            </td>
                                            <td className="px-4 pt-3 font-mono tabular-nums">
                                                {displayTotal}
                                            </td>
                                            <td className="px-4 pt-3 font-mono tabular-nums">
                                                {displayClientStatementTotal}
                                            </td>
                                            <td className="px-4 pt-3 font-mono tabular-nums">
                                                {displayClientDifferenceTotal}
                                            </td>
                                            <td className="px-4 pt-3" />
                                            <td className="px-4 pt-3 font-mono tabular-nums">
                                                {displayChequeReceivedTotal}
                                            </td>
                                            <td className="px-4 pt-3 font-mono tabular-nums">
                                                {displayDifferenceTotal}
                                            </td>
                                            <td className="px-4 pt-3" />
                                        </tr>
                                    </tfoot>
                                </AppTable>
                            </AppTableScroll>
                            </>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>

                <Button variant="link" className="w-fit px-0" asChild>
                    <Link href={clientShow(client.id)}>
                        Back to {client.name}
                    </Link>
                </Button>
            </div>

            <BackToTopButton />

            <Dialog open={addingEntry} onOpenChange={setAddingEntry}>
                <DialogContent>
                    <DialogTitle>Add statement entry</DialogTitle>
                    <DialogDescription>
                        Add a new entry for {periodLabel}. Date must be within
                        this month (dd/mm/yyyy).
                    </DialogDescription>
                    <Form
                        {...StatementEntryController.store.form(branch.id)}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                        onSuccess={() => setAddingEntry(false)}
                    >
                        {({ processing, errors }) => (
                            <>
                                <input type="hidden" name="year" value={year} />
                                <input
                                    type="hidden"
                                    name="month"
                                    value={month}
                                />
                                <div className="grid gap-2">
                                    <Label htmlFor="add-transaction-date">
                                        Date
                                    </Label>
                                    <Input
                                        id="add-transaction-date"
                                        name="transaction_date"
                                        defaultValue={defaultTransactionDate(
                                            year,
                                            month,
                                        )}
                                        placeholder="dd/mm/yyyy"
                                        required
                                    />
                                    <InputError
                                        message={errors.transaction_date}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="add-invoice-no">
                                        Invoice No
                                    </Label>
                                    <Input
                                        id="add-invoice-no"
                                        name="invoice_no"
                                        required
                                    />
                                    <InputError message={errors.invoice_no} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="add-amount">Amount</Label>
                                    <Input
                                        id="add-amount"
                                        name="amount"
                                        type="number"
                                        step="0.001"
                                        required
                                    />
                                    <InputError message={errors.amount} />
                                </div>
                                <DialogFooter className="gap-2">
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => setAddingEntry(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        Add entry
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>

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

            <Dialog
                open={addingMultipleEntries}
                onOpenChange={(open) => {
                    setAddingMultipleEntries(open);

                    if (!open) {
                        setGridRows(createEmptySpreadsheetRows(8));
                    }
                }}
            >
                <DialogContent className="max-h-[90vh] w-[90vw] max-w-[90vw] overflow-y-auto sm:max-w-[90vw]">
                    <DialogTitle>Add multiple entries</DialogTitle>
                    <DialogDescription>
                        Enter rows like a spreadsheet for {periodLabel}. Paste
                        invoice date, invoice no, and amount. Entries are saved
                        to {periodLabel} even when the invoice date is in
                        another month.
                    </DialogDescription>
                    {bulkHasDateIssues && (
                        <p className="text-sm font-medium text-destructive">
                            {`Invalid date format on row${bulkRowValidation.invalidRowNumbers.length === 1 ? '' : 's'} ${bulkRowValidation.invalidRowNumbers.join(', ')}. Use dd/mm/yyyy and make sure invoice numbers are not in the date column.`}
                        </p>
                    )}
                    <Form
                        {...StatementEntryController.bulkStore.form(branch.id)}
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

            <InvoiceScanDialog
                entry={scanEntry}
                open={scanEntry !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setScanEntry(null);
                    }
                }}
            />
        </>
    );
}

StatementsIndex.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Clients', href: '#' },
        { title: props.client.name, href: clientShow(props.client.id) },
        { title: props.branch.name, href: '#' },
    ],
});
