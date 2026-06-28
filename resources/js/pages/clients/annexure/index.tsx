import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    Download,
    FileSpreadsheet,
    Pencil,
    Plus,
    Search,
    Table,
    Trash2,
    Upload,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useSearchFromUrl } from '@/hooks/use-search-from-url';
import { useFilterFromUrl } from '@/hooks/use-reconciliation-filters-from-url';
import ClientAnnexureChequeController from '@/actions/App/Http/Controllers/ClientAnnexureChequeController';
import ClientAnnexureEntryController from '@/actions/App/Http/Controllers/ClientAnnexureEntryController';
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
import {
    AppTable,
    AppTableBodyCell,
    AppTableHeadCell,
    AppTableScroll,
} from '@/components/app-table';
import InputError from '@/components/input-error';
import {
    BranchFilterMenu,
    OptionFilterMenu,
    PeriodFilterMenu,
} from '@/components/statement-filter-menus';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { periodKey, sortBranchesByCode } from '@/lib/statement-filters';
import { mergeReconciliationQuery } from '@/lib/reconciliation-url';
import {
    getDuplicateInvoiceKeys,
    isDuplicateInvoice,
} from '@/lib/duplicate-invoices';
import {
    compareNumbers,
    compareStrings,
    toggleSortDirection,
    type SortDirection,
} from '@/lib/table-sort';
import {
    createEmptySpreadsheetRows,
    isSpreadsheetRowComplete,
    validateCompleteSpreadsheetRows,
    type SpreadsheetRow,
} from '@/lib/spreadsheet-paste';
import { cn } from '@/lib/utils';
import {
    importMethod as annexureImport,
    index as annexureIndex,
} from '@/routes/clients/annexure';
import { excel, pdf } from '@/routes/clients/annexure/export';
import { noBranchExpected } from '@/routes/client-annexure-entries';
import type {
    AnnexureChequeSummary,
    BranchOption,
    Client,
    IncomingStatementEntry,
    StatementMonth,
} from '@/types';

type Phase = 'upload' | 'review' | 'payment' | 'complete';

type Props = {
    client: Pick<Client, 'id' | 'name'>;
    phase: Phase;
    chequeId: number | null;
    selectedChequeIds: number[];
    cheques: AnnexureChequeSummary[];
    entries: IncomingStatementEntry[];
    branches: BranchOption[];
    clientTotal: string;
    branchTotal: string;
    differenceTotal: string;
    rebate: string;
    checkTotal: string;
    netAmount: string;
    checkNumber: string;
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
    | 'resolved'
    | 'unresolved'
    | 'supplier'
    | 'mismatches'
    | 'duplicates'
    | 'unique';

const ENTRY_FILTER_VALUES = [
    'all',
    'resolved',
    'unresolved',
    'supplier',
    'mismatches',
    'duplicates',
    'unique',
] as const satisfies readonly EntryFilter[];

const STEPS: { key: Phase; label: string }[] = [
    { key: 'upload', label: 'Upload' },
    { key: 'review', label: 'Review' },
    { key: 'payment', label: 'Cheque & Rebate' },
    { key: 'complete', label: 'Saved' },
];

const CHEQUE_MONTH_OPTIONS = Array.from({ length: 12 }, (_, index) => ({
    value: String(index + 1),
    label: new Date(2000, index, 1).toLocaleString(undefined, {
        month: 'long',
    }),
}));

function ChequePeriodFields({
    chequeDate,
    year,
    month,
    errors,
    chequeDateId = 'cheque-date',
    yearId = 'cheque-year',
    monthId = 'cheque-month',
}: {
    chequeDate: string;
    year: number;
    month: number;
    errors: Record<string, string | undefined>;
    chequeDateId?: string;
    yearId?: string;
    monthId?: string;
}) {
    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor={chequeDateId}>Cheque date</Label>
                <Input
                    id={chequeDateId}
                    name="cheque_date"
                    defaultValue={chequeDate}
                    placeholder="dd/mm/yyyy"
                    required
                />
                <InputError message={errors.cheque_date} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={monthId}>Cheque month</Label>
                <div className="grid grid-cols-2 gap-3">
                    <div className="grid gap-2">
                        <Label
                            htmlFor={yearId}
                            className="text-xs text-muted-foreground"
                        >
                            Year
                        </Label>
                        <Input
                            id={yearId}
                            name="year"
                            type="number"
                            min={2000}
                            max={2100}
                            defaultValue={year}
                            required
                        />
                        <InputError message={errors.year} />
                    </div>
                    <div className="grid gap-2">
                        <Label
                            htmlFor={monthId}
                            className="text-xs text-muted-foreground"
                        >
                            Month
                        </Label>
                        <select
                            id={monthId}
                            name="month"
                            defaultValue={String(month)}
                            required
                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            {CHEQUE_MONTH_OPTIONS.map((option) => (
                                <option
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.month} />
                    </div>
                </div>
            </div>
        </>
    );
}

function formatAmount(value: number | string): string {
    return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
}

function parseAmount(value: string): number {
    return Number(value.replace(/,/g, '')) || 0;
}

function stepIndex(phase: Phase): number {
    return STEPS.findIndex((step) => step.key === phase);
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

export default function ClientsAnnexureIndex({
    client,
    phase,
    chequeId,
    selectedChequeIds,
    cheques,
    entries,
    branches,
    clientTotal,
    branchTotal,
    differenceTotal,
    rebate,
    checkTotal,
    netAmount,
    checkNumber,
    unresolvedCount: _unresolvedCount,
    mismatchCount,
    year,
    month,
    selectedPeriods,
    periodLabel,
    availableMonths,
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
    const [editingEntry, setEditingEntry] =
        useState<IncomingStatementEntry | null>(null);
    const [editingCheque, setEditingCheque] =
        useState<AnnexureChequeSummary | null>(null);
    const [checkNumberInput, setCheckNumberInput] = useState(checkNumber);
    const [checkAmountInput, setCheckAmountInput] = useState(checkTotal);
    const [rebateInput, setRebateInput] = useState(rebate);
    const [addingMultipleEntries, setAddingMultipleEntries] = useState(false);
    const [gridRows, setGridRows] = useState<SpreadsheetRow[]>(() =>
        createEmptySpreadsheetRows(8),
    );

    const currentStep = stepIndex(phase);
    const multiChequeView = selectedChequeIds.length > 1;

    const chequeFilterOptions = useMemo(
        () =>
            cheques.map((cheque) => ({
                value: String(cheque.id),
                label: `${cheque.check_number} · ${cheque.period_label}`,
            })),
        [cheques],
    );

    function buildAnnexureQuery(
        periods: StatementMonth[],
        chequeIds: number[],
    ): Record<string, number[] | string[]> {
        const query: Record<string, number[] | string[]> = {};

        if (periods.length > 0) {
            query.periods = periods.map(periodKey);
        }

        if (chequeIds.length > 0) {
            query.cheque_ids = chequeIds;
        }

        return query;
    }

    function applyAnnexureFilters(next: {
        periods?: StatementMonth[];
        chequeIds?: number[];
    }) {
        router.get(
            annexureIndex.url(client.id, {
                query: {
                    ...mergeReconciliationQuery(pageUrl),
                    ...buildAnnexureQuery(
                        next.periods ?? selectedPeriods,
                        next.chequeIds ?? selectedChequeIds,
                    ),
                },
            }),
            {},
            { preserveState: false },
        );
    }

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

    const primaryPeriod = selectedPeriods[0] ?? { year, month };

    const bulkSubmitPeriod = {
        year: primaryPeriod.year,
        month: primaryPeriod.month,
    };

    const bulkHasDateIssues =
        bulkCompleteRows.length > 0 &&
        bulkRowValidation.invalidRowNumbers.length > 0;

    useEffect(() => {
        setCheckNumberInput(checkNumber);
        setCheckAmountInput(checkTotal);
        setRebateInput(rebate);
    }, [checkNumber, checkTotal, rebate, chequeId, year, month]);

    useEffect(() => {
        setSelectedBranchFilterIds([]);
    }, [selectedChequeIds, selectedPeriods]);

    function openCheque(id: number) {
        applyAnnexureFilters({ chequeIds: [id] });
    }

    function backToChequeList() {
        applyAnnexureFilters({ chequeIds: [] });
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

    const duplicateInvoiceKeys = useMemo(
        () => getDuplicateInvoiceKeys(entries, 'branch-invoice'),
        [entries],
    );

    const hasDuplicateInvoices = duplicateInvoiceKeys.size > 0;

    const entryBranchIds = useMemo(
        () =>
            Array.from(
                new Set(
                    entries
                        .map((entry) => entry.branch_id)
                        .filter((id): id is number => id !== null),
                ),
            ),
        [entries],
    );

    const entryBranches = useMemo(
        () =>
            sortBranchesByCode(
                branches.filter((branch) => entryBranchIds.includes(branch.id)),
            ),
        [branches, entryBranchIds],
    );

    const branchFilterActive =
        selectedBranchFilterIds.length > 0 &&
        selectedBranchFilterIds.length < entryBranchIds.length;

    const filteredEntries = useMemo(() => {
        const query = search.trim().toLowerCase();
        const activeBranchIds = branchFilterActive
            ? selectedBranchFilterIds
            : null;

        return entries.filter((entry) => {
            const isDuplicate = isDuplicateInvoice(
                entry,
                duplicateInvoiceKeys,
                'branch-invoice',
            );

            const matchesSearch =
                query === '' ||
                entry.invoice_no.toLowerCase().includes(query) ||
                (entry.branch_code?.toLowerCase().includes(query) ?? false) ||
                entry.transaction_date.includes(query) ||
                entry.amount.includes(query) ||
                (entry.branch_amount?.includes(query) ?? false) ||
                (entry.difference_amount?.includes(query) ?? false) ||
                (entry.cheque_number?.toLowerCase().includes(query) ?? false);

            const matchesFilter =
                filter === 'all' ||
                (filter === 'resolved' && entry.is_resolved) ||
                (filter === 'unresolved' &&
                    !entry.is_resolved &&
                    !(entry.no_branch_expected ?? false)) ||
                (filter === 'supplier' &&
                    (entry.no_branch_expected ?? false)) ||
                (filter === 'mismatches' && entry.has_difference) ||
                (filter === 'duplicates' && isDuplicate) ||
                (filter === 'unique' && !isDuplicate);

            const matchesBranch =
                activeBranchIds === null ||
                (entry.branch_id !== null &&
                    activeBranchIds.includes(entry.branch_id));

            return matchesSearch && matchesFilter && matchesBranch;
        });
    }, [
        branchFilterActive,
        duplicateInvoiceKeys,
        entries,
        filter,
        search,
        selectedBranchFilterIds,
    ]);

    const sortedEntries = useMemo(() => {
        return [...filteredEntries].sort((left, right) => {
            let result = 0;

            switch (sortColumn) {
                case 'transaction_date':
                    result = compareStrings(
                        left.transaction_date,
                        right.transaction_date,
                    );
                    break;
                case 'branch_code':
                    result = compareStrings(
                        left.branch_code ?? '',
                        right.branch_code ?? '',
                    );
                    break;
                case 'invoice_no':
                    result = compareStrings(
                        left.invoice_no,
                        right.invoice_no,
                    );
                    break;
                case 'amount':
                    result = compareNumbers(
                        left.amount_value ?? parseAmount(left.amount),
                        right.amount_value ?? parseAmount(right.amount),
                    );
                    break;
                case 'branch_amount':
                    result = compareNumbers(
                        left.branch_amount_value ?? 0,
                        right.branch_amount_value ?? 0,
                    );
                    break;
                case 'difference_amount':
                    result = compareNumbers(
                        left.difference_amount_value ?? 0,
                        right.difference_amount_value ?? 0,
                    );
                    break;
            }

            return sortDirection === 'asc' ? result : -result;
        });
    }, [filteredEntries, sortColumn, sortDirection]);

    const hasActiveFilters =
        search.trim() !== '' || filter !== 'all' || branchFilterActive;

    const displayedUnresolvedCount = useMemo(
        () =>
            entries.filter(
                (entry) =>
                    !entry.is_resolved &&
                    !(entry.no_branch_expected ?? false),
            ).length,
        [entries],
    );

    const filteredClientTotal = useMemo(
        () =>
            filteredEntries.reduce(
                (sum, entry) =>
                    sum + (entry.amount_value ?? parseAmount(entry.amount)),
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

    const footerClientTotal = hasActiveFilters
        ? formatAmount(filteredClientTotal)
        : clientTotal;
    const footerBranchTotal = hasActiveFilters
        ? formatAmount(filteredBranchTotal)
        : branchTotal;
    const footerDifferenceTotal = hasActiveFilters
        ? formatAmount(filteredDifferenceTotal)
        : differenceTotal;

    const canEditEntries =
        !multiChequeView &&
        (phase === 'review' || (phase === 'complete' && chequeId !== null));
    const showPaymentForm = phase === 'payment';
    const showChequeList =
        phase === 'complete' && selectedChequeIds.length === 0;
    const showStatementTable =
        phase === 'review' ||
        phase === 'payment' ||
        (phase === 'complete' && selectedChequeIds.length > 0);

    const savedChequeTotals = useMemo(() => {
        return {
            checkTotal: cheques.reduce(
                (sum, cheque) => sum + cheque.amount_value,
                0,
            ),
            rebate: cheques.reduce(
                (sum, cheque) => sum + cheque.rebate_value,
                0,
            ),
            net: cheques.reduce(
                (sum, cheque) => sum + cheque.net_amount_value,
                0,
            ),
        };
    }, [cheques]);

    const activeCheque = chequeId
        ? cheques.find((cheque) => cheque.id === chequeId)
        : null;

    return (
        <>
            <Head title={`Client Annexure - ${client.name}`} />

            {phase !== 'complete' && (
                <div className="mb-6 flex flex-wrap items-center gap-2">
                    {STEPS.map((step, index) => (
                        <div key={step.key} className="flex items-center gap-2">
                            <div
                                className={cn(
                                    'flex size-8 items-center justify-center rounded-full text-sm font-medium',
                                    index <= currentStep
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {index + 1}
                            </div>
                            <span
                                className={cn(
                                    'text-sm',
                                    index <= currentStep
                                        ? 'font-medium text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {step.label}
                            </span>
                            {index < STEPS.length - 1 && (
                                <div className="mx-1 hidden h-px w-8 bg-border sm:block" />
                            )}
                        </div>
                    ))}
                </div>
            )}

            <Card>
                <CardHeader>
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <CardTitle>
                                {phase === 'complete' &&
                                selectedChequeIds.length > 0
                                    ? multiChequeView
                                        ? `Annexure statement — ${selectedChequeIds.length} cheques`
                                        : `Annexure statement — Cheque ${activeCheque?.check_number ?? checkNumber}`
                                    : 'Client Annexure'}
                            </CardTitle>
                            <CardDescription>
                                {phase === 'complete' &&
                                selectedChequeIds.length > 0
                                    ? `${periodLabel} · ${
                                          hasActiveFilters
                                              ? `${filteredEntries.length} of ${entries.length} entries`
                                              : `${entries.length} entries`
                                      } · ${displayedUnresolvedCount} unresolved · ${mismatchCount} mismatches${
                                          hasDuplicateInvoices
                                              ? ' · Duplicate invoices highlighted'
                                              : ''
                                      }`
                                    : `${periodLabel}${
                                          entries.length > 0 && !showChequeList
                                              ? ` · ${
                                                    hasActiveFilters
                                                        ? `${filteredEntries.length} of ${entries.length} entries`
                                                        : `${entries.length} entries`
                                                } · ${displayedUnresolvedCount} unresolved · ${mismatchCount} mismatches${
                                                    hasDuplicateInvoices
                                                        ? ' · Duplicate invoices highlighted'
                                                        : ''
                                                }`
                                              : ''
                                      }`}
                            </CardDescription>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <PeriodFilterMenu
                                availableMonths={availableMonths}
                                selectedPeriods={selectedPeriods}
                                onChange={(periods) =>
                                    applyAnnexureFilters({
                                        periods,
                                        chequeIds: [],
                                    })
                                }
                            />
                            {cheques.length > 0 && (
                                <OptionFilterMenu
                                    options={chequeFilterOptions}
                                    selectedValues={selectedChequeIds.map(String)}
                                    onChange={(values) =>
                                        applyAnnexureFilters({
                                            chequeIds: values.map(Number),
                                        })
                                    }
                                    emptyLabel="All cheques"
                                />
                            )}
                            {phase !== 'upload' && (
                                <>
                                    <Button variant="outline" asChild>
                                        <a
                                            href={pdf.url(client.id, {
                                                query: buildAnnexureQuery(
                                                    selectedPeriods,
                                                    selectedChequeIds,
                                                ),
                                            })}
                                        >
                                            <Download className="size-4" />
                                            PDF
                                        </a>
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <a
                                            href={excel.url(client.id, {
                                                query: buildAnnexureQuery(
                                                    selectedPeriods,
                                                    selectedChequeIds,
                                                ),
                                            })}
                                        >
                                            <FileSpreadsheet className="size-4" />
                                            Excel
                                        </a>
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                </CardHeader>

                <CardContent className="space-y-6">
                    {phase === 'upload' && (
                        <div className="rounded-lg border border-dashed py-12 text-center">
                            <p className="font-medium">
                                No annexure data for {periodLabel}
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Upload an Excel file or add rows manually with
                                Invoice Date, Invoice No, and Amount.
                            </p>
                            <div className="mt-4 flex flex-wrap justify-center gap-2">
                                <Button asChild>
                                    <Link
                                        href={annexureImport.url(client.id, {
                                            query: {
                                                year: primaryPeriod.year,
                                                month: primaryPeriod.month,
                                            },
                                        })}
                                    >
                                        <Upload className="size-4" />
                                        Upload annexure
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={openMultipleEntryDialog}
                                >
                                    <Table className="size-4" />
                                    Add entries
                                </Button>
                            </div>
                        </div>
                    )}

                    {phase !== 'upload' && (
                        <>
                            {(phase === 'review' || phase === 'payment') && (
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <p className="text-sm text-muted-foreground">
                                            {hasActiveFilters
                                                ? 'Filtered client total'
                                                : 'Client Total'}
                                        </p>
                                        <p className="mt-1 font-mono text-lg font-semibold">
                                            {footerClientTotal}
                                        </p>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <p className="text-sm text-muted-foreground">
                                            {hasActiveFilters
                                                ? 'Filtered branch total'
                                                : 'Branch Total'}
                                        </p>
                                        <p className="mt-1 font-mono text-lg font-semibold">
                                            {footerBranchTotal}
                                        </p>
                                    </div>
                                    <div className="rounded-lg border bg-muted/30 p-4">
                                        <p className="text-sm text-muted-foreground">
                                            {hasActiveFilters
                                                ? 'Filtered difference'
                                                : 'Difference'}
                                        </p>
                                        <p
                                            className="mt-1 font-mono text-lg font-semibold"
                                            title="Sum of row differences (matched invoices only)"
                                        >
                                            {footerDifferenceTotal}
                                        </p>
                                    </div>
                                </div>
                            )}

                            {showChequeList && (
                                <>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <h3 className="font-medium">
                                                Cheques — {periodLabel}
                                            </h3>
                                            <p className="text-sm text-muted-foreground">
                                                Select a cheque to view the
                                                annexure statement.
                                            </p>
                                        </div>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                applyAnnexureFilters({
                                                    chequeIds: cheques.map(
                                                        (cheque) => cheque.id,
                                                    ),
                                                })
                                            }
                                        >
                                            View all cheques
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
                                                href={annexureImport.url(
                                                    client.id,
                                                    {
                                                        query: {
                                                year: primaryPeriod.year,
                                                month: primaryPeriod.month,
                                            },
                                                    },
                                                )}
                                            >
                                                <Plus className="size-4" />
                                                Add cheque
                                            </Link>
                                        </Button>
                                    </div>

                                    {cheques.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            No cheques recorded for this month.
                                        </p>
                                    ) : (
                                        <AppTableScroll className="rounded-lg border">
                                            <AppTable className="w-full md:min-w-[600px]">
                                                <thead>
                                                    <tr className="border-b text-left text-muted-foreground">
                                                        <AppTableHeadCell className="px-4 py-3 font-medium">
                                                            Cheque No
                                                        </AppTableHeadCell>
                                                        <AppTableHeadCell className="px-4 py-3 text-right font-medium">
                                                            Total Amount
                                                        </AppTableHeadCell>
                                                        <AppTableHeadCell className="px-4 py-3 text-right font-medium">
                                                            Rebate
                                                        </AppTableHeadCell>
                                                        <AppTableHeadCell className="px-4 py-3 text-right font-medium">
                                                            Net
                                                        </AppTableHeadCell>
                                                        <AppTableHeadCell className="px-4 py-3 text-right font-medium">
                                                            Actions
                                                        </AppTableHeadCell>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {cheques.map((cheque) => (
                                                        <tr
                                                            key={cheque.id}
                                                            className="border-b"
                                                        >
                                                            <AppTableBodyCell
                                                                label="Cheque No"
                                                                mobile="primary"
                                                                className="cursor-pointer px-4 py-3 font-medium hover:underline"
                                                                onClick={() =>
                                                                    cheque.payment_saved
                                                                        ? openCheque(
                                                                              cheque.id,
                                                                          )
                                                                        : openCheque(
                                                                              cheque.id,
                                                                          )
                                                                }
                                                            >
                                                                {
                                                                    cheque.check_number
                                                                }
                                                                {!cheque.payment_saved && (
                                                                    <span className="ml-2 text-xs text-amber-600">
                                                                        In progress
                                                                    </span>
                                                                )}
                                                            </AppTableBodyCell>
                                                            <AppTableBodyCell
                                                                label="Total Amount"
                                                                className="cursor-pointer px-4 py-3 text-right font-mono"
                                                                onClick={() =>
                                                                    openCheque(
                                                                        cheque.id,
                                                                    )
                                                                }
                                                            >
                                                                {
                                                                    cheque.amount
                                                                }
                                                            </AppTableBodyCell>
                                                            <AppTableBodyCell
                                                                label="Rebate"
                                                                className="cursor-pointer px-4 py-3 text-right font-mono"
                                                                onClick={() =>
                                                                    openCheque(
                                                                        cheque.id,
                                                                    )
                                                                }
                                                            >
                                                                {cheque.rebate}
                                                            </AppTableBodyCell>
                                                            <AppTableBodyCell
                                                                label="Net"
                                                                className="cursor-pointer px-4 py-3 text-right font-mono"
                                                                onClick={() =>
                                                                    openCheque(
                                                                        cheque.id,
                                                                    )
                                                                }
                                                            >
                                                                {
                                                                    cheque.net_amount
                                                                }
                                                            </AppTableBodyCell>
                                                            <AppTableBodyCell
                                                                label="Actions"
                                                                mobile="actions"
                                                                className="px-4 py-3 text-right"
                                                            >
                                                                <div className="flex justify-end gap-1">
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        onClick={(
                                                                            event,
                                                                        ) => {
                                                                            event.stopPropagation();
                                                                            setEditingCheque(
                                                                                cheque,
                                                                            );
                                                                        }}
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
                                                                            onClick={(
                                                                                event,
                                                                            ) =>
                                                                                event.stopPropagation()
                                                                            }
                                                                        >
                                                                            <Trash2 className="size-4 text-destructive" />
                                                                        </Button>
                                                                    </DialogTrigger>
                                                                    <DialogContent>
                                                                        <DialogTitle>
                                                                            Delete
                                                                            cheque?
                                                                        </DialogTitle>
                                                                        <DialogDescription>
                                                                            This
                                                                            will
                                                                            permanently
                                                                            delete
                                                                            cheque{' '}
                                                                            {
                                                                                cheque.check_number
                                                                            }{' '}
                                                                            and
                                                                            all
                                                                            annexure
                                                                            entries
                                                                            for
                                                                            it.
                                                                        </DialogDescription>
                                                                        <Form
                                                                            {...ClientAnnexureChequeController.destroy.form(
                                                                                cheque.id,
                                                                            )}
                                                                            options={{
                                                                                preserveScroll: true,
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
                                                                                    <DialogFooter className="gap-2">
                                                                                        <Button
                                                                                            type="submit"
                                                                                            variant="destructive"
                                                                                            disabled={
                                                                                                processing
                                                                                            }
                                                                                        >
                                                                                            Delete
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
                                                    ))}
                                                </tbody>
                                                <tfoot>
                                                    <tr className="bg-muted/20 font-semibold">
                                                        <td className="px-4 py-3">
                                                            Total
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono">
                                                            {formatAmount(
                                                                savedChequeTotals.checkTotal,
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono">
                                                            {formatAmount(
                                                                savedChequeTotals.rebate,
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3 text-right font-mono">
                                                            {formatAmount(
                                                                savedChequeTotals.net,
                                                            )}
                                                        </td>
                                                        <td />
                                                    </tr>
                                                </tfoot>
                                            </AppTable>
                                        </AppTableScroll>
                                    )}
                                </>
                            )}

                            {phase === 'complete' &&
                                selectedChequeIds.length > 0 && (
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <Button
                                        variant="outline"
                                        onClick={backToChequeList}
                                    >
                                        <ChevronLeft className="size-4" />
                                        Back to cheques
                                    </Button>
                                    <div className="flex flex-wrap gap-2">
                                        {activeCheque?.payment_saved && (
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    activeCheque &&
                                                    setEditingCheque(
                                                        activeCheque,
                                                    )
                                                }
                                            >
                                                <Pencil className="size-4" />
                                                Edit cheque
                                            </Button>
                                        )}
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={annexureImport.url(
                                                    client.id,
                                                    {
                                                        query: {
                                                year: primaryPeriod.year,
                                                month: primaryPeriod.month,
                                            },
                                                    },
                                                )}
                                            >
                                                <Plus className="size-4" />
                                                Add cheque
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {phase === 'review' && chequeId !== null && (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/30">
                                    <p className="text-sm">
                                        Review imported data. Edit or delete rows
                                        if needed, then continue to enter cheque
                                        number and rebate.
                                    </p>
                                    <Form
                                        {...ClientAnnexureChequeController.completeReview.form(
                                            chequeId,
                                        )}
                                        options={{ preserveScroll: true }}
                                    >
                                        <Button type="submit">
                                            Complete review
                                        </Button>
                                    </Form>
                                </div>
                            )}

                            {showPaymentForm && chequeId !== null && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Cheque number & rebate
                                        </CardTitle>
                                        <CardDescription>
                                            Enter the cheque number and rebate
                                            amount, then save.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <Form
                                            {...ClientAnnexureChequeController.update.form(
                                                chequeId,
                                            )}
                                            options={{ preserveScroll: true }}
                                            className="space-y-4"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <div className="flex flex-wrap items-end gap-3">
                                                        <div className="grid min-w-[140px] flex-1 gap-2">
                                                            <Label>
                                                                Cheque number
                                                            </Label>
                                                            <Input
                                                                name="check_number"
                                                                value={
                                                                    checkNumberInput
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setCheckNumberInput(
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    )
                                                                }
                                                                placeholder="e.g. 051939"
                                                                required
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors.check_number
                                                                }
                                                            />
                                                        </div>
                                                        <div className="grid min-w-[140px] flex-1 gap-2">
                                                            <Label>
                                                                Check amount
                                                            </Label>
                                                            <Input
                                                                name="amount"
                                                                type="number"
                                                                step="0.001"
                                                                value={
                                                                    checkAmountInput
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setCheckAmountInput(
                                                                        event
                                                                            .target
                                                                            .value,
                                                                    )
                                                                }
                                                                required
                                                            />
                                                            <InputError
                                                                message={
                                                                    errors.amount
                                                                }
                                                            />
                                                        </div>
                                                    </div>

                                                    <div className="grid max-w-xs gap-2">
                                                        <Label htmlFor="rebate">
                                                            Rebate amount
                                                        </Label>
                                                        <Input
                                                            id="rebate"
                                                            name="rebate"
                                                            type="number"
                                                            step="0.001"
                                                            value={rebateInput}
                                                            onChange={(event) =>
                                                                setRebateInput(
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            required
                                                        />
                                                        <InputError
                                                            message={errors.rebate}
                                                        />
                                                    </div>

                                                    {activeCheque && (
                                                        <ChequePeriodFields
                                                            chequeDate={
                                                                activeCheque.cheque_date
                                                            }
                                                            year={activeCheque.year}
                                                            month={
                                                                activeCheque.month
                                                            }
                                                            errors={errors}
                                                            chequeDateId="payment-cheque-date"
                                                            yearId="payment-cheque-year"
                                                            monthId="payment-cheque-month"
                                                        />
                                                    )}

                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        {processing
                                                            ? 'Saving...'
                                                            : 'Save cheque'}
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    </CardContent>
                                </Card>
                            )}

                            {showStatementTable && (
                                <>
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <div className="relative w-full sm:max-w-sm">
                                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            value={search}
                                            onChange={(event) =>
                                                setSearch(event.target.value)
                                            }
                                            placeholder="Search date, branch, invoice, cheque, amount, difference..."
                                            className="pl-9"
                                        />
                                    </div>
                                    {entryBranches.length > 0 && (
                                        <BranchFilterMenu
                                            branches={entryBranches}
                                            branchIds={
                                                selectedBranchFilterIds.length >
                                                0
                                                    ? selectedBranchFilterIds
                                                    : entryBranchIds
                                            }
                                            onChange={
                                                setSelectedBranchFilterIds
                                            }
                                        />
                                    )}
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
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
                                    {hasActiveFilters && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setSearch('');
                                                setFilter('all');
                                                setSelectedBranchFilterIds([]);
                                            }}
                                        >
                                            Clear
                                        </Button>
                                    )}
                                    {phase !== 'complete' && (
                                        <Button variant="outline" asChild>
                                            <Link href={annexureImport(client.id)}>
                                                <Upload className="size-4" />
                                                Re-upload
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </div>

                            <ReconciliationLegend
                                compact
                                statuses={COMPARISON_LEGEND_STATUSES}
                            />

                            {sortedEntries.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No entries match your search or filters.
                                </p>
                            ) : (
                                <AppTableScroll>
                                    <AppTable className="w-full md:min-w-[760px] md:text-center">
                                        <thead>
                                            <tr className="border-b text-muted-foreground">
                                                <AppTableHeadCell className="w-12 px-4 py-2 font-medium">
                                                    SI
                                                </AppTableHeadCell>
                                                {multiChequeView && (
                                                    <AppTableHeadCell className="px-4 py-2 font-medium">
                                                        Cheque
                                                    </AppTableHeadCell>
                                                )}
                                                <SortableTableHead
                                                    label="Invoice date"
                                                    column="transaction_date"
                                                    activeColumn={sortColumn}
                                                    direction={sortDirection}
                                                    onSort={handleSort}
                                                    align="center"
                                                    className="pb-2"
                                                />
                                                <SortableTableHead
                                                    label="Branch ID"
                                                    column="branch_code"
                                                    activeColumn={sortColumn}
                                                    direction={sortDirection}
                                                    onSort={handleSort}
                                                    align="center"
                                                    className="pb-2"
                                                />
                                                <SortableTableHead
                                                    label="Invoice"
                                                    column="invoice_no"
                                                    activeColumn={sortColumn}
                                                    direction={sortDirection}
                                                    onSort={handleSort}
                                                    align="center"
                                                    className="pb-2"
                                                />
                                                <SortableTableHead
                                                    label="Client Amount"
                                                    column="amount"
                                                    activeColumn={sortColumn}
                                                    direction={sortDirection}
                                                    onSort={handleSort}
                                                    align="center"
                                                    className="pb-2"
                                                />
                                                <SortableTableHead
                                                    label="Branch Amount"
                                                    column="branch_amount"
                                                    activeColumn={sortColumn}
                                                    direction={sortDirection}
                                                    onSort={handleSort}
                                                    align="center"
                                                    className="pb-2"
                                                />
                                                <SortableTableHead
                                                    label="Difference"
                                                    column="difference_amount"
                                                    activeColumn={sortColumn}
                                                    direction={sortDirection}
                                                    onSort={handleSort}
                                                    align="center"
                                                    className="pb-2"
                                                />
                                                {canEditEntries && (
                                                    <AppTableHeadCell className="px-4 py-2 font-medium">
                                                        Actions
                                                    </AppTableHeadCell>
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {sortedEntries.map((entry, index) => {
                                                const isDuplicate =
                                                    isDuplicateInvoice(
                                                        entry,
                                                        duplicateInvoiceKeys,
                                                        'branch-invoice',
                                                    );
                                                const differenceValue =
                                                    entry.difference_amount_value ??
                                                    null;
                                                const noBranchExpectedFlag =
                                                    entry.no_branch_expected ??
                                                    false;
                                                const rowStatus =
                                                    resolveReconciliationRowStatus(
                                                        {
                                                            isDuplicate,
                                                            isResolved:
                                                                entry.is_resolved,
                                                            noBranchExpected:
                                                                noBranchExpectedFlag,
                                                            differenceValue,
                                                        },
                                                    );

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
                                                        label="SI"
                                                        mobile="skip"
                                                        className="px-4 py-2 text-muted-foreground"
                                                    >
                                                        {index + 1}
                                                    </AppTableBodyCell>
                                                    {multiChequeView && (
                                                        <AppTableBodyCell
                                                            label="Cheque"
                                                            className="px-4 py-2 font-mono"
                                                        >
                                                            {entry.cheque_number ??
                                                                '—'}
                                                        </AppTableBodyCell>
                                                    )}
                                                    <AppTableBodyCell
                                                        label="Invoice date"
                                                        className="px-4 py-2"
                                                    >
                                                        {
                                                            entry.transaction_date
                                                        }
                                                    </AppTableBodyCell>
                                                    <AppTableBodyCell
                                                        label="Branch ID"
                                                        className={cn(
                                                            'px-4 py-2',
                                                            !entry.is_resolved &&
                                                                !noBranchExpectedFlag &&
                                                                'font-semibold text-amber-700 dark:text-amber-400',
                                                        )}
                                                    >
                                                        {entry.branch_code ??
                                                            '—'}
                                                    </AppTableBodyCell>
                                                    <AppTableBodyCell
                                                        label="Invoice"
                                                        mobile="primary"
                                                        className="px-4 py-2"
                                                    >
                                                        <InvoiceNoLink
                                                            clientId={client.id}
                                                            invoiceNo={
                                                                entry.invoice_no
                                                            }
                                                            className={cn(
                                                                'inline-block',
                                                                reconciliationInvoiceTextClassName(
                                                                    rowStatus,
                                                                    isDuplicate,
                                                                ),
                                                            )}
                                                        />
                                                    </AppTableBodyCell>
                                                    <AppTableBodyCell
                                                        label="Client Amount"
                                                        className="px-4 py-2 font-mono tabular-nums"
                                                    >
                                                        {entry.amount}
                                                    </AppTableBodyCell>
                                                    <AppTableBodyCell
                                                        label="Branch Amount"
                                                        className="px-4 py-2 font-mono tabular-nums"
                                                    >
                                                        {entry.branch_amount ??
                                                            '—'}
                                                    </AppTableBodyCell>
                                                    <AppTableBodyCell
                                                        label="Difference"
                                                        className={cn(
                                                            'px-4 py-2 font-mono tabular-nums',
                                                            reconciliationDiffTextClassName(
                                                                differenceValue,
                                                                rowStatus,
                                                            ),
                                                        )}
                                                    >
                                                        {entry.difference_amount ??
                                                            '—'}
                                                    </AppTableBodyCell>
                                                    {canEditEntries && (
                                                        <AppTableBodyCell
                                                            label="Actions"
                                                            mobile="actions"
                                                            className="px-4 py-2"
                                                        >
                                                            <div className="flex flex-nowrap justify-center gap-1">
                                                                {!entry.is_resolved &&
                                                                    !noBranchExpectedFlag && (
                                                                        <Button
                                                                            type="button"
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
                                                                        type="button"
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
                                                                            Delete
                                                                            entry?
                                                                        </DialogTitle>
                                                                        <DialogDescription>
                                                                            Remove
                                                                            invoice{' '}
                                                                            {
                                                                                entry.invoice_no
                                                                            }{' '}
                                                                            from
                                                                            this
                                                                            month&apos;s
                                                                            annexure.
                                                                        </DialogDescription>
                                                                        <Form
                                                                            {...ClientAnnexureEntryController.destroy.form(
                                                                                entry.id,
                                                                            )}
                                                                            options={{
                                                                                preserveScroll: true,
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
                                                                                    <DialogFooter className="gap-2">
                                                                                        <Button
                                                                                            type="submit"
                                                                                            variant="destructive"
                                                                                            disabled={
                                                                                                processing
                                                                                            }
                                                                                        >
                                                                                            Delete
                                                                                        </Button>
                                                                                    </DialogFooter>
                                                                                </>
                                                                            )}
                                                                        </Form>
                                                                    </DialogContent>
                                                                </Dialog>
                                                            </div>
                                                        </AppTableBodyCell>
                                                    )}
                                                </tr>
                                                );
                                            })}
                                        </tbody>
                                        <tfoot>
                                            <tr className="font-semibold">
                                                <td
                                                    colSpan={
                                                        multiChequeView ? 5 : 4
                                                    }
                                                    className="px-4 pt-3 text-left"
                                                >
                                                    {hasActiveFilters
                                                        ? 'Filtered total'
                                                        : 'Total'}
                                                </td>
                                                <td className="px-4 pt-3 font-mono tabular-nums">
                                                    {footerClientTotal}
                                                </td>
                                                <td className="px-4 pt-3 font-mono tabular-nums">
                                                    {footerBranchTotal}
                                                </td>
                                                <td
                                                    className="px-4 pt-3 font-mono tabular-nums"
                                                    title="Sum of row differences (matched invoices only)"
                                                >
                                                    {footerDifferenceTotal}
                                                </td>
                                                {canEditEntries && <td />}
                                            </tr>
                                        </tfoot>
                                    </AppTable>
                                </AppTableScroll>
                            )}
                                </>
                            )}
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
                    <DialogTitle>Edit annexure entry</DialogTitle>
                    {editingEntry && (
                        <Form
                            {...ClientAnnexureEntryController.update.form(
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
                                            Branch ID
                                        </Label>
                                        <select
                                            id="edit-branch-id"
                                            name="branch_id"
                                            defaultValue={
                                                editingEntry.branch_id
                                                    ? String(
                                                          editingEntry.branch_id,
                                                      )
                                                    : ''
                                            }
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        >
                                            <option value="">Auto lookup</option>
                                            {branches.map((branch) => (
                                                <option
                                                    key={branch.id}
                                                    value={branch.id}
                                                >
                                                    {branch.code} — {branch.name}
                                                </option>
                                            ))}
                                        </select>
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
                    <DialogTitle>Add annexure entries</DialogTitle>
                    <DialogDescription>
                        Enter rows like a spreadsheet for {periodLabel}. Paste
                        invoice date, invoice no, and amount. A new cheque batch
                        will be created for review.
                    </DialogDescription>
                    {bulkHasDateIssues && (
                        <p className="text-sm font-medium text-destructive">
                            {`Invalid date format on row${bulkRowValidation.invalidRowNumbers.length === 1 ? '' : 's'} ${bulkRowValidation.invalidRowNumbers.join(', ')}. Use dd/mm/yyyy and make sure invoice numbers are not in the date column.`}
                        </p>
                    )}

                    <Form
                        {...ClientAnnexureEntryController.bulkStore.form(
                            client.id,
                        )}
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

            <Dialog
                open={editingCheque !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditingCheque(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogTitle>Edit cheque</DialogTitle>
                    <DialogDescription>
                        Update the cheque number, date, cheque month, total
                        amount, and rebate.
                    </DialogDescription>
                    {editingCheque && (
                        <Form
                            {...ClientAnnexureChequeController.update.form(
                                editingCheque.id,
                            )}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                            onSuccess={() => setEditingCheque(null)}
                        >
                            {({ processing, errors }) => (
                                <>
                                    {chequeId !== null && (
                                        <input
                                            type="hidden"
                                            name="stay_on_cheque"
                                            value="1"
                                        />
                                    )}
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-cheque-number">
                                            Cheque number
                                        </Label>
                                        <Input
                                            id="edit-cheque-number"
                                            name="check_number"
                                            defaultValue={
                                                editingCheque.check_number ===
                                                '—'
                                                    ? ''
                                                    : editingCheque.check_number
                                            }
                                            placeholder="e.g. 078160"
                                            required
                                        />
                                        <InputError
                                            message={errors.check_number}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-cheque-amount">
                                            Total amount
                                        </Label>
                                        <Input
                                            id="edit-cheque-amount"
                                            name="amount"
                                            type="number"
                                            step="0.001"
                                            defaultValue={
                                                editingCheque.amount_value
                                            }
                                            required
                                        />
                                        <InputError message={errors.amount} />
                                    </div>
                                    <ChequePeriodFields
                                        chequeDate={editingCheque.cheque_date}
                                        year={editingCheque.year}
                                        month={editingCheque.month}
                                        errors={errors}
                                        chequeDateId="edit-cheque-date"
                                        yearId="edit-cheque-year"
                                        monthId="edit-cheque-month"
                                    />
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-cheque-rebate">
                                            Rebate
                                        </Label>
                                        <Input
                                            id="edit-cheque-rebate"
                                            name="rebate"
                                            type="number"
                                            step="0.001"
                                            defaultValue={
                                                editingCheque.rebate_value
                                            }
                                            required
                                        />
                                        <InputError message={errors.rebate} />
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Net amount:{' '}
                                        {formatAmount(
                                            editingCheque.client_total_value -
                                                editingCheque.rebate_value,
                                        )}{' '}
                                        (client total − rebate)
                                    </p>
                                    <DialogFooter className="gap-2">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() =>
                                                setEditingCheque(null)
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
        </>
    );
}

ClientsAnnexureIndex.layout = () => ({
    breadcrumbs: [{ title: 'Client Annexure', href: '#' }],
});
