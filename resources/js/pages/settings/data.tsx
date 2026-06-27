import { Form, Head } from '@inertiajs/react';
import { DatabaseBackup, Download, Upload } from 'lucide-react';
import DataController from '@/actions/App/Http/Controllers/Settings/DataController';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, exportMethod } from '@/routes/data';

type DatabaseSummary = {
    driver: string;
    database: string;
    tables: number;
    mysqldump_available: boolean;
};

export default function DataSettings({ summary }: { summary: DatabaseSummary }) {
    return (
        <>
            <Head title="Database backup" />

            <h1 className="sr-only">Database backup and restore</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Database backup"
                    description="Download a complete copy of the entire application database and restore it when needed."
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <DatabaseBackup className="size-5" />
                            Database
                        </CardTitle>
                        <CardDescription>
                            {summary.database} ({summary.driver}) with{' '}
                            {summary.tables} tables.
                            {summary.mysqldump_available
                                ? ' Backups use mysqldump for a full SQL export.'
                                : ' Backups are generated from the application when mysqldump is unavailable.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button asChild>
                            <a href={exportMethod.url()}>
                                <Download className="size-4" />
                                Download database backup
                            </a>
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Upload className="size-5" />
                            Restore database
                        </CardTitle>
                        <CardDescription>
                            Upload a .sql.gz database backup created by this
                            application. This replaces the entire database,
                            including all users, clients, and settings.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...DataController.restore.form()}
                            encType="multipart/form-data"
                            className="space-y-6"
                        >
                            {({ processing, errors, progress }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="backup">
                                            Database backup (.sql.gz)
                                        </Label>
                                        <Input
                                            id="backup"
                                            name="backup"
                                            type="file"
                                            accept=".sql.gz,.sql,.zip,application/gzip,application/x-gzip,application/zip"
                                            required
                                        />
                                        <InputError message={errors.backup} />
                                        {progress && (
                                            <p className="text-sm text-muted-foreground">
                                                Uploading… {progress.percentage}%
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-4">
                                        <Checkbox
                                            id="confirm"
                                            name="confirm"
                                            value="1"
                                            required
                                        />
                                        <div className="grid gap-1">
                                            <Label
                                                htmlFor="confirm"
                                                className="leading-snug"
                                            >
                                                I understand this will replace
                                                the entire database
                                            </Label>
                                            <p className="text-sm text-muted-foreground">
                                                All users, clients, and records
                                                will be overwritten. You will be
                                                asked to confirm your password,
                                                then signed out after restore.
                                            </p>
                                            <InputError message={errors.confirm} />
                                        </div>
                                    </div>

                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        {processing
                                            ? 'Restoring database…'
                                            : 'Restore database'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <p className="text-sm text-muted-foreground">
                    Store database backups safely. Use them before server moves,
                    reinstalls, or major upgrades. For cPanel, you can also use
                    phpMyAdmin export as an extra copy.
                </p>
            </div>
        </>
    );
}

DataSettings.layout = {
    breadcrumbs: [{ title: 'Database backup', href: edit() }],
};
