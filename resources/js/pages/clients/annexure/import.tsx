import { Form, Head, Link, usePage } from '@inertiajs/react';
import ClientAnnexureImportController from '@/actions/App/Http/Controllers/ClientAnnexureImportController';
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
import { index as annexureIndex } from '@/routes/clients/annexure';
import type { Client, ClientSummary } from '@/types';

export default function ClientsAnnexureImport({
    client,
}: {
    client: Pick<Client, 'id' | 'name'>;
    summary: ClientSummary;
}) {
    const { url } = usePage();
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const year = params.get('year');
    const month = params.get('month');
    const periodLabel =
        year && month
            ? new Date(Number(year), Number(month) - 1, 1).toLocaleString(
                  undefined,
                  { month: 'long', year: 'numeric' },
              )
            : null;

    return (
        <>
            <Head title={`Upload annexure - ${client.name}`} />

            <Card>
                <CardHeader>
                    <CardTitle>
                        {periodLabel
                            ? `Add cheque — ${periodLabel}`
                            : 'Upload client annexure'}
                    </CardTitle>
                    <CardDescription>
                        Upload an Excel file with Date, Invoice, and Amount. A
                        new cheque batch will be created for review.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Form
                        {...ClientAnnexureImportController.store.form(
                            client.id,
                        )}
                        encType="multipart/form-data"
                        className="space-y-6"
                    >
                        {({ processing, errors, progress }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="file">Annexure file</Label>
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

                                <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
                                    <p className="font-medium text-foreground">
                                        Expected columns
                                    </p>
                                    <ul className="mt-2 list-disc pl-5">
                                        <li>Date (dd/mm/yyyy or Excel date)</li>
                                        <li>Invoice / Invoice No</li>
                                        <li>Amount (or Client Amount)</li>
                                    </ul>
                                    <p className="mt-2">
                                        The first row must be column headers. Extra
                                        columns like Sl or Branch ID are ignored.
                                    </p>
                                </div>

                                <div className="flex flex-wrap gap-3">
                                    <Button type="submit" disabled={processing}>
                                        {processing
                                            ? 'Importing...'
                                            : 'Import annexure'}
                                    </Button>
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={annexureIndex.url(client.id, {
                                                query:
                                                    year && month
                                                        ? {
                                                              year: Number(
                                                                  year,
                                                              ),
                                                              month: Number(
                                                                  month,
                                                              ),
                                                          }
                                                        : undefined,
                                            })}
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

ClientsAnnexureImport.layout = () => ({
    breadcrumbs: [{ title: 'Upload Annexure', href: '#' }],
});
