import { Form, Head, Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import Heading from '@/components/heading';
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
import { create, index, show } from '@/routes/clients';
import type { Client } from '@/types';

export default function ClientsIndex({ clients }: { clients: Client[] }) {
    return (
        <>
            <Head title="Clients" />

            <div className={pageShellClassName}>
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title="Clients"
                        description="Manage clients and their branches for statement analysis"
                    />
                    <Button asChild>
                        <Link href={create()}>
                            <Plus className="size-4" />
                            New client
                        </Link>
                    </Button>
                </div>

                {clients.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-4 py-12 text-center">
                            <Building2 className="size-10 text-muted-foreground" />
                            <div>
                                <p className="font-medium">No clients yet</p>
                                <p className="text-sm text-muted-foreground">
                                    Create your first client, such as Lulu Hyper
                                    Market, then add branches.
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={create()}>Create client</Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {clients.map((client) => (
                            <Card key={client.id}>
                                <CardHeader>
                                    <CardTitle className="flex items-start justify-between gap-2">
                                        <Link
                                            href={show(client.id)}
                                            className="hover:underline"
                                        >
                                            {client.name}
                                        </Link>
                                        <Badge variant="secondary">
                                            {client.branches_count ?? 0}{' '}
                                            branches
                                        </Badge>
                                    </CardTitle>
                                    <CardDescription>
                                        View branches and upload statements
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Button variant="outline" asChild>
                                        <Link href={show(client.id)}>
                                            Open client
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

ClientsIndex.layout = {
    breadcrumbs: [
        { title: 'Clients', href: index() },
    ],
};
