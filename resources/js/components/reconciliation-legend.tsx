import {
    RECONCILIATION_LEGEND_STATUSES,
    RECONCILIATION_STATUS_STYLES,
    type ReconciliationRowStatus,
} from '@/lib/reconciliation-row-status';

type Props = {
    className?: string;
    compact?: boolean;
    statuses?: ReconciliationRowStatus[];
};

export function ReconciliationLegend({
    className,
    compact = false,
    statuses = RECONCILIATION_LEGEND_STATUSES,
}: Props) {
    return (
        <div
            className={`flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground ${className ?? ''}`}
        >
            {!compact && (
                <span className="font-medium text-foreground">Legend</span>
            )}
            {statuses.map((status) => {
                const item = RECONCILIATION_STATUS_STYLES[status];

                return (
                    <span
                        key={status}
                        className="inline-flex items-center gap-1.5"
                    >
                        <span
                            className={`size-3 shrink-0 rounded-sm ring-1 ring-inset ${item.swatch}`}
                            aria-hidden
                        />
                        {item.label}
                    </span>
                );
            })}
        </div>
    );
}
