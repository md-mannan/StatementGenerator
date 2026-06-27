import { Form, Head, Link } from '@inertiajs/react';
import {
    FileSpreadsheet,
    Plus,
    Search,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import BranchController from '@/actions/App/Http/Controllers/BranchController';
import InputError from '@/components/input-error';
import { SortableTableHead } from '@/components/sortable-table-head';
import {
    AppTable,
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
import { index, show } from '@/routes/clients';
import { index as statementsIndex } from '@/routes/branches/statements';
import { importMethod as statementsImport } from '@/routes/branches/statements';
import {
    compareBooleans,
    compareNumbers,
    compareStrings,
    parseDdMmYyyyHhMm,
    toggleSortDirection,
    type SortDirection,
} from '@/lib/table-sort';
import type { Branch, BranchMonthStat, Client, ClientSummary } from '@/types';

type BranchFilter = 'all' | 'with-data' | 'empty';
type BranchMonthRow = {
    key: string;
    branch: Branch;
    stat: BranchMonthStat | null;
};
type BranchSortColumn =
    | 'code'
    | 'name'
    | 'month'
    | 'entries'
    | 'total_amount'
    | 'last_uploaded_at'
    | 'status';

function formatAmount(value: number | string): string {
    return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
}

export default function ClientsShow({
    client,
    branchMonthStats,
}: {
    client: Client;
    summary: ClientSummary;
    branchMonthStats: BranchMonthStat[];
}) {
    const [editingBranch, setEditingBranch] = useState<Branch | null>(null);
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<BranchFilter>('all');
    const [monthFilter, setMonthFilter] = useState('all');
    const [sortColumn, setSortColumn] = useState<BranchSortColumn>('month');
    const [sortDirection, setSortDirection] =
        useState<SortDirection>('desc');

    const branches = client.branches ?? [];

    const branchMonthRows = useMemo((): BranchMonthRow[] => {
        const statsByBranch = new Map<number, BranchMonthStat[]>();

        branchMonthStats.forEach((stat) => {
            const existing = statsByBranch.get(stat.branch_id) ?? [];
            existing.push(stat);
            statsByBranch.set(stat.branch_id, existing);
        });

        const rows: BranchMonthRow[] = [];

        branches.forEach((branch) => {
            const stats = (statsByBranch.get(branch.id) ?? []).sort(
                (left, right) => {
                    if (left.year !== right.year) {
                        return right.year - left.year;
                    }

                    return right.month - left.month;
                },
            );

            if (stats.length === 0) {
                rows.push({
                    key: `${branch.id}-empty`,
                    branch,
                    stat: null,
                });

                return;
            }

            stats.forEach((stat) => {
                rows.push({
                    key: `${branch.id}-${stat.year}-${stat.month}`,
                    branch,
                    stat,
                });
            });
        });

        return rows;
    }, [branchMonthStats, branches]);

    const availableMonths = useMemo(() => {
        const periods = new Map<
            string,
            { year: number; month: number; label: string }
        >();

        branchMonthStats.forEach((stat) => {
            const key = `${stat.year}-${stat.month}`;

            if (!periods.has(key)) {
                periods.set(key, {
                    year: stat.year,
                    month: stat.month,
                    label: stat.label,
                });
            }
        });

        return Array.from(periods.values()).sort((left, right) => {
            if (left.year !== right.year) {
                return right.year - left.year;
            }

            return right.month - left.month;
        });
    }, [branchMonthStats]);

    function handleSort(column: BranchSortColumn) {
        setSortDirection((currentDirection) =>
            toggleSortDirection(sortColumn, column, currentDirection),
        );
        setSortColumn(column);
    }

    const displayedBranches = useMemo(() => {
        const query = search.trim().toLowerCase();

        const filtered = branchMonthRows.filter((row) => {
            const { branch, stat } = row;
            const matchesSearch =
                query === '' ||
                branch.code.toLowerCase().includes(query) ||
                branch.name.toLowerCase().includes(query) ||
                (stat?.label.toLowerCase().includes(query) ?? false);

            const matchesFilter =
                filter === 'all' ||
                (filter === 'with-data' && stat !== null) ||
                (filter === 'empty' && stat === null);

            const matchesMonth =
                monthFilter === 'all' ||
                (stat !== null &&
                    `${stat.year}-${stat.month}` === monthFilter);

            return matchesSearch && matchesFilter && matchesMonth;
        });

        return [...filtered].sort((left, right) => {
            const leftPeriod =
                left.stat !== null
                    ? left.stat.year * 100 + left.stat.month
                    : 0;
            const rightPeriod =
                right.stat !== null
                    ? right.stat.year * 100 + right.stat.month
                    : 0;

            switch (sortColumn) {
                case 'code':
                    return compareStrings(
                        left.branch.code,
                        right.branch.code,
                        sortDirection,
                    );
                case 'name':
                    return compareStrings(
                        left.branch.name,
                        right.branch.name,
                        sortDirection,
                    );
                case 'month':
                    return compareNumbers(
                        leftPeriod,
                        rightPeriod,
                        sortDirection,
                    );
                case 'entries':
                    return compareNumbers(
                        left.stat?.entries_count ?? 0,
                        right.stat?.entries_count ?? 0,
                        sortDirection,
                    );
                case 'total_amount':
                    return compareNumbers(
                        left.stat?.total_amount_value ?? 0,
                        right.stat?.total_amount_value ?? 0,
                        sortDirection,
                    );
                case 'last_uploaded_at':
                    return compareNumbers(
                        parseDdMmYyyyHhMm(left.stat?.last_uploaded_at ?? ''),
                        parseDdMmYyyyHhMm(right.stat?.last_uploaded_at ?? ''),
                        sortDirection,
                    );
                case 'status':
                    return compareBooleans(
                        left.stat !== null,
                        right.stat !== null,
                        sortDirection,
                    );
                default:
                    return 0;
            }
        });
    }, [
        branchMonthRows,
        filter,
        monthFilter,
        search,
        sortColumn,
        sortDirection,
    ]);

    const hasActiveFilters =
        search.trim() !== '' || filter !== 'all' || monthFilter !== 'all';

    const displayTotals = useMemo(() => {
        return displayedBranches.reduce(
            (totals, row) => ({
                entries:
                    totals.entries + (row.stat?.entries_count ?? 0),
                amount:
                    totals.amount + (row.stat?.total_amount_value ?? 0),
            }),
            { entries: 0, amount: 0 },
        );
    }, [displayedBranches]);

    return (
        <>
            <Head title={client.name} />

            <Card>
                    <CardHeader className="gap-4">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <CardTitle>Branches</CardTitle>
                                <CardDescription>
                                    Each branch shows one row per statement
                                    month
                                </CardDescription>
                            </div>
                            <Dialog>
                                <DialogTrigger asChild>
                                    <Button>
                                        <Plus className="size-4" />
                                        Add branch
                                    </Button>
                                </DialogTrigger>
                                <DialogContent>
                                    <DialogTitle>Add branch</DialogTitle>
                                    <DialogDescription>
                                        Enter a branch ID and name for this
                                        client.
                                    </DialogDescription>
                                    <Form
                                        {...BranchController.store.form(
                                            client.id,
                                        )}
                                        resetOnSuccess
                                        className="space-y-4"
                                    >
                                        {({
                                            processing,
                                            errors,
                                            resetAndClearErrors,
                                        }) => (
                                            <>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="code">
                                                        Branch ID
                                                    </Label>
                                                    <Input
                                                        id="code"
                                                        name="code"
                                                        placeholder="BH001"
                                                        required
                                                    />
                                                    <InputError
                                                        message={errors.code}
                                                    />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="name">
                                                        Branch name
                                                    </Label>
                                                    <Input
                                                        id="name"
                                                        name="name"
                                                        placeholder="Dubai Mall Branch"
                                                        required
                                                    />
                                                    <InputError
                                                        message={errors.name}
                                                    />
                                                </div>
                                                <DialogFooter className="gap-2">
                                                    <DialogClose asChild>
                                                        <Button
                                                            variant="secondary"
                                                            type="button"
                                                            onClick={() =>
                                                                resetAndClearErrors()
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                    </DialogClose>
                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        Create branch
                                                    </Button>
                                                </DialogFooter>
                                            </>
                                        )}
                                    </Form>
                                </DialogContent>
                            </Dialog>
                        </div>

                        {branches.length > 0 && (
                            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                    <div className="relative w-full sm:max-w-xs">
                                        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            value={search}
                                            onChange={(event) =>
                                                setSearch(event.target.value)
                                            }
                                            placeholder="Search branch ID or name..."
                                            className="pl-9"
                                        />
                                    </div>
                                    {availableMonths.length > 0 && (
                                        <Select
                                            value={monthFilter}
                                            onValueChange={setMonthFilter}
                                        >
                                            <SelectTrigger className="w-full sm:w-[180px]">
                                                <SelectValue placeholder="All months" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">
                                                    All months
                                                </SelectItem>
                                                {availableMonths.map((item) => (
                                                    <SelectItem
                                                        key={`${item.year}-${item.month}`}
                                                        value={`${item.year}-${item.month}`}
                                                    >
                                                        {item.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {(
                                        [
                                            ['all', 'All'],
                                            ['with-data', 'With data'],
                                            ['empty', 'No data'],
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
                        )}
                    </CardHeader>
                    <CardContent>
                        {branches.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No branches yet. Add a branch to start uploading
                                statements.
                            </p>
                        ) : displayedBranches.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No branches match your search, month, or
                                filter.
                            </p>
                        ) : (
                            <AppTableScroll className="rounded-lg border">
                                <AppTable>
                                    <thead>
                                        <tr>
                                            <SortableTableHead
                                                label="Branch ID"
                                                column="code"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as BranchSortColumn,
                                                    )
                                                }
                                            />
                                            <SortableTableHead
                                                label="Name"
                                                column="name"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as BranchSortColumn,
                                                    )
                                                }
                                            />
                                            <SortableTableHead
                                                label="Month"
                                                column="month"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as BranchSortColumn,
                                                    )
                                                }
                                            />
                                            <SortableTableHead
                                                label="Entries"
                                                column="entries"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as BranchSortColumn,
                                                    )
                                                }
                                            />
                                            <SortableTableHead
                                                label="Total amount"
                                                column="total_amount"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as BranchSortColumn,
                                                    )
                                                }
                                                align="right"
                                            />
                                            <SortableTableHead
                                                label="Last upload"
                                                column="last_uploaded_at"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as BranchSortColumn,
                                                    )
                                                }
                                            />
                                            <SortableTableHead
                                                label="Status"
                                                column="status"
                                                activeColumn={sortColumn}
                                                direction={sortDirection}
                                                onSort={(column) =>
                                                    handleSort(
                                                        column as BranchSortColumn,
                                                    )
                                                }
                                            />
                                            <AppTableHeadCell className="px-4 py-3 text-right font-medium">
                                                Actions
                                            </AppTableHeadCell>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {displayedBranches.map((row) => {
                                            const { branch, stat } = row;

                                            return (
                                            <tr
                                                key={row.key}
                                                className="border-t"
                                            >
                                                <td className="px-4 py-3 font-mono">
                                                    {branch.code}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {branch.name}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {stat?.label ?? '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {stat?.entries_count ?? 0}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono">
                                                    {stat?.total_amount ??
                                                        '0.000'}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {stat?.last_uploaded_at ??
                                                        '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge
                                                        variant={
                                                            stat !== null
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {stat !== null
                                                            ? 'Active'
                                                            : 'No data'}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild={stat !== null}
                                                            disabled={stat === null}
                                                        >
                                                            {stat !== null ? (
                                                                <Link
                                                                    href={statementsIndex(
                                                                        branch.id,
                                                                        {
                                                                            query: {
                                                                                year: stat.year,
                                                                                month: stat.month,
                                                                            },
                                                                        },
                                                                    )}
                                                                >
                                                                    <FileSpreadsheet className="size-4" />
                                                                    Statement
                                                                </Link>
                                                            ) : (
                                                                <>
                                                                    <FileSpreadsheet className="size-4" />
                                                                    Statement
                                                                </>
                                                            )}
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={statementsImport(
                                                                    branch.id,
                                                                )}
                                                            >
                                                                Upload
                                                            </Link>
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setEditingBranch(
                                                                    branch,
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
                                                                    Delete
                                                                    branch?
                                                                </DialogTitle>
                                                                <DialogDescription>
                                                                    This will
                                                                    delete all
                                                                    statement
                                                                    entries for{' '}
                                                                    {
                                                                        branch.name
                                                                    }
                                                                    .
                                                                </DialogDescription>
                                                                <Form
                                                                    {...BranchController.destroy.form(
                                                                        branch.id,
                                                                    )}
                                                                >
                                                                    {({
                                                                        processing,
                                                                    }) => (
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
                                                                    )}
                                                                </Form>
                                                            </DialogContent>
                                                        </Dialog>
                                                    </div>
                                                </td>
                                            </tr>
                                            );
                                        })}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t bg-muted/40 font-semibold">
                                            <td
                                                colSpan={3}
                                                className="px-4 py-3"
                                            >
                                                {hasActiveFilters
                                                    ? 'Filtered total'
                                                    : 'Total'}
                                            </td>
                                            <td className="px-4 py-3 tabular-nums">
                                                {displayTotals.entries}
                                            </td>
                                            <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                {formatAmount(
                                                    displayTotals.amount,
                                                )}
                                            </td>
                                            <td colSpan={3} />
                                        </tr>
                                    </tfoot>
                                </AppTable>
                            </AppTableScroll>
                        )}
                    </CardContent>
                </Card>

            <Dialog
                open={editingBranch !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditingBranch(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogTitle>Edit branch</DialogTitle>
                    {editingBranch && (
                        <Form
                            {...BranchController.update.form(editingBranch.id)}
                            className="space-y-4"
                            onSuccess={() => setEditingBranch(null)}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-code">
                                            Branch ID
                                        </Label>
                                        <Input
                                            id="edit-code"
                                            name="code"
                                            defaultValue={editingBranch.code}
                                            required
                                        />
                                        <InputError message={errors.code} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="edit-name">
                                            Branch name
                                        </Label>
                                        <Input
                                            id="edit-name"
                                            name="name"
                                            defaultValue={editingBranch.name}
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <DialogFooter className="gap-2">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={() =>
                                                setEditingBranch(null)
                                            }
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save branch
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

ClientsShow.layout = (props: { client: Client }) => ({
    breadcrumbs: [
        { title: 'Clients', href: index() },
        { title: props.client.name, href: show(props.client.id) },
    ],
});
