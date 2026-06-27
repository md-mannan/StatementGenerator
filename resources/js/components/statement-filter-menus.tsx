import { useEffect, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    branchSelectionLabel,
    monthSelectionLabel,
    normalizePeriodSelection,
    periodKey,
    periodSelectionLabel,
    sortBranchesByCode,
    yearSelectionLabel,
} from '@/lib/statement-filters';
import { filterMenuTriggerClassName } from '@/lib/page-layout';
import { cn } from '@/lib/utils';
import type { BranchOption, StatementMonth } from '@/types';

function sameNumberIds(left: number[], right: number[]): boolean {
    if (left.length !== right.length) {
        return false;
    }

    const sortedLeft = [...left].sort((a, b) => a - b);
    const sortedRight = [...right].sort((a, b) => a - b);

    return sortedLeft.every((value, index) => value === sortedRight[index]);
}

function samePeriods(
    left: StatementMonth[],
    right: StatementMonth[],
): boolean {
    if (left.length !== right.length) {
        return false;
    }

    const leftKeys = left.map(periodKey).sort();
    const rightKeys = right.map(periodKey).sort();

    return leftKeys.every((value, index) => value === rightKeys[index]);
}

type BranchFilterMenuProps = {
    branches: BranchOption[];
    branchIds: number[];
    onChange: (branchIds: number[]) => void;
    className?: string;
};

export function BranchFilterMenu({
    branches,
    branchIds,
    onChange,
    className,
}: BranchFilterMenuProps) {
    const [open, setOpen] = useState(false);
    const [pendingIds, setPendingIds] = useState(branchIds);

    useEffect(() => {
        if (!open) {
            setPendingIds(branchIds);
        }
    }, [branchIds, open]);

    function toggleBranch(branchId: number, checked: boolean) {
        if (checked) {
            setPendingIds((current) =>
                Array.from(new Set([...current, branchId])),
            );

            return;
        }

        setPendingIds((current) => {
            const next = current.filter((id) => id !== branchId);

            return next.length > 0 ? next : [branchId];
        });
    }

    function toggleAll(checked: boolean) {
        setPendingIds(
            checked
                ? branches.map((branch) => branch.id)
                : ([branches[0]?.id].filter(Boolean) as number[]),
        );
    }

    function applySelection() {
        if (!sameNumberIds(pendingIds, branchIds)) {
            onChange(pendingIds);
        }

        setOpen(false);
    }

    function handleOpenChange(nextOpen: boolean) {
        if (nextOpen) {
            setPendingIds(branchIds);
            setOpen(true);

            return;
        }

        if (!sameNumberIds(pendingIds, branchIds)) {
            onChange(pendingIds);
        }

        setOpen(false);
    }

    return (
        <DropdownMenu open={open} onOpenChange={handleOpenChange} modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    className={cn(
                        filterMenuTriggerClassName,
                        'h-9 justify-between font-normal',
                        branchIds.length === 0 && 'text-muted-foreground',
                        className,
                    )}
                >
                    <span className="truncate">
                        {branchSelectionLabel(branchIds, branches)}
                    </span>
                    <ChevronDown className="size-4 opacity-50" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="start"
                className="max-h-[min(70vh,var(--radix-dropdown-menu-content-available-height))] min-w-[min(280px,calc(100vw-2rem))] p-0"
            >
                <div className="sticky top-0 z-10 border-b bg-popover p-1">
                    <DropdownMenuCheckboxItem
                        checked={
                            pendingIds.length === branches.length
                                ? true
                                : pendingIds.length === 0
                                  ? false
                                  : 'indeterminate'
                        }
                        onCheckedChange={(checked) =>
                            toggleAll(checked === true)
                        }
                        onSelect={(event) => event.preventDefault()}
                    >
                        Select all
                    </DropdownMenuCheckboxItem>
                </div>
                <div className="max-h-64 overflow-y-auto p-1 scrollbar-thin">
                    {sortBranchesByCode(branches).map((item) => (
                        <DropdownMenuCheckboxItem
                            key={item.id}
                            checked={pendingIds.includes(item.id)}
                            onCheckedChange={(checked) =>
                                toggleBranch(item.id, checked === true)
                            }
                            onSelect={(event) => event.preventDefault()}
                        >
                            {item.code} — {item.name}
                        </DropdownMenuCheckboxItem>
                    ))}
                </div>
                <div className="sticky bottom-0 border-t bg-popover p-2">
                    <Button
                        type="button"
                        size="sm"
                        className="w-full"
                        onClick={applySelection}
                    >
                        Apply
                    </Button>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

type PeriodFilterMenuProps = {
    availableMonths: StatementMonth[];
    selectedPeriods: StatementMonth[];
    onChange: (periods: StatementMonth[]) => void;
    emptyLabel?: string;
    allowEmpty?: boolean;
    className?: string;
};

export function PeriodFilterMenu({
    availableMonths,
    selectedPeriods,
    onChange,
    emptyLabel,
    allowEmpty = false,
    className,
}: PeriodFilterMenuProps) {
    const [open, setOpen] = useState(false);
    const [pendingPeriods, setPendingPeriods] = useState(selectedPeriods);
    const pendingKeys = new Set(pendingPeriods.map(periodKey));

    useEffect(() => {
        if (!open) {
            setPendingPeriods(
                selectedPeriods.length === 0 && allowEmpty
                    ? [...availableMonths]
                    : selectedPeriods,
            );
        }
    }, [allowEmpty, availableMonths, open, selectedPeriods]);

    const allPeriodsSelected =
        pendingPeriods.length === availableMonths.length ||
        (allowEmpty &&
            selectedPeriods.length === 0 &&
            pendingPeriods.length === availableMonths.length);

    function togglePeriod(period: StatementMonth, checked: boolean) {
        if (checked) {
            setPendingPeriods((current) =>
                [...current, period].sort(
                    (left, right) =>
                        left.year * 100 +
                        left.month -
                        (right.year * 100 + right.month),
                ),
            );

            return;
        }

        setPendingPeriods((current) => {
            const next = current.filter(
                (item) => periodKey(item) !== periodKey(period),
            );

            if (next.length > 0 || allowEmpty) {
                return next;
            }

            return [period];
        });
    }

    function toggleAll(checked: boolean) {
        setPendingPeriods(
            checked
                ? [...availableMonths]
                : allowEmpty
                  ? []
                  : ([availableMonths[0]].filter(Boolean) as StatementMonth[]),
        );
    }

    function periodsEquivalent(
        left: StatementMonth[],
        right: StatementMonth[],
    ): boolean {
        const normalizedLeft = normalizePeriodSelection(left, availableMonths);
        const normalizedRight = normalizePeriodSelection(right, availableMonths);

        if (normalizedLeft.length === 0 && normalizedRight.length === 0) {
            return true;
        }

        return samePeriods(normalizedLeft, normalizedRight);
    }

    function applySelection() {
        if (!periodsEquivalent(pendingPeriods, selectedPeriods)) {
            onChange(pendingPeriods);
        }

        setOpen(false);
    }

    function handleOpenChange(nextOpen: boolean) {
        if (nextOpen) {
            setPendingPeriods(
                selectedPeriods.length === 0 && allowEmpty
                    ? [...availableMonths]
                    : selectedPeriods,
            );
            setOpen(true);

            return;
        }

        if (!periodsEquivalent(pendingPeriods, selectedPeriods)) {
            onChange(pendingPeriods);
        }

        setOpen(false);
    }

    return (
        <DropdownMenu open={open} onOpenChange={handleOpenChange} modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    className={cn(
                        filterMenuTriggerClassName,
                        'h-9 justify-between font-normal',
                        selectedPeriods.length === 0 && 'text-muted-foreground',
                        className,
                    )}
                >
                    <span className="truncate">
                        {selectedPeriods.length === 0 && emptyLabel
                            ? emptyLabel
                            : periodSelectionLabel(selectedPeriods)}
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
                            allPeriodsSelected
                                ? true
                                : pendingPeriods.length === 0
                                  ? false
                                  : 'indeterminate'
                        }
                        onCheckedChange={(checked) =>
                            toggleAll(checked === true)
                        }
                        onSelect={(event) => event.preventDefault()}
                    >
                        Select all
                    </DropdownMenuCheckboxItem>
                </div>
                <div className="max-h-64 overflow-y-auto p-1 scrollbar-thin">
                    {availableMonths.map((item) => (
                        <DropdownMenuCheckboxItem
                            key={periodKey(item)}
                            checked={pendingKeys.has(periodKey(item))}
                            onCheckedChange={(checked) =>
                                togglePeriod(item, checked === true)
                            }
                            onSelect={(event) => event.preventDefault()}
                        >
                            {item.label}
                        </DropdownMenuCheckboxItem>
                    ))}
                </div>
                <div className="sticky bottom-0 border-t bg-popover p-2">
                    <Button
                        type="button"
                        size="sm"
                        className="w-full"
                        onClick={applySelection}
                    >
                        Apply
                    </Button>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
function sameNumericSelection(left: number[], right: number[]): boolean {
    if (left.length !== right.length) {
        return false;
    }

    const sortedLeft = [...left].sort((a, b) => a - b);
    const sortedRight = [...right].sort((a, b) => a - b);

    return sortedLeft.every((value, index) => value === sortedRight[index]);
}

type YearFilterMenuProps = {
    years: number[];
    selectedYears: number[];
    onChange: (years: number[]) => void;
    className?: string;
};

export function YearFilterMenu({
    years,
    selectedYears,
    onChange,
    className,
}: YearFilterMenuProps) {
    const [open, setOpen] = useState(false);
    const [pendingYears, setPendingYears] = useState(selectedYears);

    useEffect(() => {
        if (!open) {
            setPendingYears(selectedYears);
        }
    }, [open, selectedYears]);

    function toggleYear(year: number, checked: boolean) {
        if (checked) {
            setPendingYears((current) =>
                Array.from(new Set([...current, year])).sort(
                    (left, right) => right - left,
                ),
            );

            return;
        }

        setPendingYears((current) => {
            const next = current.filter((value) => value !== year);

            return next.length > 0 ? next : [year];
        });
    }

    function toggleAll(checked: boolean) {
        setPendingYears(checked ? [...years] : ([years[0]].filter(Boolean) as number[]));
    }

    function applySelection() {
        if (!sameNumericSelection(pendingYears, selectedYears)) {
            onChange(pendingYears);
        }

        setOpen(false);
    }

    function handleOpenChange(nextOpen: boolean) {
        if (nextOpen) {
            setPendingYears(selectedYears);
            setOpen(true);

            return;
        }

        if (!sameNumericSelection(pendingYears, selectedYears)) {
            onChange(pendingYears);
        }

        setOpen(false);
    }

    const pendingSet = new Set(pendingYears);

    return (
        <DropdownMenu open={open} onOpenChange={handleOpenChange} modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    className={cn(
                        filterMenuTriggerClassName,
                        'h-9 justify-between font-normal',
                        selectedYears.length === 0 && 'text-muted-foreground',
                        className,
                    )}
                >
                    <span className="truncate">
                        {yearSelectionLabel(selectedYears)}
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
                            pendingYears.length === years.length
                                ? true
                                : pendingYears.length === 0
                                  ? false
                                  : 'indeterminate'
                        }
                        onCheckedChange={(checked) =>
                            toggleAll(checked === true)
                        }
                        onSelect={(event) => event.preventDefault()}
                    >
                        Select all
                    </DropdownMenuCheckboxItem>
                </div>
                <div className="max-h-64 overflow-y-auto p-1 scrollbar-thin">
                    {years.map((year) => (
                        <DropdownMenuCheckboxItem
                            key={year}
                            checked={pendingSet.has(year)}
                            onCheckedChange={(checked) =>
                                toggleYear(year, checked === true)
                            }
                            onSelect={(event) => event.preventDefault()}
                        >
                            {year}
                        </DropdownMenuCheckboxItem>
                    ))}
                </div>
                <div className="sticky bottom-0 border-t bg-popover p-2">
                    <Button
                        type="button"
                        size="sm"
                        className="w-full"
                        onClick={applySelection}
                    >
                        Apply
                    </Button>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

type MonthFilterMenuProps = {
    months: { value: number; label: string }[];
    selectedMonths: number[];
    onChange: (months: number[]) => void;
    className?: string;
};

export function MonthFilterMenu({
    months,
    selectedMonths,
    onChange,
    className,
}: MonthFilterMenuProps) {
    const [open, setOpen] = useState(false);
    const [pendingMonths, setPendingMonths] = useState(selectedMonths);

    useEffect(() => {
        if (!open) {
            setPendingMonths(selectedMonths);
        }
    }, [open, selectedMonths]);

    function toggleMonth(month: number, checked: boolean) {
        if (checked) {
            setPendingMonths((current) =>
                Array.from(new Set([...current, month])).sort(
                    (left, right) => left - right,
                ),
            );

            return;
        }

        setPendingMonths((current) => {
            const next = current.filter((value) => value !== month);

            return next.length > 0 ? next : [month];
        });
    }

    function toggleAll(checked: boolean) {
        setPendingMonths(
            checked
                ? months.map((item) => item.value)
                : ([months[0]?.value].filter(Boolean) as number[]),
        );
    }

    function applySelection() {
        if (!sameNumericSelection(pendingMonths, selectedMonths)) {
            onChange(pendingMonths);
        }

        setOpen(false);
    }

    function handleOpenChange(nextOpen: boolean) {
        if (nextOpen) {
            setPendingMonths(selectedMonths);
            setOpen(true);

            return;
        }

        if (!sameNumericSelection(pendingMonths, selectedMonths)) {
            onChange(pendingMonths);
        }

        setOpen(false);
    }

    const pendingSet = new Set(pendingMonths);

    return (
        <DropdownMenu open={open} onOpenChange={handleOpenChange} modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    className={cn(
                        filterMenuTriggerClassName,
                        'h-9 justify-between font-normal',
                        selectedMonths.length === 0 && 'text-muted-foreground',
                        className,
                    )}
                >
                    <span className="truncate">
                        {monthSelectionLabel(selectedMonths, months)}
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
                            pendingMonths.length === months.length
                                ? true
                                : pendingMonths.length === 0
                                  ? false
                                  : 'indeterminate'
                        }
                        onCheckedChange={(checked) =>
                            toggleAll(checked === true)
                        }
                        onSelect={(event) => event.preventDefault()}
                    >
                        Select all
                    </DropdownMenuCheckboxItem>
                </div>
                <div className="max-h-64 overflow-y-auto p-1 scrollbar-thin">
                    {months.map((item) => (
                        <DropdownMenuCheckboxItem
                            key={item.value}
                            checked={pendingSet.has(item.value)}
                            onCheckedChange={(checked) =>
                                toggleMonth(item.value, checked === true)
                            }
                            onSelect={(event) => event.preventDefault()}
                        >
                            {item.label}
                        </DropdownMenuCheckboxItem>
                    ))}
                </div>
                <div className="sticky bottom-0 border-t bg-popover p-2">
                    <Button
                        type="button"
                        size="sm"
                        className="w-full"
                        onClick={applySelection}
                    >
                        Apply
                    </Button>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
function sameStringSelection(left: string[], right: string[]): boolean {
    if (left.length !== right.length) {
        return false;
    }

    const sortedLeft = [...left].sort();
    const sortedRight = [...right].sort();

    return sortedLeft.every((value, index) => value === sortedRight[index]);
}

type OptionFilterMenuProps = {
    options: { value: string; label: string }[];
    selectedValues: string[];
    onChange: (values: string[]) => void;
    emptyLabel: string;
    className?: string;
};

export function OptionFilterMenu({
    options,
    selectedValues,
    onChange,
    emptyLabel,
    className,
}: OptionFilterMenuProps) {
    const [open, setOpen] = useState(false);
    const [pendingValues, setPendingValues] = useState(selectedValues);

    useEffect(() => {
        if (!open) {
            setPendingValues(selectedValues);
        }
    }, [open, selectedValues]);

    function selectionLabel(values: string[]): string {
        if (values.length === 0) {
            return emptyLabel;
        }

        if (values.length === 1) {
            return (
                options.find((item) => item.value === values[0])?.label ??
                '1 selected'
            );
        }

        return `${values.length} selected`;
    }

    function toggleValue(value: string, checked: boolean) {
        if (checked) {
            setPendingValues((current) => Array.from(new Set([...current, value])));

            return;
        }

        setPendingValues((current) => current.filter((item) => item !== value));
    }

    function toggleAll(checked: boolean) {
        setPendingValues(checked ? options.map((item) => item.value) : []);
    }

    function applySelection() {
        if (!sameStringSelection(pendingValues, selectedValues)) {
            onChange(pendingValues);
        }

        setOpen(false);
    }

    function handleOpenChange(nextOpen: boolean) {
        if (nextOpen) {
            setPendingValues(selectedValues);
            setOpen(true);

            return;
        }

        if (!sameStringSelection(pendingValues, selectedValues)) {
            onChange(pendingValues);
        }

        setOpen(false);
    }

    const pendingSet = new Set(pendingValues);

    return (
        <DropdownMenu open={open} onOpenChange={handleOpenChange} modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    className={cn(
                        filterMenuTriggerClassName,
                        'h-9 justify-between font-normal',
                        selectedValues.length === 0 && 'text-muted-foreground',
                        className,
                    )}
                >
                    <span className="truncate">
                        {selectionLabel(selectedValues)}
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
                            pendingValues.length === options.length
                                ? true
                                : pendingValues.length === 0
                                  ? false
                                  : 'indeterminate'
                        }
                        onCheckedChange={(checked) =>
                            toggleAll(checked === true)
                        }
                        onSelect={(event) => event.preventDefault()}
                    >
                        Select all
                    </DropdownMenuCheckboxItem>
                </div>
                <div className="max-h-64 overflow-y-auto p-1 scrollbar-thin">
                    {options.map((item) => (
                        <DropdownMenuCheckboxItem
                            key={item.value}
                            checked={pendingSet.has(item.value)}
                            onCheckedChange={(checked) =>
                                toggleValue(item.value, checked === true)
                            }
                            onSelect={(event) => event.preventDefault()}
                        >
                            {item.label}
                        </DropdownMenuCheckboxItem>
                    ))}
                </div>
                <div className="sticky bottom-0 border-t bg-popover p-2">
                    <Button
                        type="button"
                        size="sm"
                        className="w-full"
                        onClick={applySelection}
                    >
                        Apply
                    </Button>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
