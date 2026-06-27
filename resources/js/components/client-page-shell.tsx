import { Form, Link, usePage } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import ClientController from '@/actions/App/Http/Controllers/ClientController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { mergeReconciliationQuery } from '@/lib/reconciliation-url';
import { cn } from '@/lib/utils';
import { edit } from '@/routes/clients';
import { index as crossCheckIndex } from '@/routes/clients/cross-check';
import { index as receivedStatementsIndex } from '@/routes/clients/received-statements';
import type { Client, ClientSummary } from '@/types';

function SummaryCardLink({
    href,
    children,
    className,
}: {
    href: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <Link
            href={href}
            className={cn(
                'block rounded-xl transition-shadow hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                className,
            )}
        >
            {children}
        </Link>
    );
}

function summaryDescription(summary: ClientSummary): string {
    switch (summary.context) {
        case 'branches':
            return 'Add branches and upload monthly statement files.';
        case 'generate_statement':
            return 'Export combined branch statements for a month.';
        case 'received_statements':
            return 'Upload and review what the client received.';
        case 'annexure':
            return 'Import annexure data and track cheque payments.';
        case 'cross_check':
            return 'Find any invoice and see all amounts in one view.';
        case 'statement_view':
            return 'Combined branch statement compared with received amounts.';
        case 'invoice':
            return 'Compare branch, received, and annexure amounts for this invoice.';
    }
}

function SummaryCards({
    client,
    summary,
    currentUrl,
}: {
    client: Pick<Client, 'id' | 'name'>;
    summary: ClientSummary;
    currentUrl: string;
}) {
    switch (summary.context) {
        case 'branches':
            return (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Branches</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.branches}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Branch months</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.branch_months}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0 text-sm text-muted-foreground">
                            Rows with uploaded data
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Total entries</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.entries}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Branch total amount</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.total_amount}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </div>
            );

        case 'generate_statement':
            return (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Branches</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.branches}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>With statement data</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.branches_with_data}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Statement months</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.statement_months}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Combined total</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.total_amount}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </div>
            );

        case 'received_statements':
            return (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>
                                {summary.period_label}
                            </CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.entries} entries
                            </CardTitle>
                        </CardHeader>
                        {summary.saved_months !== undefined && (
                            <CardContent className="pt-0 text-sm text-muted-foreground">
                                {summary.saved_months} months uploaded
                            </CardContent>
                        )}
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Client total</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.client_total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Branch total</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.branch_total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <SummaryCardLink
                        href={receivedStatementsIndex.url(client.id, {
                            query: mergeReconciliationQuery(currentUrl, {
                                filter: 'mismatches',
                            }),
                        })}
                    >
                        <Card className="h-full border-orange-200/80 dark:border-orange-900/50">
                            <CardHeader className="pb-2">
                                <CardDescription>Difference</CardDescription>
                                <CardTitle className="text-2xl">
                                    {summary.difference_total}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="pt-0 text-sm text-muted-foreground">
                                {summary.unresolved_count} unresolved ·{' '}
                                {summary.mismatch_count} mismatches
                                <span className="mt-1 block text-primary">
                                    View mismatches →
                                </span>
                            </CardContent>
                        </Card>
                    </SummaryCardLink>
                </div>
            );

        case 'annexure':
            return (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>
                                {summary.period_label}
                            </CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.entries} entries
                            </CardTitle>
                        </CardHeader>
                        {summary.saved_months !== undefined && (
                            <CardContent className="pt-0 text-sm text-muted-foreground">
                                {summary.saved_months} months uploaded
                            </CardContent>
                        )}
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Client total</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.client_total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Check total</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.check_total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Rebate</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.rebate}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Net amount</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.net_amount}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                </div>
            );

        case 'cross_check':
            return (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Unique invoices</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.entries}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0 text-sm text-muted-foreground">
                            {summary.statement_months} statement months ·{' '}
                            {summary.branches} branches
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Branch total</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.branch_total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Received total</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.received_total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Annexure total</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.annexure_total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <SummaryCardLink
                        href={crossCheckIndex.url(client.id, {
                            query: mergeReconciliationQuery(currentUrl, {
                                status: ['mismatch'],
                            }),
                        })}
                    >
                        <Card className="h-full border-primary/20">
                            <CardHeader className="pb-2">
                                <CardDescription>Reconciliation</CardDescription>
                                <CardTitle className="text-2xl">
                                    {summary.matched_count} matched
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="pt-0 text-sm text-muted-foreground">
                                {summary.complete_count} complete ·{' '}
                                {summary.mismatch_count} mismatches ·{' '}
                                {summary.incomplete_count} incomplete
                                <span className="mt-1 block text-primary">
                                    Review mismatches →
                                </span>
                            </CardContent>
                        </Card>
                    </SummaryCardLink>
                </div>
            );

        case 'statement_view':
            return (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>
                                {summary.period_label}
                            </CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.entries} entries
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Branch total</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.branch_total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Received total</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.client_total}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Difference</CardDescription>
                            <CardTitle className="text-2xl">
                                {summary.difference_total}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0 text-sm text-muted-foreground">
                            {summary.unresolved_count} unresolved ·{' '}
                            {summary.mismatch_count} mismatches
                        </CardContent>
                    </Card>
                </div>
            );

        case 'invoice':
            return (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Branch amount</CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {summary.branch_amount}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Received amount</CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {summary.received_amount}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Annexure amount</CardDescription>
                            <CardTitle className="text-2xl tabular-nums">
                                {summary.annexure_amount}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardDescription>Cheque</CardDescription>
                            <CardTitle className="text-2xl font-mono tabular-nums">
                                {summary.cheque_number ?? '—'}
                            </CardTitle>
                        </CardHeader>
                        {summary.cheque_period && (
                            <CardContent className="pt-0 text-sm text-muted-foreground">
                                Cheque month {summary.cheque_period}
                            </CardContent>
                        )}
                    </Card>
                </div>
            );
    }
}

export function ClientPageShell({
    client,
    summary,
}: {
    client: Pick<Client, 'id' | 'name'>;
    summary: ClientSummary;
}) {
    const { url: currentUrl } = usePage();

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-4">
                <Heading
                    title={client.name}
                    description={summaryDescription(summary)}
                />
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" asChild>
                        <Link href={edit(client.id)}>
                            <Pencil className="size-4" />
                            Edit client
                        </Link>
                    </Button>
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="destructive">
                                <Trash2 className="size-4" />
                                Delete client
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogTitle>Delete client?</DialogTitle>
                            <DialogDescription>
                                This will permanently delete {client.name}, all
                                branches, and all statement entries.
                            </DialogDescription>
                            <Form {...ClientController.destroy.form(client.id)}>
                                {({ processing }) => (
                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button variant="secondary">
                                                Cancel
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            variant="destructive"
                                            disabled={processing}
                                            asChild
                                        >
                                            <button type="submit">
                                                Delete client
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                )}
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <SummaryCards
                client={client}
                summary={summary}
                currentUrl={currentUrl}
            />
        </>
    );
}
