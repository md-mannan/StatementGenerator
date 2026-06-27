import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { InvoiceRelationMap } from '@/components/invoice-relation-map';
import { Badge } from '@/components/ui/badge';
import { index as crossCheckIndex } from '@/routes/clients/cross-check';
import type { Client, InvoiceDetail } from '@/types';

type Props = {
    client: Pick<Client, 'id' | 'name'>;
    invoice: InvoiceDetail;
};

function statusLabel(status: InvoiceDetail['status']): string {
    switch (status) {
        case 'matched':
            return 'Matched';
        case 'complete':
            return 'Complete';
        case 'mismatch':
            return 'Mismatch';
        case 'incomplete':
            return 'Incomplete';
    }
}

function statusVariant(
    status: InvoiceDetail['status'],
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'matched':
            return 'default';
        case 'complete':
            return 'outline';
        case 'mismatch':
            return 'destructive';
        case 'incomplete':
            return 'secondary';
    }
}

export default function ClientInvoiceShow({ client, invoice }: Props) {
    return (
        <>
            <Head title={`Invoice ${invoice.invoice_no}`} />

            <div className="mx-auto w-full max-w-3xl px-2 py-4">
                <Link
                    href={crossCheckIndex(client.id)}
                    className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-3.5" />
                    All invoices
                </Link>

                <div className="mb-6 flex flex-wrap items-center gap-2">
                    <h1 className="text-lg font-semibold tracking-tight">
                        Invoice overview
                    </h1>
                    <Badge variant={statusVariant(invoice.status)}>
                        {statusLabel(invoice.status)}
                    </Badge>
                </div>

                <InvoiceRelationMap client={client} invoice={invoice} />
            </div>
        </>
    );
}
