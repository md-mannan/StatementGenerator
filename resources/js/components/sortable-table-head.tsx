import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { stickyTableHeadCellClassName } from '@/components/app-table';
import { cn } from '@/lib/utils';
import type { SortDirection } from '@/lib/table-sort';

type SortableTableHeadProps = {
    label: string;
    column: string;
    activeColumn: string | null;
    direction: SortDirection;
    onSort: (column: string) => void;
    align?: 'left' | 'center' | 'right';
    className?: string;
};

export function SortableTableHead({
    label,
    column,
    activeColumn,
    direction,
    onSort,
    align = 'left',
    className,
}: SortableTableHeadProps) {
    const isActive = activeColumn === column;
    const Icon = isActive
        ? direction === 'asc'
            ? ArrowUp
            : ArrowDown
        : ArrowUpDown;

    return (
        <th
            className={cn(
                stickyTableHeadCellClassName,
                'px-4 py-3 font-medium',
                align === 'right'
                    ? 'text-right'
                    : align === 'center'
                      ? 'text-center'
                      : 'text-left',
                className,
            )}
        >
            <button
                type="button"
                onClick={() => onSort(column)}
                className={cn(
                    'inline-flex w-full items-center gap-1 transition-colors hover:text-foreground',
                    isActive ? 'text-foreground' : 'text-muted-foreground',
                    align === 'right'
                        ? 'justify-end'
                        : align === 'center'
                          ? 'justify-center'
                          : 'justify-start',
                )}
            >
                <span>{label}</span>
                <Icon className={cn('size-3.5 shrink-0', !isActive && 'opacity-40')} />
            </button>
        </th>
    );
}
