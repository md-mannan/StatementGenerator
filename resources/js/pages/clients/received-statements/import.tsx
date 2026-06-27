import { Form, Head, Link } from '@inertiajs/react';
import IncomingStatementImportController from '@/actions/App/Http/Controllers/IncomingStatementImportController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as receivedStatementsIndex } from '@/routes/clients/received-statements';
import type { Client, ClientSummary } from '@/types';

type Props = {
    client: Pick<Client, 'id' | 'name'>;
    summary: ClientSummary;
    year: number | null;
    month: number | null;
};

function formatPeriodLabel(yearValue: number, monthValue: number): string {
    return new Date(yearValue, monthValue - 1, 1).toLocaleString(undefined, {
        month: 'long',
        year: 'numeric',
    });
}

export default function ClientsReceivedStatementsImport({
    client,
    year,
    month,
}: Props) {
    const periodLabel =
        year !== null && month !== null
            ? formatPeriodLabel(year, month)
            : null;

    return (
        <>
            <Head title={`Upload received statement - ${client.name}`} />

            <Card>
                <CardHeader>
                    <CardTitle>Upload client statement</CardTitle>
                    <CardDescription>
                        The client sends Invoice Date, Invoice No, and Amount.
                        Branch ID is looked up automatically from your existing
                        supplier statements for {client.name}. After import,
                        entries are grouped by invoice month from each invoice
                        date.
                        {periodLabel
                            ? ` You opened upload from ${periodLabel}.`
                            : ''}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        {...IncomingStatementImportController.store.form(
                            client.id,
                        )}
                        encType="multipart/form-data"
                        className="space-y-6"
                    >
                        {({ processing, errors, progress }) => (
                            <>
                                {year !== null && month !== null && (
                                    <>
                                        <input
                                            type="hidden"
                                            name="year"
                                            value={year}
                                        />
                                        <input
                                            type="hidden"
                                            name="month"
                                            value={month}
                                        />
                                    </>
                                )}
                                <div className="grid gap-2">
                                    <Label htmlFor="file">Statement file</Label>
                                    <Input
                                        id="file"
                                        name="file"
                                        type="file"
                                        accept=".xlsx,.xls,.csv"
                                        required
                                    />
                                    <InputError message={errors.file} />
                                    {progress && (
                                        <p className="text-sm text-muted-foreground">
                                            Uploading… {progress.percentage}%
                                        </p>
                                    )}
                                </div>

                                <div className="flex flex-wrap gap-3">
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Importing...'
                                            : 'Import statement'}
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={receivedStatementsIndex.url(
                                                client.id,
                                                {
                                                    query:
                                                        year !== null &&
                                                        month !== null
                                                            ? { year, month }
                                                            : undefined,
                                                },
                                            )}
                                        >
                                            Cancel
                                        </Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </CardContent>
            </Card>
        </>
    );
}

ClientsReceivedStatementsImport.layout = () => ({
    breadcrumbs: [{ title: 'Upload received statement', href: '#' }],
});
