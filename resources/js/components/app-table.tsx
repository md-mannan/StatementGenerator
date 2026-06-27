import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Tailwind classes applied to every sticky table header cell. */
export const stickyTableHeadCellClassName =
    'sticky top-0 z-20 bg-card align-middle shadow-[inset_0_-1px_0_0_var(--border)]';

type AppTableProps = ComponentProps<'table'>;

export function AppTable({ className, ...props }: AppTableProps) {
    return (
        <table
            className={cn(
                'app-table w-full border-separate border-spacing-0 text-sm',
                className,
            )}
            {...props}
        />
    );
}

type AppTableHeadCellProps = ComponentProps<'th'>;

export function AppTableHeadCell({
    className,
    ...props
}: AppTableHeadCellProps) {
    return (
        <th
            className={cn(
                stickyTableHeadCellClassName,
                'px-2 py-2 sm:px-4 sm:py-3',
                className,
            )}
            {...props}
        />
    );
}

type AppTableSectionProps = {
    className?: string;
    children: ReactNode;
};

export function AppTableScroll({ className, children }: AppTableSectionProps) {
    return (
        <div
            className={cn(
                'min-w-0 overflow-x-auto scrollbar-thin',
                className,
            )}
        >
            {children}
        </div>
    );
}

/** Internal scroll region (spreadsheet grid, dialogs). Sticky headers stick inside this box. */
export function AppTableScrollContainer({
    className,
    children,
    ...props
}: ComponentProps<'div'>) {
    return (
        <div className={cn('overflow-auto', className)} {...props}>
            {children}
        </div>
    );
}
