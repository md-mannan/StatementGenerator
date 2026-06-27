type InvoiceEntry = {
    invoice_no: string;
    branch_id?: number | null;
    branch_code?: string | null;
};

function invoiceKey(
    entry: InvoiceEntry,
    scope: 'invoice' | 'branch-invoice',
): string {
    if (scope === 'branch-invoice') {
        const branchKey = entry.branch_id ?? entry.branch_code ?? '';

        return `${branchKey}:${entry.invoice_no}`;
    }

    return entry.invoice_no;
}

export function getDuplicateInvoiceKeys(
    entries: InvoiceEntry[],
    scope: 'invoice' | 'branch-invoice' = 'invoice',
): Set<string> {
    const counts = new Map<string, number>();

    entries.forEach((entry) => {
        const key = invoiceKey(entry, scope);
        counts.set(key, (counts.get(key) ?? 0) + 1);
    });

    return new Set(
        [...counts.entries()]
            .filter(([, count]) => count > 1)
            .map(([key]) => key),
    );
}

export function isDuplicateInvoice(
    entry: InvoiceEntry,
    duplicateKeys: Set<string>,
    scope: 'invoice' | 'branch-invoice' = 'invoice',
): boolean {
    return duplicateKeys.has(invoiceKey(entry, scope));
}
