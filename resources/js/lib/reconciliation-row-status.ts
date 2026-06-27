export type ReconciliationRowStatus =
    | 'no_bill'
    | 'supplier'
    | 'higher'
    | 'lower'
    | 'duplicate'
    | 'unresolved'
    | 'matched';

export const RECONCILIATION_STATUS_STYLES: Record<
    ReconciliationRowStatus,
    {
        label: string;
        row: string;
        swatch: string;
        diffText?: string;
        invoiceText?: string;
    }
> = {
    no_bill: {
        label: 'No bill expected',
        row: 'bg-sky-50 dark:bg-sky-950/30',
        swatch:
            'bg-sky-50 ring-sky-200 dark:bg-sky-950/30 dark:ring-sky-800',
        invoiceText: 'text-sky-800 dark:text-sky-300',
    },
    supplier: {
        label: 'Supplier invoice',
        row: 'bg-violet-50 dark:bg-violet-950/30',
        swatch:
            'bg-violet-50 ring-violet-200 dark:bg-violet-950/30 dark:ring-violet-800',
        invoiceText: 'text-violet-800 dark:text-violet-300',
    },
    higher: {
        label: 'Higher than matched',
        row: 'bg-orange-50 dark:bg-orange-950/30',
        swatch:
            'bg-orange-50 ring-orange-200 dark:bg-orange-950/30 dark:ring-orange-800',
        diffText: 'font-semibold text-orange-700 dark:text-orange-400',
    },
    lower: {
        label: 'Lower than matched',
        row: 'bg-rose-50 dark:bg-rose-950/30',
        swatch:
            'bg-rose-50 ring-rose-200 dark:bg-rose-950/30 dark:ring-rose-800',
        diffText: 'font-semibold text-rose-700 dark:text-rose-400',
    },
    duplicate: {
        label: 'Duplicate invoice',
        row: 'bg-yellow-50 dark:bg-yellow-950/30',
        swatch:
            'bg-yellow-50 ring-yellow-200 dark:bg-yellow-950/30 dark:ring-yellow-800',
        invoiceText: 'font-semibold text-yellow-800 dark:text-yellow-400',
    },
    unresolved: {
        label: 'Unresolved',
        row: 'bg-amber-50 dark:bg-amber-950/30',
        swatch:
            'bg-amber-50 ring-amber-200 dark:bg-amber-950/30 dark:ring-amber-800',
        invoiceText: 'font-semibold text-amber-800 dark:text-amber-400',
    },
    matched: {
        label: 'Complete / matched',
        row: 'bg-emerald-50 dark:bg-emerald-950/30',
        swatch:
            'bg-emerald-50 ring-emerald-200 dark:bg-emerald-950/30 dark:ring-emerald-800',
    },
};

export const RECONCILIATION_LEGEND_STATUSES: ReconciliationRowStatus[] = [
    'unresolved',
    'duplicate',
    'higher',
    'lower',
    'no_bill',
    'supplier',
    'matched',
];

export const BRANCH_STATEMENT_LEGEND_STATUSES: ReconciliationRowStatus[] = [
    'unresolved',
    'duplicate',
    'higher',
    'lower',
    'no_bill',
    'matched',
];

export const COMPARISON_LEGEND_STATUSES: ReconciliationRowStatus[] = [
    'unresolved',
    'duplicate',
    'higher',
    'lower',
    'supplier',
    'matched',
];

export const SIMPLE_RECONCILIATION_LEGEND_STATUSES: ReconciliationRowStatus[] =
    ['unresolved', 'duplicate', 'higher', 'lower', 'matched'];

type ResolveReconciliationRowStatusInput = {
    isDuplicate: boolean;
    isResolved?: boolean;
    noBillExpected?: boolean;
    noBranchExpected?: boolean;
    differenceValue?: number | null;
    clientDifferenceValue?: number | null;
};

const mismatchThreshold = 0.0005;

function hasHigherDifference(
    ...values: Array<number | null | undefined>
): boolean {
    return values.some(
        (value) => value !== null && value !== undefined && value > mismatchThreshold,
    );
}

function hasLowerDifference(
    ...values: Array<number | null | undefined>
): boolean {
    return values.some(
        (value) =>
            value !== null && value !== undefined && value < -mismatchThreshold,
    );
}

export function resolveReconciliationRowStatus({
    isDuplicate,
    isResolved = false,
    noBillExpected = false,
    noBranchExpected = false,
    differenceValue = null,
    clientDifferenceValue = null,
}: ResolveReconciliationRowStatusInput): ReconciliationRowStatus {
    if (noBillExpected) {
        return 'no_bill';
    }

    if (noBranchExpected) {
        return 'supplier';
    }

    if (
        hasHigherDifference(differenceValue, clientDifferenceValue)
    ) {
        return 'higher';
    }

    if (hasLowerDifference(differenceValue, clientDifferenceValue)) {
        return 'lower';
    }

    if (isDuplicate) {
        return 'duplicate';
    }

    if (!isResolved) {
        return 'unresolved';
    }

    return 'matched';
}

export function reconciliationRowClassName(
    status: ReconciliationRowStatus,
): string {
    return RECONCILIATION_STATUS_STYLES[status].row;
}

export function reconciliationDiffTextClassName(
    value: number | null,
    status: ReconciliationRowStatus,
): string | undefined {
    if (value === null) {
        return undefined;
    }

    if (value > mismatchThreshold) {
        return RECONCILIATION_STATUS_STYLES.higher.diffText;
    }

    if (value < -mismatchThreshold) {
        return RECONCILIATION_STATUS_STYLES.lower.diffText;
    }

    if (status === 'matched') {
        return undefined;
    }

    return undefined;
}

export function reconciliationInvoiceTextClassName(
    status: ReconciliationRowStatus,
    isDuplicate: boolean,
): string | undefined {
    if (isDuplicate || status === 'duplicate') {
        return RECONCILIATION_STATUS_STYLES.duplicate.invoiceText;
    }

    if (status === 'unresolved') {
        return RECONCILIATION_STATUS_STYLES.unresolved.invoiceText;
    }

    if (status === 'no_bill') {
        return RECONCILIATION_STATUS_STYLES.no_bill.invoiceText;
    }

    if (status === 'supplier') {
        return RECONCILIATION_STATUS_STYLES.supplier.invoiceText;
    }

    return undefined;
}
