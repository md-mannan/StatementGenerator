import {
    defaultQuadPoints,
    quadPointsToPolygon,
    type QuadPoints,
} from '@/lib/invoice-polygon-crop';
import { fitImageToContainer } from '@/lib/invoice-scan-crop';
import { cn } from '@/lib/utils';
import { useCallback, useEffect, useId, useRef, useState } from 'react';

type CornerKey = keyof QuadPoints;

const CORNER_KEYS: CornerKey[] = [
    'topLeft',
    'topRight',
    'bottomRight',
    'bottomLeft',
];

type Props = {
    src: string;
    alt: string;
    quad: QuadPoints;
    onChange: (quad: QuadPoints) => void;
    onImageReady?: (image: HTMLImageElement) => void | Promise<void>;
    imageRef?: React.RefObject<HTMLImageElement | null>;
    containerRef?: React.RefObject<HTMLDivElement | null>;
    className?: string;
};

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

function clientToNormalized(
    clientX: number,
    clientY: number,
    rect: DOMRect,
): { x: number; y: number } {
    return {
        x: clamp((clientX - rect.left) / rect.width, 0, 1),
        y: clamp((clientY - rect.top) / rect.height, 0, 1),
    };
}

export function InvoicePolygonCropper({
    src,
    alt,
    quad,
    onChange,
    onImageReady,
    imageRef: externalImageRef,
    containerRef: externalContainerRef,
    className,
}: Props) {
    const maskId = useId().replace(/:/g, '');
    const internalImageRef = useRef<HTMLImageElement>(null);
    const internalContainerRef = useRef<HTMLDivElement>(null);
    const overlayRef = useRef<HTMLDivElement>(null);
    const dragCornerRef = useRef<CornerKey | null>(null);

    const imageRef = externalImageRef ?? internalImageRef;
    const containerRef = externalContainerRef ?? internalContainerRef;

    const [initialized, setInitialized] = useState(false);

    const fitImage = useCallback(() => {
        const container = containerRef.current;
        const image = imageRef.current;

        if (container && image && image.naturalWidth > 0) {
            fitImageToContainer(image, container);
        }
    }, [containerRef, imageRef]);

    const handleImageLoad = useCallback(() => {
        fitImage();

        const image = imageRef.current;

        if (!initialized && image) {
            void onImageReady?.(image);
            setInitialized(true);
        }
    }, [fitImage, imageRef, initialized, onImageReady]);

    useEffect(() => {
        fitImage();

        const container = containerRef.current;

        if (!container) {
            return;
        }

        const observer = new ResizeObserver(() => {
            fitImage();
        });

        observer.observe(container);

        return () => observer.disconnect();
    }, [containerRef, fitImage, src]);

    useEffect(() => {
        setInitialized(false);
    }, [src]);

    const handlePointerDown = useCallback(
        (corner: CornerKey, event: React.PointerEvent<HTMLButtonElement>) => {
            event.preventDefault();
            dragCornerRef.current = corner;
            event.currentTarget.setPointerCapture(event.pointerId);
        },
        [],
    );

    const handlePointerMove = useCallback(
        (event: React.PointerEvent<HTMLButtonElement>) => {
            const corner = dragCornerRef.current;
            const image = imageRef.current;

            if (!corner || !image) {
                return;
            }

            const rect = image.getBoundingClientRect();
            const nextPoint = clientToNormalized(
                event.clientX,
                event.clientY,
                rect,
            );

            onChange({
                ...quad,
                [corner]: nextPoint,
            });
        },
        [imageRef, onChange, quad],
    );

    const handlePointerUp = useCallback(
        (event: React.PointerEvent<HTMLButtonElement>) => {
            if (dragCornerRef.current) {
                event.currentTarget.releasePointerCapture(event.pointerId);
                dragCornerRef.current = null;
            }
        },
        [],
    );

    return (
        <div
            ref={containerRef}
            className={cn(
                'flex min-h-0 w-full flex-1 items-center justify-center',
                className,
            )}
        >
            <div ref={overlayRef} className="relative inline-block leading-none">
                <img
                    ref={imageRef}
                    src={src}
                    alt={alt}
                    draggable={false}
                    className="block select-none"
                    onLoad={handleImageLoad}
                />

                <svg
                    className="pointer-events-none absolute inset-0 h-full w-full"
                    viewBox="0 0 1 1"
                    preserveAspectRatio="none"
                    aria-hidden
                >
                    <defs>
                        <mask id={maskId}>
                            <rect width="1" height="1" fill="white" />
                            <polygon
                                points={quadPointsToPolygon(quad)}
                                fill="black"
                            />
                        </mask>
                    </defs>
                    <rect
                        width="1"
                        height="1"
                        fill="rgba(0,0,0,0.45)"
                        mask={`url(#${maskId})`}
                    />
                    <polygon
                        points={quadPointsToPolygon(quad)}
                        fill="rgba(59,130,246,0.12)"
                        stroke="rgb(59,130,246)"
                        strokeWidth="0.004"
                        vectorEffect="non-scaling-stroke"
                    />
                </svg>

                {CORNER_KEYS.map((corner) => (
                    <button
                        key={corner}
                        type="button"
                        aria-label={`Move ${corner}`}
                        className="absolute z-10 size-4 -translate-x-1/2 -translate-y-1/2 cursor-move rounded-full border-2 border-white bg-primary shadow-md touch-none"
                        style={{
                            left: `${quad[corner].x * 100}%`,
                            top: `${quad[corner].y * 100}%`,
                        }}
                        onPointerDown={(event) => handlePointerDown(corner, event)}
                        onPointerMove={handlePointerMove}
                        onPointerUp={handlePointerUp}
                        onPointerCancel={handlePointerUp}
                    />
                ))}
            </div>
        </div>
    );
}
