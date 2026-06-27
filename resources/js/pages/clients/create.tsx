import { Form, Head, Link } from '@inertiajs/react';
import ClientController from '@/actions/App/Http/Controllers/ClientController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index } from '@/routes/clients';

export default function ClientsCreate() {
    return (
        <>
            <Head title="Create client" />

            <div className="mx-auto max-w-xl p-4">
                <Heading
                    title="Create client"
                    description="Add a new client such as Lulu Hyper Market"
                />

                <Form
                    {...ClientController.store.form()}
                    className="space-y-6 rounded-xl border p-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Client name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    placeholder="Lulu Hyper Market"
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="flex gap-3">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create client'}
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={index()}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

ClientsCreate.layout = {
    breadcrumbs: [
        { title: 'Clients', href: index() },
        { title: 'Create', href: create() },
    ],
};
