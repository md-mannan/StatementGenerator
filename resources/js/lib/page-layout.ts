/** Standard page padding and width constraints for app pages. */
export const pageShellClassName =
    'flex min-w-0 flex-col gap-4 p-3 sm:gap-6 sm:p-4 lg:p-6';

/** Horizontal scroll for toolbars that must stay on one row on larger screens. */
export const toolbarScrollClassName =
    'flex min-w-0 items-center gap-2 overflow-x-auto pb-1 scrollbar-thin sm:flex-wrap sm:overflow-visible sm:pb-0';

/** Filter dropdown triggers — full width on mobile, fixed on sm+. */
export const filterMenuTriggerClassName = 'w-full min-w-0 sm:w-44';
