import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { GlobalSearchProvider } from '@/components/global-search';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
};

export function AppShell({ children, variant = 'sidebar' }: Props) {
    const isOpen = usePage().props.sidebarOpen;

    if (variant === 'header') {
        return (
            <GlobalSearchProvider>
                <div className="flex min-h-screen w-full flex-col">{children}</div>
            </GlobalSearchProvider>
        );
    }

    return (
        <GlobalSearchProvider>
            <SidebarProvider defaultOpen={isOpen}>{children}</SidebarProvider>
        </GlobalSearchProvider>
    );
}
