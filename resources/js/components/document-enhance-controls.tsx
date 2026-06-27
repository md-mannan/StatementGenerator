import { Button } from '@/components/ui/button';
import {
    DOCUMENT_ADJUSTMENT_CONTROLS,
    DEFAULT_DOCUMENT_ADJUSTMENTS,
    DOCUMENT_FILTERS,
    type DocumentAdjustments,
    type DocumentFilter,
} from '@/lib/invoice-document-filters';

const SHORT_LABELS: Record<keyof DocumentAdjustments, string> = {
    brightness: 'Bright',
    colorCorrection: 'Color',
    contrast: 'Contrast',
    sharpness: 'Sharp',
    hue: 'Hue',
};

type Props = {
    documentFilter: DocumentFilter;
    onFilterChange: (filter: DocumentFilter) => void;
    adjustments: DocumentAdjustments;
    onAdjustmentsChange: (adjustments: DocumentAdjustments) => void;
};

export function DocumentEnhanceControls({
    documentFilter,
    onFilterChange,
    adjustments,
    onAdjustmentsChange,
}: Props) {
    const update = (key: keyof DocumentAdjustments, value: number): void => {
        onAdjustmentsChange({
            ...adjustments,
            [key]: value,
        });
    };

    return (
        <div className="shrink-0 space-y-2 rounded-md border bg-muted/20 px-2 py-2">
            <div className="flex flex-wrap items-center gap-1">
                {DOCUMENT_FILTERS.map((filter) => (
                    <Button
                        key={filter.value}
                        type="button"
                        size="sm"
                        variant={
                            documentFilter === filter.value
                                ? 'default'
                                : 'outline'
                        }
                        className="h-7 px-2 text-xs"
                        onClick={() => onFilterChange(filter.value)}
                    >
                        {filter.label}
                    </Button>
                ))}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="ml-auto h-7 px-2 text-xs"
                    onClick={() =>
                        onAdjustmentsChange(DEFAULT_DOCUMENT_ADJUSTMENTS)
                    }
                >
                    Reset
                </Button>
            </div>

            <div className="grid grid-cols-1 gap-1 sm:grid-cols-2 xl:grid-cols-5">
                {DOCUMENT_ADJUSTMENT_CONTROLS.map(({ key }) => (
                    <div
                        key={key}
                        className="flex items-center gap-1.5 rounded-sm bg-background/60 px-1.5 py-1"
                    >
                        <span className="w-11 shrink-0 truncate text-[10px] text-muted-foreground">
                            {SHORT_LABELS[key]}
                        </span>
                        <input
                            id={`adjust-${key}`}
                            type="range"
                            min={0}
                            max={100}
                            step={1}
                            value={adjustments[key]}
                            onChange={(event) =>
                                update(key, Number(event.target.value))
                            }
                            className="h-1 min-w-0 flex-1 cursor-pointer accent-primary"
                            aria-label={SHORT_LABELS[key]}
                        />
                        <span className="w-7 shrink-0 text-right text-[10px] tabular-nums text-muted-foreground">
                            {adjustments[key]}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}
