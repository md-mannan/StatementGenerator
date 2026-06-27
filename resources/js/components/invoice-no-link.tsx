import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { show } from '@/routes/clients/invoices';

type Props = {
    clientId: number;
    invoiceNo: string;
    className?: string;
};

export function InvoiceNoLink({ clientId, invoiceNo, className }: Props) {
    return (
        <Link
            href={show.url({
                client: clientId,
                invoiceNo: encodeURIComponent(invoiceNo),
            })}
            className={cn(
                'font-mono text-primary hover:underline',
                className,
            )}
            prefetch="hover"
        >
            {invoiceNo}
        </Link>
    );
}
