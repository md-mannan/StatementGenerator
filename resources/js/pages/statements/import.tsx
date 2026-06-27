import { Form, Head, Link } from '@inertiajs/react';
import StatementImportController from '@/actions/App/Http/Controllers/StatementImportController';
import Heading from '@/components/heading';
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
import { show as clientShow } from '@/routes/clients';
import { index as statementsIndex } from '@/routes/branches/statements';
import type { Branch, Client } from '@/types';

type Props = {
    branch: Branch;
    client: Client;
    year: number | null;
    month: number | null;
};

function formatPeriodLabel(yearValue: number, monthValue: number): string {
    return new Date(yearValue, monthValue - 1, 1).toLocaleString(undefined, {
        month: 'long',
        year: 'numeric',
    });
}

export default function StatementsImport({ branch, client, year, month }: Props) {
    const periodLabel =
        year !== null && month !== null
            ? formatPeriodLabel(year, month)
            : null;

    return (
        <>
            <Head title={`Upload statement - ${branch.name}`} />

            <div className="mx-auto max-w-2xl p-4">
                <Heading
                    title="Upload statement"
                    description={`Import Excel data for ${client.name} — ${branch.name} (${branch.code})`}
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Excel file</CardTitle>
                        <CardDescription>
                            Your spreadsheet must include columns: Invoice Date
                            (dd/mm/yyyy), Invoice No, and Amount.
                            {periodLabel
                                ? ` Rows will be saved to the ${periodLabel} statement even when the invoice date is in another month.`
                                : ' If you upload from a specific month view, rows are assigned to that statement month.'}{' '}
                            Supported file formats: .xlsx, .xls, .csv
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...StatementImportController.store.form(branch.id)}
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
                                        <Label htmlFor="file">
                                            Statement file
                                        </Label>
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
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing
                                                ? 'Importing...'
                                                : 'Import statement'}
                                        </Button>
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={
                                                    year !== null && month !== null
                                                        ? statementsIndex.url(
                                                              branch.id,
                                                              {
                                                                  query: {
                                                                      year,
                                                                      month,
                                                                  },
                                                              },
                                                          )
                                                        : statementsIndex(
                                                              branch.id,
                                                          )
                                                }
                                            >
                                                Cancel
                                            </Link>
                                        </Button>
                                        <Button variant="ghost" asChild>
                                            <Link href={clientShow(client.id)}>
                                                Back to client
                                            </Link>
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

StatementsImport.layout = () => ({
    breadcrumbs: [{ title: 'Upload statement', href: '#' }],
});
