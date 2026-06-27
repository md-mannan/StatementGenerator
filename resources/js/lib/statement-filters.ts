import type { StatementMonth } from '@/types';

export function periodKey(period: Pick<StatementMonth, 'year' | 'month'>): string {
    return `${period.year}-${period.month}`;
}

export function buildStatementFilterQuery(
    branchIds: number[],
    periods: Pick<StatementMonth, 'year' | 'month'>[],
): Record<string, number[] | string[]> {
    const query: Record<string, number[] | string[]> = {};

    if (branchIds.length > 0) {
        query.branch_ids = branchIds;
    }

    if (periods.length > 0) {
        query.periods = periods.map(periodKey);
    }

    return query;
}

export function normalizePeriodSelection(
    periods: Pick<StatementMonth, 'year' | 'month'>[],
    availableMonths: Pick<StatementMonth, 'year' | 'month'>[],
): Pick<StatementMonth, 'year' | 'month'>[] {
    if (periods.length === 0) {
        return [];
    }

    if (
        availableMonths.length > 0 &&
        periods.length === availableMonths.length
    ) {
        return [];
    }

    return periods;
}

export function yearSelectionLabel(selectedYears: number[]): string {
    if (selectedYears.length === 0) {
        return 'Select years';
    }

    if (selectedYears.length === 1) {
        return String(selectedYears[0]);
    }

    return `${selectedYears.length} years`;
}

export function monthSelectionLabel(
    selectedMonths: number[],
    months: { value: number; label: string }[],
): string {
    if (selectedMonths.length === 0) {
        return 'Select months';
    }

    if (selectedMonths.length === 1) {
        return (
            months.find((item) => item.value === selectedMonths[0])?.label ??
            '1 month'
        );
    }

    return `${selectedMonths.length} months`;
}

export function periodSelectionLabel(
    periods: Pick<StatementMonth, 'label'>[],
): string {
    if (periods.length === 0) {
        return 'Select months';
    }

    if (periods.length === 1) {
        return periods[0].label;
    }

    return `${periods.length} months`;
}

export function branchSelectionLabel(
    branchIds: number[],
    branches: { id: number; code: string; name: string }[],
): string {
    if (branchIds.length === 0) {
        return 'Select branches';
    }

    if (branchIds.length === 1) {
        const branch = branches.find((item) => item.id === branchIds[0]);

        return branch ? `${branch.code} — ${branch.name}` : '1 branch';
    }

    if (branchIds.length === branches.length) {
        return 'All branches';
    }

    return `${branchIds.length} branches`;
}

export function compareBranchCode(left: string, right: string): number {
    return left.localeCompare(right, undefined, { numeric: true });
}

export function sortBranchesByCode<T extends { code: string }>(
    branches: readonly T[],
): T[] {
    return [...branches].sort((left, right) =>
        compareBranchCode(left.code, right.code),
    );
}
