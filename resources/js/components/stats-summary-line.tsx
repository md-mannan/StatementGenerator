import { cn } from '@/lib/utils';

type Props = {
    items: Array<string | false | null | undefined>;
    className?: string;
};

export function StatsSummaryLine({ items, className }: Props) {
    const visibleItems = items.filter(
        (item): item is string => typeof item === 'string' && item.length > 0,
    );

    if (visibleItems.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'flex flex-wrap gap-x-3 gap-y-1 text-sm text-muted-foreground',
                className,
            )}
        >
            {visibleItems.map((item, index) => (
                <span key={`${item}-${index}`}>{item}</span>
            ))}
        </div>
    );
}
