import { Breadcrumbs } from '@/components/breadcrumbs';
import { GlobalSearchTrigger } from '@/components/global-search';
import { NavUser } from '@/components/nav-user';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="flex h-14 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-3 pt-[env(safe-area-inset-top)] transition-[width,height] ease-linear sm:h-16 sm:px-4 md:px-6 group-has-data-[collapsible=icon]/sidebar-wrapper:h-12">
            <div className="flex min-w-0 flex-1 items-center gap-2 overflow-hidden">
                <SidebarTrigger className="-ml-1 size-10 shrink-0 touch-target sm:size-7" />
                <div className="min-w-0 overflow-hidden">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>
            <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                <GlobalSearchTrigger />
                <NavUser variant="header" />
            </div>
        </header>
    );
}
