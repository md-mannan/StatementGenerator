import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CheckCircle2,
    FileOutput,
    FileSpreadsheet,
    GitCompareArrows,
    Inbox,
    Landmark,
    Layers,
    Plus,
    ScanLine,
    Upload,
} from 'lucide-react';
import {
    AppTable,
    AppTableHeadCell,
    AppTableScroll,
} from '@/components/app-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { pageShellClassName } from '@/lib/page-layout';
import { dashboard } from '@/routes';
import { create, generateStatement, index as clientsIndex, show } from '@/routes/clients';
import { index as annexureIndex } from '@/routes/clients/annexure';
import { index as crossCheckIndex } from '@/routes/clients/cross-check';
import { index as receivedStatementsIndex } from '@/routes/clients/received-statements';
import { index as statementsIndex } from '@/routes/branches/statements';
import { importMethod as statementsImport } from '@/routes/branches/statements';
import type { DashboardPayload } from '@/types';

function StatCard({
    title,
    value,
    description,
    icon: Icon,
}: {
    title: string;
    value: string | number;
    description: string;
    icon: typeof Building2;
}) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardDescription className="flex items-center gap-2">
                    <Icon className="size-4 shrink-0" />
                    {title}
                </CardDescription>
                <CardTitle className="text-2xl tabular-nums">{value}</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-xs text-muted-foreground">{description}</p>
            </CardContent>
        </Card>
    );
}

export default function Dashboard({
    overview,
    reconciliation,
    clients,
    recent_uploads,
}: DashboardPayload) {
    const hasClients = clients.length > 0;

    return (
        <>
            <Head title="Dashboard" />

            <div className={pageShellClassName}>
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Statement Analyzer
                        </h1>
                        <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                            Reconcile branch statements, received statements, and
                            annexure cheques across all clients in one place.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={create()}>
                            <Plus className="size-4" />
                            New client
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        title="Clients"
                        value={overview.clients}
                        description="Retailers you manage"
                        icon={Building2}
                    />
                    <StatCard
                        title="Branches"
                        value={overview.branches}
                        description="Locations with statements"
                        icon={Landmark}
                    />
                    <StatCard
                        title="Statement months"
                        value={overview.statement_months}
                        description="Distinct branch statement periods"
                        icon={Layers}
                    />
                    <StatCard
                        title="Invoice scans"
                        value={overview.invoice_scans}
                        description="Attached branch invoice scans"
                        icon={ScanLine}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Data volumes</CardTitle>
                            <CardDescription>
                                Imported rows and totals across your account
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="rounded-lg border bg-muted/20 p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Branch entries
                                    </p>
                                    <p className="mt-1 text-2xl font-semibold tabular-nums">
                                        {overview.branch_entries.toLocaleString()}
                                    </p>
                                    <p className="mt-1 font-mono text-sm tabular-nums">
                                        {overview.branch_total}
                                    </p>
                                </div>
                                <div className="rounded-lg border bg-muted/20 p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Received entries
                                    </p>
                                    <p className="mt-1 text-2xl font-semibold tabular-nums">
                                        {overview.received_entries.toLocaleString()}
                                    </p>
                                    <p className="mt-1 font-mono text-sm tabular-nums">
                                        {overview.received_total}
                                    </p>
                                </div>
                                <div className="rounded-lg border bg-muted/20 p-4">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Annexure entries
                                    </p>
                                    <p className="mt-1 text-2xl font-semibold tabular-nums">
                                        {overview.annexure_entries.toLocaleString()}
                                    </p>
                                    <p className="mt-1 font-mono text-sm tabular-nums">
                                        {overview.annexure_total}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <GitCompareArrows className="size-4" />
                                Reconciliation
                            </CardTitle>
                            <CardDescription>
                                Branch ↔ received matching; incomplete means
                                branch or received data is still missing
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Invoices tracked
                                </span>
                                <span className="font-semibold tabular-nums">
                                    {reconciliation.invoices.toLocaleString()}
                                </span>
                            </div>
                            <div className="flex items-center justify-between text-sm">
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <CheckCircle2 className="size-3.5 text-emerald-600" />
                                    Matched
                                </span>
                                <span className="font-semibold tabular-nums">
                                    {reconciliation.matched_count.toLocaleString()}
                                </span>
                            </div>
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Complete (cheque issued)
                                </span>
                                <span className="font-semibold tabular-nums">
                                    {reconciliation.complete_count.toLocaleString()}
                                </span>
                            </div>
                            <div className="flex items-center justify-between text-sm">
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <AlertTriangle className="size-3.5 text-orange-600" />
                                    Mismatches
                                </span>
                                <span className="font-semibold tabular-nums text-orange-700 dark:text-orange-400">
                                    {reconciliation.mismatch_count.toLocaleString()}
                                </span>
                            </div>
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Incomplete
                                </span>
                                <span className="font-semibold tabular-nums">
                                    {reconciliation.incomplete_count.toLocaleString()}
                                </span>
                            </div>
                            <div className="border-t pt-3 text-xs text-muted-foreground">
                                <p>
                                    Branch {reconciliation.branch_total}
                                </p>
                                <p className="mt-1">
                                    Received {reconciliation.received_total}
                                </p>
                                <p className="mt-1">
                                    Annexure {reconciliation.annexure_total}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {[
                        {
                            title: 'Branch statements',
                            description:
                                'Upload Excel per branch, reconcile client and cheque amounts.',
                            icon: FileSpreadsheet,
                            href: clientsIndex(),
                        },
                        {
                            title: 'Received statements',
                            description:
                                'Import what the client received and compare to branch data.',
                            icon: Inbox,
                            href: clientsIndex(),
                        },
                        {
                            title: 'Annexure & cheques',
                            description:
                                'Track cheque payments and annexure invoice lines.',
                            icon: Landmark,
                            href: clientsIndex(),
                        },
                        {
                            title: 'All invoices',
                            description:
                                'Cross-check branch, received, and annexure in one grid.',
                            icon: GitCompareArrows,
                            href: clientsIndex(),
                        },
                    ].map((item) => (
                        <Card key={item.title}>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <item.icon className="size-4" />
                                    {item.title}
                                </CardTitle>
                                <CardDescription>{item.description}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={item.href}>
                                        Open clients
                                        <ArrowRight className="size-3.5" />
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {hasClients ? (
                    <>
                        <Card>
                            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                                <div>
                                    <CardTitle>Clients overview</CardTitle>
                                    <CardDescription>
                                        Entries, totals, and reconciliation
                                        health per client
                                    </CardDescription>
                                </div>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={clientsIndex()}>View all</Link>
                                </Button>
                            </CardHeader>
                            <CardContent className="p-0">
                                <AppTableScroll className="rounded-none border-t">
                                    <AppTable>
                                        <thead>
                                            <tr>
                                                <AppTableHeadCell>
                                                    Client
                                                </AppTableHeadCell>
                                                <AppTableHeadCell>
                                                    Branches
                                                </AppTableHeadCell>
                                                <AppTableHeadCell>
                                                    Branch entries
                                                </AppTableHeadCell>
                                                <AppTableHeadCell>
                                                    Received
                                                </AppTableHeadCell>
                                                <AppTableHeadCell>
                                                    Annexure
                                                </AppTableHeadCell>
                                                <AppTableHeadCell className="text-right">
                                                    Branch total
                                                </AppTableHeadCell>
                                                <AppTableHeadCell>
                                                    Mismatches
                                                </AppTableHeadCell>
                                                <AppTableHeadCell>
                                                    Last upload
                                                </AppTableHeadCell>
                                                <AppTableHeadCell className="text-right">
                                                    Actions
                                                </AppTableHeadCell>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {clients.map((client) => (
                                                <tr
                                                    key={client.id}
                                                    className="border-t"
                                                >
                                                    <td className="px-4 py-3 font-medium">
                                                        <Link
                                                            href={show(
                                                                client.id,
                                                            )}
                                                            className="hover:underline"
                                                        >
                                                            {client.name}
                                                        </Link>
                                                    </td>
                                                    <td className="px-4 py-3 tabular-nums">
                                                        {client.branches_count}
                                                    </td>
                                                    <td className="px-4 py-3 tabular-nums">
                                                        {client.branch_entries.toLocaleString()}
                                                    </td>
                                                    <td className="px-4 py-3 tabular-nums">
                                                        {client.received_entries.toLocaleString()}
                                                    </td>
                                                    <td className="px-4 py-3 tabular-nums">
                                                        {client.annexure_entries.toLocaleString()}
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                        {client.branch_total}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {client.mismatch_count >
                                                        0 ? (
                                                            <Badge variant="destructive">
                                                                {
                                                                    client.mismatch_count
                                                                }
                                                            </Badge>
                                                        ) : (
                                                            <Badge variant="secondary">
                                                                0
                                                            </Badge>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-muted-foreground">
                                                        {client.last_upload_at ??
                                                            '—'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex justify-end gap-1">
                                                            <Button
                                                                variant="ghost"
                                                                size="sm"
                                                                asChild
                                                            >
                                                                <Link
                                                                    href={crossCheckIndex(
                                                                        client.id,
                                                                    )}
                                                                >
                                                                    Cross-check
                                                                </Link>
                                                            </Button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                        <tfoot>
                                            <tr className="border-t bg-muted/40 font-semibold">
                                                <td
                                                    className="px-4 py-3"
                                                    colSpan={2}
                                                >
                                                    Total
                                                </td>
                                                <td className="px-4 py-3 tabular-nums">
                                                    {clients
                                                        .reduce(
                                                            (sum, client) =>
                                                                sum +
                                                                client.branch_entries,
                                                            0,
                                                        )
                                                        .toLocaleString()}
                                                </td>
                                                <td className="px-4 py-3 tabular-nums">
                                                    {clients
                                                        .reduce(
                                                            (sum, client) =>
                                                                sum +
                                                                client.received_entries,
                                                            0,
                                                        )
                                                        .toLocaleString()}
                                                </td>
                                                <td className="px-4 py-3 tabular-nums">
                                                    {clients
                                                        .reduce(
                                                            (sum, client) =>
                                                                sum +
                                                                client.annexure_entries,
                                                            0,
                                                        )
                                                        .toLocaleString()}
                                                </td>
                                                <td className="px-4 py-3 text-right font-mono tabular-nums">
                                                    {overview.branch_total}
                                                </td>
                                                <td className="px-4 py-3 tabular-nums">
                                                    {reconciliation.mismatch_count.toLocaleString()}
                                                </td>
                                                <td colSpan={2} />
                                            </tr>
                                        </tfoot>
                                    </AppTable>
                                </AppTableScroll>
                            </CardContent>
                        </Card>

                        {recent_uploads.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Upload className="size-4" />
                                        Recent branch uploads
                                    </CardTitle>
                                    <CardDescription>
                                        Latest statement data by branch
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <AppTableScroll className="rounded-none border-t">
                                        <AppTable>
                                            <thead>
                                                <tr>
                                                    <AppTableHeadCell>
                                                        Client
                                                    </AppTableHeadCell>
                                                    <AppTableHeadCell>
                                                        Branch
                                                    </AppTableHeadCell>
                                                    <AppTableHeadCell>
                                                        Entries
                                                    </AppTableHeadCell>
                                                    <AppTableHeadCell>
                                                        Uploaded
                                                    </AppTableHeadCell>
                                                    <AppTableHeadCell className="text-right">
                                                        Open
                                                    </AppTableHeadCell>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {recent_uploads.map((upload) => (
                                                    <tr
                                                        key={`${upload.branch_id}-${upload.uploaded_at}`}
                                                        className="border-t"
                                                    >
                                                        <td className="px-4 py-3">
                                                            {upload.client_id ? (
                                                                <Link
                                                                    href={show(
                                                                        upload.client_id,
                                                                    )}
                                                                    className="hover:underline"
                                                                >
                                                                    {
                                                                        upload.client_name
                                                                    }
                                                                </Link>
                                                            ) : (
                                                                upload.client_name
                                                            )}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <span className="font-mono">
                                                                {
                                                                    upload.branch_code
                                                                }
                                                            </span>
                                                            <span className="ml-2 text-muted-foreground">
                                                                {
                                                                    upload.branch_name
                                                                }
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 tabular-nums">
                                                            {upload.entries_count.toLocaleString()}
                                                        </td>
                                                        <td className="px-4 py-3 text-sm text-muted-foreground">
                                                            {upload.uploaded_at ??
                                                                '—'}
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <div className="flex justify-end gap-1">
                                                                {upload.branch_id && (
                                                                    <>
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            asChild
                                                                        >
                                                                            <Link
                                                                                href={statementsIndex(
                                                                                    upload.branch_id,
                                                                                )}
                                                                            >
                                                                                Statement
                                                                            </Link>
                                                                        </Button>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="sm"
                                                                            asChild
                                                                        >
                                                                            <Link
                                                                                href={statementsImport(
                                                                                    upload.branch_id,
                                                                                )}
                                                                            >
                                                                                Upload
                                                                            </Link>
                                                                        </Button>
                                                                    </>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </AppTable>
                                    </AppTableScroll>
                                </CardContent>
                            </Card>
                        )}
                    </>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle>Get started</CardTitle>
                            <CardDescription>
                                Create a client, add branches, then upload Excel
                                statements with Date (dd/mm/yyyy), Invoice No,
                                and Amount columns.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-3">
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    Create first client
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={clientsIndex()}>Browse clients</Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {hasClients && (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {clients.slice(0, 6).map((client) => (
                            <Card key={client.id}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        <Link
                                            href={show(client.id)}
                                            className="hover:underline"
                                        >
                                            {client.name}
                                        </Link>
                                    </CardTitle>
                                    <CardDescription>
                                        {client.branches_count} branches ·{' '}
                                        {client.statement_months} months ·{' '}
                                        {client.cross_check_invoices} invoices
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={crossCheckIndex(client.id)}
                                        >
                                            <GitCompareArrows className="size-3.5" />
                                            Invoices
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={show(client.id)}>
                                            <Landmark className="size-3.5" />
                                            Branches
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={receivedStatementsIndex(
                                                client.id,
                                            )}
                                        >
                                            <Inbox className="size-3.5" />
                                            Received
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={annexureIndex(client.id)}>
                                            <FileSpreadsheet className="size-3.5" />
                                            Annexure
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={generateStatement(
                                                client.id,
                                            )}
                                        >
                                            <FileOutput className="size-3.5" />
                                            Generate
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
