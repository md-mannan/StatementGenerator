import { BranchMonthRowActions } from '@/components/branch-month-row-actions';
import { Badge } from '@/components/ui/badge';
import type { Branch, BranchMonthStat } from '@/types';

type BranchMonthRow = {
    key: string;
    branch: Branch;
    stat: BranchMonthStat | null;
};

type Props = {
    row: BranchMonthRow;
    onEdit: (branch: Branch) => void;
};

export function MobileBranchMonthCard({ row, onEdit }: Props) {
    const { branch, stat } = row;

    return (
        <article className="rounded-xl border bg-card p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-mono text-sm font-semibold">{branch.code}</p>
                    <p className="truncate font-medium">{branch.name}</p>
                </div>
                <Badge variant={stat !== null ? 'default' : 'secondary'}>
                    {stat !== null ? 'Active' : 'No data'}
                </Badge>
            </div>

            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <div>
                    <dt className="text-muted-foreground">Month</dt>
                    <dd className="font-medium">{stat?.label ?? '—'}</dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Entries</dt>
                    <dd className="font-medium tabular-nums">
                        {stat?.entries_count ?? 0}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Total</dt>
                    <dd className="font-mono font-medium tabular-nums">
                        {stat?.total_amount ?? '0.000'}
                    </dd>
                </div>
                <div>
                    <dt className="text-muted-foreground">Last upload</dt>
                    <dd className="text-muted-foreground">
                        {stat?.last_uploaded_at ?? '—'}
                    </dd>
                </div>
            </dl>

            <div className="mt-4 border-t pt-4">
                <BranchMonthRowActions
                    branch={branch}
                    stat={stat}
                    onEdit={onEdit}
                    layout="grid"
                />
            </div>
        </article>
    );
}
