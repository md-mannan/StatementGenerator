/** Standard page padding and width constraints for app pages. */
export const pageShellClassName =
    'flex min-w-0 flex-col gap-4 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:gap-6 sm:p-4 lg:p-6';

/** Horizontal scroll for toolbars that must stay on one row on larger screens. */
export const toolbarScrollClassName =
    'flex min-w-0 items-center gap-2 overflow-x-auto pb-1 scrollbar-thin sm:flex-wrap sm:overflow-visible sm:pb-0';

/** Filter dropdown triggers — full width on mobile, fixed on sm+. */
export const filterMenuTriggerClassName = 'w-full min-w-0 sm:w-44';

/** Summary stat cards — two columns on phones, more on larger screens. */
export const summaryGridClassName =
    'grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4';

export const summaryGridWideClassName =
    'grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-5';

/** Compact stat value on small screens. */
export const summaryStatValueClassName = 'text-lg tabular-nums sm:text-2xl';

/** Client tab navigation — horizontal scroll with snap on mobile. */
export const clientTabNavClassName =
    '-mx-3 flex snap-x snap-mandatory gap-1 overflow-x-auto border-b px-3 pb-0 scrollbar-thin sm:mx-0 sm:flex-wrap sm:overflow-visible sm:snap-none sm:px-0';

export const clientTabLinkClassName =
    'inline-flex h-11 min-h-11 shrink-0 snap-start items-center whitespace-nowrap rounded-t-md border border-b-0 px-3 text-sm font-medium transition-colors sm:h-9 sm:min-h-0 sm:px-4';

/** Stack primary actions full-width on mobile. */
export const mobileActionGroupClassName =
    'flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap';
