import { cn } from '@/lib/utils';
import { useCallback, useEffect, useRef, useState } from 'react';

export const PREVIEW_ZOOM_MIN = 50;
export const PREVIEW_ZOOM_MAX = 200;
export const PREVIEW_ZOOM_DEFAULT = 100;
export const PREVIEW_ZOOM_STEP = 0.5;

type Props = {
    url: string;
    alt: string;
    isImage: boolean;
    zoom?: number;
    className?: string;
    onZoomChange?: (zoom: number) => void;
};

function fitImageToContainer(
    container: HTMLElement,
    naturalWidth: number,
    naturalHeight: number,
): { w: number; h: number } {
    const padding = 16;
    const cw = Math.max(container.clientWidth - padding, 1);
    const ch = Math.max(container.clientHeight - padding, 1);
    const ratio = Math.min(cw / naturalWidth, ch / naturalHeight);

    return {
        w: Math.max(1, Math.round(naturalWidth * ratio)),
        h: Math.max(1, Math.round(naturalHeight * ratio)),
    };
}

export function formatPreviewZoom(zoom: number): string {
    return `${Math.round(zoom)}%`;
}

export function InvoiceScanPreviewViewer({
    url,
    alt,
    isImage,
    zoom = PREVIEW_ZOOM_DEFAULT,
    className,
    onZoomChange,
}: Props) {
    const scale = zoom / 100;
    const containerRef = useRef<HTMLDivElement>(null);
    const zoomRef = useRef(zoom);
    const [fitSize, setFitSize] = useState<{ w: number; h: number } | null>(
        null,
    );

    zoomRef.current = zoom;

    useEffect(() => {
        setFitSize(null);
    }, [url]);

    const measureFit = useCallback(
        (naturalWidth: number, naturalHeight: number) => {
            const container = containerRef.current;

            if (!container || naturalWidth <= 0 || naturalHeight <= 0) {
                return;
            }

            setFitSize(fitImageToContainer(container, naturalWidth, naturalHeight));
        },
        [],
    );

    useEffect(() => {
        if (!isImage || !fitSize) {
            return;
        }

        const container = containerRef.current;

        if (!container || typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(() => {
            const img = container.querySelector('img');

            if (img?.naturalWidth) {
                measureFit(img.naturalWidth, img.naturalHeight);
            }
        });

        observer.observe(container);

        return () => observer.disconnect();
    }, [fitSize, isImage, measureFit, url]);

    useEffect(() => {
        const container = containerRef.current;

        if (!container || !onZoomChange) {
            return;
        }

        const handleWheel = (event: WheelEvent): void => {
            event.preventDefault();
            const delta =
                -event.deltaY * (event.deltaMode === 1 ? 10 : 0.08);
            onZoomChange(
                clampPreviewZoom(zoomRef.current + delta),
            );
        };

        container.addEventListener('wheel', handleWheel, { passive: false });

        return () => container.removeEventListener('wheel', handleWheel);
    }, [onZoomChange]);

    const scaledWidth = fitSize ? fitSize.w * scale : undefined;
    const scaledHeight = fitSize ? fitSize.h * scale : undefined;

    return (
        <div className={cn('h-full min-h-0 w-full', className)}>
            <div
                ref={containerRef}
                className="h-full overflow-auto rounded-lg border bg-muted/20"
            >
                <div className="flex min-h-full min-w-full items-center justify-center p-2">
                    {isImage ? (
                        <div
                            className="shrink-0 transition-[width,height] duration-200 ease-out"
                            style={{
                                width: scaledWidth,
                                height: scaledHeight,
                            }}
                        >
                            <img
                                src={url}
                                alt={alt}
                                draggable={false}
                                onLoad={(event) =>
                                    measureFit(
                                        event.currentTarget.naturalWidth,
                                        event.currentTarget.naturalHeight,
                                    )
                                }
                                className="block size-full object-contain"
                                style={
                                    fitSize
                                        ? undefined
                                        : {
                                              maxHeight: '100%',
                                              maxWidth: '100%',
                                              height: 'auto',
                                              width: 'auto',
                                          }
                                }
                            />
                        </div>
                    ) : (
                        <div
                            className="w-full transition-[width] duration-200 ease-out"
                            style={{
                                width: `${100 * scale}%`,
                                minWidth: '100%',
                            }}
                        >
                            <iframe
                                title={alt}
                                src={url}
                                className="aspect-[3/4] w-full rounded-md transition-[min-height] duration-200 ease-out"
                                style={{
                                    minHeight: `${480 * scale}px`,
                                }}
                            />
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

export function VerticalPreviewZoomSlider({
    value,
    onChange,
    className,
}: {
    value: number;
    onChange: (value: number) => void;
    className?: string;
}) {
    const [isDragging, setIsDragging] = useState(false);

    const endDrag = (): void => {
        setIsDragging(false);
    };

    return (
        <div
            className={cn(
                'flex min-h-[7rem] flex-1 items-center justify-center py-1',
                className,
            )}
        >
            <input
                type="range"
                min={PREVIEW_ZOOM_MIN}
                max={PREVIEW_ZOOM_MAX}
                step={PREVIEW_ZOOM_STEP}
                value={value}
                onChange={(event) =>
                    onChange(clampPreviewZoom(Number(event.target.value)))
                }
                onPointerDown={() => setIsDragging(true)}
                onPointerUp={endDrag}
                onPointerCancel={endDrag}
                onLostPointerCapture={endDrag}
                className={cn(
                    'w-[min(9rem,22vh)] rotate-90 cursor-pointer accent-primary',
                    !isDragging && 'transition-opacity duration-150',
                )}
                aria-label="Zoom level"
                aria-valuetext={formatPreviewZoom(value)}
            />
        </div>
    );
}

export function clampPreviewZoom(value: number): number {
    const rounded = Math.round(value * 2) / 2;

    return Math.min(
        PREVIEW_ZOOM_MAX,
        Math.max(PREVIEW_ZOOM_MIN, rounded),
    );
}

export function printInvoiceScanImage(url: string, title: string): void {
    const iframe = document.createElement('iframe');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = '0';
    document.body.appendChild(iframe);

    const frameWindow = iframe.contentWindow;

    if (!frameWindow) {
        iframe.remove();

        return;
    }

    frameWindow.document.open();
    frameWindow.document.write(`
        <!DOCTYPE html>
        <html>
            <head>
                <title>${title}</title>
                <style>
                    @page { margin: 12mm; }
                    body {
                        margin: 0;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        min-height: 100vh;
                    }
                    img {
                        max-width: 100%;
                        max-height: 100vh;
                        object-fit: contain;
                    }
                </style>
            </head>
            <body>
                <img src="${url}" alt="${title}" />
            </body>
        </html>
    `);
    frameWindow.document.close();

    const image = frameWindow.document.querySelector('img');

    const triggerPrint = (): void => {
        frameWindow.focus();
        frameWindow.print();
        window.setTimeout(() => iframe.remove(), 500);
    };

    if (image?.complete) {
        triggerPrint();

        return;
    }

    image?.addEventListener('load', triggerPrint, { once: true });
    image?.addEventListener('error', () => iframe.remove(), { once: true });
}
