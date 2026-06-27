import { Form, Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

type RequirementItem = {
    label: string;
    passed: boolean;
    message: string;
};

type Props = {
    requirements: {
        php_version: RequirementItem;
        extensions: RequirementItem[];
        permissions: RequirementItem[];
        ready: boolean;
    };
    defaults: {
        db_host: string;
        db_port: string;
        db_database: string;
        db_username: string;
        app_name: string;
        app_url: string;
    };
    passwordRules: string;
};

const steps = [
  { id: 'requirements', title: 'Requirements' },
  { id: 'database', title: 'Database' },
  { id: 'application', title: 'Application' },
  { id: 'administrator', title: 'Administrator' },
] as const;

type StepId = (typeof steps)[number]['id'];

function RequirementRow({ item }: { item: RequirementItem }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-border py-3 last:border-b-0">
            <div>
                <p className="text-sm font-medium">{item.label}</p>
                <p className="text-xs text-muted-foreground">{item.message}</p>
            </div>
            <span
                className={cn(
                    'rounded-full px-2 py-0.5 text-xs font-medium',
                    item.passed
                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                        : 'bg-destructive/10 text-destructive',
                )}
            >
                {item.passed ? 'Pass' : 'Fail'}
            </span>
        </div>
    );
}

function readCsrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

export default function SetupIndex({ requirements, defaults, passwordRules }: Props) {
    const [step, setStep] = useState<StepId>('requirements');
    const [databaseTested, setDatabaseTested] = useState(false);
    const [databaseTestMessage, setDatabaseTestMessage] = useState<string | null>(
        null,
    );
    const [databaseTestFailed, setDatabaseTestFailed] = useState(false);
    const [testingDatabase, setTestingDatabase] = useState(false);

    const form = useForm({
        db_host: defaults.db_host,
        db_port: defaults.db_port,
        db_database: defaults.db_database,
        db_username: defaults.db_username,
        db_password: '',
        app_name: defaults.app_name,
        app_url: defaults.app_url,
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

  const currentStepIndex = steps.findIndex((wizardStep) => wizardStep.id === step);

    useEffect(() => {
        setDatabaseTested(false);
        setDatabaseTestFailed(false);
        setDatabaseTestMessage(null);
    }, [
        form.data.db_host,
        form.data.db_port,
        form.data.db_database,
        form.data.db_username,
        form.data.db_password,
    ]);

    const goNext = () => {
        const nextStep = steps[currentStepIndex + 1];

        if (nextStep) {
            setStep(nextStep.id);
        }
    };

    const goBack = () => {
        const previousStep = steps[currentStepIndex - 1];

        if (previousStep) {
            setStep(previousStep.id);
        }
    };

    const testDatabase = async () => {
        setTestingDatabase(true);
        setDatabaseTested(false);
        setDatabaseTestFailed(false);
        setDatabaseTestMessage(null);
        form.clearErrors('db_host', 'db_port', 'db_database', 'db_username', 'db_password', 'db_connection');

        try {
            const response = await fetch('/setup/database/test', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': readCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    db_host: form.data.db_host,
                    db_port: form.data.db_port,
                    db_database: form.data.db_database,
                    db_username: form.data.db_username,
                    db_password: form.data.db_password,
                }),
            });

            const payload = (await response.json()) as {
                message?: string;
                errors?: Record<string, string[]>;
            };

            if (! response.ok) {
                setDatabaseTestFailed(true);
                setDatabaseTestMessage(
                    payload.message ??
                        'Could not connect to the database. Check your credentials and try again.',
                );

                if (payload.errors) {
                    Object.entries(payload.errors).forEach(([field, messages]) => {
                        form.setError(field as keyof typeof form.data, messages[0] ?? '');
                    });
                }

                return;
            }

            setDatabaseTested(true);
            setDatabaseTestMessage(
                payload.message ?? 'Database connection successful.',
            );
        } catch {
            setDatabaseTestFailed(true);
            setDatabaseTestMessage(
                'Could not reach the server to test the database connection.',
            );
        } finally {
            setTestingDatabase(false);
        }
    };

    const install = () => {
        form.post('/setup/install', {
            preserveState: true,
            onSuccess: () => {
                window.location.assign('/dashboard');
            },
            onError: (errors) => {
                if (errors.db_host || errors.db_database || errors.db_connection) {
                    setStep('database');
                } else if (errors.app_name || errors.app_url) {
                    setStep('application');
                } else {
                    setStep('administrator');
                }
            },
        });
    };

    return (
        <>
            <Head title="Setup" />
            <div className="min-h-svh bg-background px-4 py-10">
                <div className="mx-auto flex w-full max-w-2xl flex-col gap-8">
                    <div className="flex flex-col items-center gap-3 text-center">
                        <AppLogoIcon className="size-10 fill-current text-foreground" />
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Welcome to {form.data.app_name || 'Statement Analyzer'}
                            </h1>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Complete this quick setup before using the app.
                            </p>
                        </div>
                    </div>

                    <div className="grid grid-cols-4 gap-2">
                        {steps.map((wizardStep, index) => (
                            <div key={wizardStep.id} className="space-y-2">
                                <div
                                    className={cn(
                                        'h-1 rounded-full',
                                        index <= currentStepIndex
                                            ? 'bg-primary'
                                            : 'bg-muted',
                                    )}
                                />
                                <p
                                    className={cn(
                                        'text-center text-xs font-medium',
                                        index === currentStepIndex
                                            ? 'text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {wizardStep.title}
                                </p>
                            </div>
                        ))}
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {steps[currentStepIndex]?.title}
                            </CardTitle>
                            <CardDescription>
                                {step === 'requirements' &&
                                    'Make sure your server meets the minimum requirements.'}
                                {step === 'database' &&
                                    'Enter the database connection details for this installation.'}
                                {step === 'application' &&
                                    'Set the application name shown in the browser, sidebar, and emails.'}
                                {step === 'administrator' &&
                                    'Create the first administrator account.'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {step === 'requirements' && (
                                <div className="divide-y divide-border rounded-lg border border-border px-4">
                                    <RequirementRow item={requirements.php_version} />
                                    {requirements.extensions.map((item) => (
                                        <RequirementRow
                                            key={item.label}
                                            item={item}
                                        />
                                    ))}
                                    {requirements.permissions.map((item) => (
                                        <RequirementRow
                                            key={item.label}
                                            item={item}
                                        />
                                    ))}
                                </div>
                            )}

                            {step === 'database' && (
                                <div className="grid gap-4">
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="db_host">Database host</Label>
                                            <Input
                                                id="db_host"
                                                value={form.data.db_host}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'db_host',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError message={form.errors.db_host} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="db_port">Port</Label>
                                            <Input
                                                id="db_port"
                                                value={form.data.db_port}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'db_port',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError message={form.errors.db_port} />
                                        </div>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="db_database">Database name</Label>
                                        <Input
                                            id="db_database"
                                            value={form.data.db_database}
                                            onChange={(event) =>
                                                form.setData(
                                                    'db_database',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError message={form.errors.db_database} />
                                        <InputError message={form.errors.db_connection} />
                                    </div>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="db_username">Username</Label>
                                            <Input
                                                id="db_username"
                                                value={form.data.db_username}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'db_username',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError message={form.errors.db_username} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="db_password">Password</Label>
                                            <Input
                                                id="db_password"
                                                type="password"
                                                value={form.data.db_password}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'db_password',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError message={form.errors.db_password} />
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-3">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => void testDatabase()}
                                            disabled={testingDatabase}
                                        >
                                            {testingDatabase && <Spinner />}
                                            Test connection
                                        </Button>
                                        {databaseTested && databaseTestMessage && (
                                            <p className="text-sm text-emerald-600 dark:text-emerald-400">
                                                {databaseTestMessage}
                                            </p>
                                        )}
                                        {databaseTestFailed && databaseTestMessage && (
                                            <p className="text-sm text-destructive">
                                                {databaseTestMessage}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}

                            {step === 'application' && (
                                <div className="grid gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="app_name">Application name</Label>
                                        <Input
                                            id="app_name"
                                            value={form.data.app_name}
                                            onChange={(event) =>
                                                form.setData(
                                                    'app_name',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError message={form.errors.app_name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="app_url">Application URL</Label>
                                        <Input
                                            id="app_url"
                                            type="url"
                                            value={form.data.app_url}
                                            onChange={(event) =>
                                                form.setData(
                                                    'app_url',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError message={form.errors.app_url} />
                                    </div>
                                </div>
                            )}

                            {step === 'administrator' && (
                                <Form
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        install();
                                    }}
                                    className="grid gap-4"
                                >
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="admin_app_name">
                                                Application name
                                            </Label>
                                            <Input
                                                id="admin_app_name"
                                                value={form.data.app_name}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'app_name',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError message={form.errors.app_name} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="admin_app_url">
                                                Application URL
                                            </Label>
                                            <Input
                                                id="admin_app_url"
                                                type="url"
                                                value={form.data.app_url}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'app_url',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError message={form.errors.app_url} />
                                        </div>
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            value={form.data.name}
                                            onChange={(event) =>
                                                form.setData('name', event.target.value)
                                            }
                                        />
                                        <InputError message={form.errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            value={form.data.email}
                                            onChange={(event) =>
                                                form.setData('email', event.target.value)
                                            }
                                        />
                                        <InputError message={form.errors.email} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="password">Password</Label>
                                        <PasswordInput
                                            id="password"
                                            value={form.data.password}
                                            onChange={(event) =>
                                                form.setData(
                                                    'password',
                                                    event.target.value,
                                                )
                                            }
                                            passwordrules={passwordRules}
                                        />
                                        <InputError message={form.errors.password} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="password_confirmation">
                                            Confirm password
                                        </Label>
                                        <PasswordInput
                                            id="password_confirmation"
                                            value={form.data.password_confirmation}
                                            onChange={(event) =>
                                                form.setData(
                                                    'password_confirmation',
                                                    event.target.value,
                                                )
                                            }
                                            passwordrules={passwordRules}
                                        />
                                        <InputError
                                            message={form.errors.password_confirmation}
                                        />
                                    </div>
                                    <InputError message={form.errors.requirements} />
                                </Form>
                            )}

                            <div className="flex items-center justify-between gap-3 border-t border-border pt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={goBack}
                                    disabled={currentStepIndex === 0 || form.processing}
                                >
                                    Back
                                </Button>

                                {step === 'administrator' ? (
                                    <Button
                                        type="button"
                                        onClick={install}
                                        disabled={form.processing}
                                    >
                                        {form.processing && <Spinner />}
                                        Install Statement Analyzer
                                    </Button>
                                ) : (
                                    <Button
                                        type="button"
                                        onClick={goNext}
                                        disabled={
                                            (step === 'requirements' &&
                                                !requirements.ready) ||
                                            (step === 'database' && !databaseTested) ||
                                            form.processing
                                        }
                                    >
                                        Continue
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
