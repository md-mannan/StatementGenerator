import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type MobileDataField = {
    label: string;
    value: ReactNode;
    className?: string;
    mono?: boolean;
};

type Props = {
    title: ReactNode;
    subtitle?: ReactNode;
    badge?: { label: string; variant?: 'default' | 'secondary' | 'destructive' | 'outline' };
    fields: MobileDataField[];
    actions?: ReactNode;
    className?: string;
    highlight?: boolean;
};

export function MobileDataCard({
    title,
    subtitle,
    badge,
    fields,
    actions,
    className,
    highlight,
}: Props) {
    return (
        <article
            className={cn(
                'rounded-xl border bg-card p-4 shadow-sm',
                highlight && 'ring-1 ring-inset ring-primary/20',
                className,
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="font-medium leading-snug">{title}</div>
                    {subtitle && (
                        <p className="mt-0.5 truncate text-sm text-muted-foreground">
                            {subtitle}
                        </p>
                    )}
                </div>
                {badge && (
                    <Badge variant={badge.variant ?? 'secondary'} className="shrink-0">
                        {badge.label}
                    </Badge>
                )}
            </div>

            {fields.length > 0 && (
                <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    {fields.map((field) => (
                        <div key={field.label} className={field.className}>
                            <dt className="text-muted-foreground">{field.label}</dt>
                            <dd
                                className={cn(
                                    'font-medium break-words',
                                    field.mono && 'font-mono tabular-nums',
                                )}
                            >
                                {field.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            {actions && <div className="mt-4 border-t pt-4">{actions}</div>}
        </article>
    );
}
