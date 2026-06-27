import { cn } from '@/lib/utils';

export { stickyTableHeadCellClassName } from '@/components/app-table';

/** @deprecated Use `<AppTableScroll>` instead. */
export function appTableScrollClassName(className?: string): string {
    return cn('min-w-0', className);
}

/** @deprecated Use `<AppTableScrollContainer>` instead. */
export function appTableScrollContainerClassName(className?: string): string {
    return cn('overflow-auto', className);
}

/** @deprecated Use `<AppTable className="...">` instead. */
export function appTableClassName(className?: string): string {
    return cn('app-table w-full border-separate border-spacing-0 text-sm', className);
}
