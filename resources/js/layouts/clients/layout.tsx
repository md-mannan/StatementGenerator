import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { usePage } from '@inertiajs/react';
import { ClientPageShell } from '@/components/client-page-shell';
import { useCurrentUrl } from '@/hooks/use-current-url';
import {
    preservedTabQuery,
    reconciliationQueryRecord,
} from '@/lib/reconciliation-url';
import {
    clientTabLinkClassName,
    clientTabNavClassName,
} from '@/lib/page-layout';
import { cn, toUrl } from '@/lib/utils';
import { generateStatement, show } from '@/routes/clients';
import { index as annexureIndex } from '@/routes/clients/annexure';
import { index as crossCheckIndex } from '@/routes/clients/cross-check';
import { index as receivedStatements } from '@/routes/clients/received-statements';
import type { Client, ClientSummary } from '@/types';

type ClientPageProps = {
    client: Pick<Client, 'id' | 'name'>;
    summary?: ClientSummary;
};

function isClientTabActive(tabPath: string, currentUrl: string): boolean {
    if (/^\/clients\/\d+$/.test(tabPath)) {
        return currentUrl === tabPath;
    }

    return currentUrl === tabPath || currentUrl.startsWith(`${tabPath}/`);
}

export default function ClientLayout({ children }: PropsWithChildren) {
    const { client, summary } = usePage<ClientPageProps>().props;
    const { currentUrl } = useCurrentUrl();
    const tabQuery = reconciliationQueryRecord(
        preservedTabQuery(currentUrl),
    );

    const tabs = [
        {
            title: 'All Invoices',
            href: crossCheckIndex.url(client.id, { query: tabQuery }),
        },
        {
            title: 'Branches',
            href: show.url(client.id, { query: tabQuery }),
        },
        {
            title: 'Received Statements',
            href: receivedStatements.url(client.id, { query: tabQuery }),
        },
        {
            title: 'Annexure',
            href: annexureIndex.url(client.id, { query: tabQuery }),
        },
        {
            title: 'Generate Statement',
            href: generateStatement.url(client.id, { query: tabQuery }),
        },
    ];

    const isInvoicePage = summary?.context === 'invoice';

    return (
        <div
            className={cn(
                'flex min-w-0 flex-col',
                isInvoicePage ? 'gap-2 p-2 sm:p-3' : 'gap-4 p-3 sm:gap-6 sm:p-4 lg:p-6',
            )}
        >
            {summary && !isInvoicePage && (
                <ClientPageShell client={client} summary={summary} />
            )}

            {!isInvoicePage && (
                <nav className={cn(clientTabNavClassName, 'mobile-scroll-hint')}>
                    {tabs.map((tab) => {
                        const tabPath = toUrl(tab.href);
                        const isActive = isClientTabActive(tabPath, currentUrl);

                        return (
                            <Link
                                key={tabPath}
                                href={tab.href}
                                className={cn(
                                    clientTabLinkClassName,
                                    isActive
                                        ? 'border-border bg-background text-primary shadow-sm'
                                        : 'border-transparent text-muted-foreground hover:bg-muted/50 hover:text-foreground',
                                )}
                            >
                                {tab.title}
                            </Link>
                        );
                    })}
                </nav>
            )}

            {children}
        </div>
    );
}
