import { ExternalLink } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Client, InvoiceDetail, InvoiceSourceEntry } from '@/types';

type SourceKey = 'branch' | 'received' | 'annexure';

type SourceConfig = {
    key: SourceKey;
    label: string;
    amount: (invoice: InvoiceDetail) => string | null;
    entries: (invoice: InvoiceDetail) => Omit<InvoiceSourceEntry, 'source'>[];
};

const SOURCES: SourceConfig[] = [
    {
        key: 'branch',
        label: 'Branch',
        amount: (invoice) => invoice.branch_amount,
        entries: (invoice) => invoice.branch_entries,
    },
    {
        key: 'received',
        label: 'Received',
        amount: (invoice) => invoice.received_amount,
        entries: (invoice) => invoice.received_entries,
    },
    {
        key: 'annexure',
        label: 'Annexure',
        amount: (invoice) => invoice.annexure_amount,
        entries: (invoice) => invoice.annexure_entries,
    },
];

type NodeState = 'ok' | 'missing' | 'mismatch';

function sourceState(
    invoice: InvoiceDetail,
    source: SourceKey,
): NodeState {
    if (invoice.missing_sources.includes(source)) {
        return 'missing';
    }

    if (invoice.has_amount_mismatch) {
        return 'mismatch';
    }

    return 'ok';
}

function stateBorder(state: NodeState): string {
    switch (state) {
        case 'ok':
            return 'border-primary/40';
        case 'missing':
            return 'border-dashed border-muted-foreground/50';
        case 'mismatch':
            return 'border-destructive/50';
    }
}

function stateConnector(state: NodeState): string {
    switch (state) {
        case 'ok':
            return 'stroke-primary/40';
        case 'missing':
            return 'stroke-muted-foreground/30';
        case 'mismatch':
            return 'stroke-destructive/40';
    }
}

function stateLabel(state: NodeState): string {
    switch (state) {
        case 'ok':
            return 'Linked';
        case 'missing':
            return 'Missing';
        case 'mismatch':
            return 'Mismatch';
    }
}

function resolveChequeNumber(
    entry: Omit<InvoiceSourceEntry, 'source'> | null,
    invoice: InvoiceDetail,
): string | null {
    const fromEntry = entry?.cheque_number?.trim();

    if (fromEntry) {
        return fromEntry;
    }

    const fromInvoice = invoice.cheque_number?.trim();

    return fromInvoice || null;
}

function resolveChequePeriod(
    entry: Omit<InvoiceSourceEntry, 'source'> | null,
    invoice: InvoiceDetail,
): string | null {
    return entry?.cheque_period ?? invoice.cheque_period ?? null;
}

function entryFacts(
    source: SourceKey,
    entry: Omit<InvoiceSourceEntry, 'source'> | null,
    invoice: InvoiceDetail,
): { label: string; value: string }[] {
    if (!entry) {
        const chequeNumber = resolveChequeNumber(null, invoice);

        if (chequeNumber) {
            return [
                { label: 'Status', value: 'No record' },
                { label: 'Cheque', value: chequeNumber },
            ];
        }

        return [{ label: 'Status', value: 'No record' }];
    }

    const facts: { label: string; value: string }[] = [
        { label: 'Date', value: entry.transaction_date },
        {
            label: 'Branch',
            value:
                [entry.branch_code, entry.branch_name]
                    .filter(Boolean)
                    .join(' · ') || '—',
        },
    ];

    if (source === 'branch' && entry.statement_period) {
        facts.push({ label: 'Filed', value: entry.statement_period });
    }

    if (source === 'received' && entry.statement_period) {
        facts.push({ label: 'Period', value: entry.statement_period });
    }

    const chequeNumber = resolveChequeNumber(entry, invoice);
    const chequePeriod = resolveChequePeriod(entry, invoice);

    if (chequeNumber) {
        facts.push({ label: 'Cheque', value: chequeNumber });
    }

    if (chequePeriod) {
        facts.push({ label: 'Cheque month', value: chequePeriod });
    }

    return facts;
}

function MapNode({
    title,
    subtitle,
    amount,
    facts,
    state,
    href,
    variant,
}: {
    title: string;
    subtitle?: string;
    amount?: string | null;
    facts?: { label: string; value: string }[];
    state?: NodeState;
    href?: string;
    variant: 'client' | 'hub' | 'source';
}) {
    return (
        <div
            className={cn(
                'relative w-full rounded-xl border bg-card px-4 py-3 shadow-sm',
                variant === 'hub' &&
                    'border-primary/50 bg-primary/5 ring-2 ring-primary/20',
                variant === 'client' && 'max-w-xs border-border/80',
                variant === 'source' && state && stateBorder(state),
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p
                        className={cn(
                            'font-medium',
                            variant === 'hub' && 'font-mono text-lg',
                        )}
                    >
                        {title}
                    </p>
                    {subtitle && (
                        <p className="text-xs text-muted-foreground">
                            {subtitle}
                        </p>
                    )}
                </div>
                {state && variant === 'source' && (
                    <span
                        className={cn(
                            'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide',
                            state === 'ok' && 'bg-primary/10 text-primary',
                            state === 'missing' &&
                                'bg-muted text-muted-foreground',
                            state === 'mismatch' &&
                                'bg-destructive/10 text-destructive',
                        )}
                    >
                        {stateLabel(state)}
                    </span>
                )}
            </div>

            {amount !== undefined && (
                <p
                    className={cn(
                        'mt-2 font-mono text-xl font-semibold tabular-nums',
                        !amount && 'text-muted-foreground',
                    )}
                >
                    {amount ?? '—'}
                </p>
            )}

            {facts && facts.length > 0 && (
                <dl className="mt-3 space-y-1 border-t pt-2">
                    {facts.map((fact) => (
                        <div
                            key={fact.label}
                            className="flex justify-between gap-3 text-xs"
                        >
                            <dt className="text-muted-foreground">
                                {fact.label}
                            </dt>
                            <dd className="text-right font-medium">
                                {fact.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            {href && (
                <a
                    href={href}
                    target="_blank"
                    rel="noreferrer"
                    className="mt-3 inline-flex items-center gap-1 text-xs text-primary hover:underline"
                >
                    Open source
                    <ExternalLink className="size-3" />
                </a>
            )}
        </div>
    );
}

function RelationFork({
    states,
}: {
    states: [NodeState, NodeState, NodeState];
}) {
    return (
        <svg
            viewBox="0 0 320 48"
            className="h-12 w-full max-w-lg text-border"
            aria-hidden
        >
            <line
                x1="160"
                y1="0"
                x2="160"
                y2="16"
                className="stroke-current"
                strokeWidth="2"
            />
            <line
                x1="40"
                y1="16"
                x2="280"
                y2="16"
                className="stroke-current"
                strokeWidth="2"
            />
            {[
                { x: 40, state: states[0] },
                { x: 160, state: states[1] },
                { x: 280, state: states[2] },
            ].map((branch) => (
                <line
                    key={branch.x}
                    x1={branch.x}
                    y1="16"
                    x2={branch.x}
                    y2="48"
                    fill="none"
                    strokeWidth="2"
                    className={stateConnector(branch.state)}
                />
            ))}
        </svg>
    );
}

export function InvoiceRelationMap({
    client,
    invoice,
}: {
    client: Pick<Client, 'id' | 'name'>;
    invoice: InvoiceDetail;
}) {
    const hubSubtitle = [
        invoice.invoice_date,
        invoice.statement_period,
        invoice.branch_code ? `Branch ${invoice.branch_code}` : null,
        invoice.cheque_number ? `Cheque ${invoice.cheque_number}` : null,
        invoice.cheque_period ? `Cheque month ${invoice.cheque_period}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <div className="mx-auto flex w-full max-w-3xl flex-col items-center">
            <MapNode
                variant="client"
                title={client.name}
                subtitle="Client"
            />

            <div className="h-8 w-px bg-border" aria-hidden />

            <MapNode
                variant="hub"
                title={invoice.invoice_no}
                subtitle={hubSubtitle || 'Invoice'}
            />

            <RelationFork
                states={SOURCES.map((source) =>
                    sourceState(invoice, source.key),
                ) as [NodeState, NodeState, NodeState]}
            />

            <div className="grid w-full max-w-2xl grid-cols-1 gap-4 sm:grid-cols-3">
                {SOURCES.map((source) => {
                    const entry = source.entries(invoice)[0] ?? null;
                    const state = sourceState(invoice, source.key);

                    return (
                        <MapNode
                            key={source.key}
                            variant="source"
                            title={source.label}
                            subtitle="Statement source"
                            amount={source.amount(invoice)}
                            facts={entryFacts(source.key, entry, invoice)}
                            state={state}
                            href={entry?.source_url}
                        />
                    );
                })}
            </div>

            <p className="mt-6 text-center text-xs text-muted-foreground">
                {invoice.invoice_date_differs_from_period &&
                    'Invoice date differs from filed statement month. '}
                Branch upload, received report, and annexure payment connect
                through this invoice.
                {invoice.cheque_number && ` Cheque ${invoice.cheque_number}.`}
            </p>
        </div>
    );
}
