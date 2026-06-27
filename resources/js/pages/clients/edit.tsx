import { Form, Head, Link } from '@inertiajs/react';
import ClientController from '@/actions/App/Http/Controllers/ClientController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, index, show } from '@/routes/clients';
import type { Client } from '@/types';

export default function ClientsEdit({ client }: { client: Client }) {
    return (
        <>
            <Head title={`Edit ${client.name}`} />

            <div className="mx-auto max-w-xl p-4">
                <Heading
                    title="Edit client"
                    description="Update the client name"
                />

                <Form
                    {...ClientController.update.form(client.id)}
                    className="space-y-6 rounded-xl border p-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Client name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={client.name}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="flex gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving...' : 'Save changes'}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={show(client.id)}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

ClientsEdit.layout = (props: { client: Client }) => ({
    breadcrumbs: [
        { title: 'Clients', href: index() },
        { title: props.client.name, href: show(props.client.id) },
        { title: 'Edit', href: edit(props.client.id) },
    ],
});
