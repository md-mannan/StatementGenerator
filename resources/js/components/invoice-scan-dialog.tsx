import StatementInvoiceScanController from '@/actions/App/Http/Controllers/StatementInvoiceScanController';
import { DocumentEnhanceControls } from '@/components/document-enhance-controls';
import InputError from '@/components/input-error';
import { InvoicePolygonCropper } from '@/components/invoice-polygon-cropper';
import {
    InvoiceScanPreviewViewer,
    PREVIEW_ZOOM_DEFAULT,
    VerticalPreviewZoomSlider,
    formatPreviewZoom,
    printInvoiceScanImage,
} from '@/components/invoice-scan-preview-viewer';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { detectDocumentQuad } from '@/lib/invoice-document-detect';
import {
    canvasToBlob,
    canvasToDataUrl,
    DEFAULT_DOCUMENT_ADJUSTMENTS,
    renderEnhancedDocument,
    type DocumentAdjustments,
    type DocumentFilter,
} from '@/lib/invoice-document-filters';
import {
    cropImageWithQuadToCanvas,
    defaultQuadPoints,
    isValidQuad,
    type QuadPoints,
} from '@/lib/invoice-polygon-crop';
import {
    readFileAsDataUrl,
    scanFilenameForInvoice,
} from '@/lib/invoice-scan-crop';
import { cn } from '@/lib/utils';
import type { StatementEntry } from '@/types/statement';
import { router } from '@inertiajs/react';
import { Camera, Download, FileText, ImageIcon, Loader2, Maximize2, Printer, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Props = {
    entry: StatementEntry | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type Step = 'choose' | 'preview' | 'camera' | 'crop' | 'enhance' | 'saving';

function isImageScanExtension(extension: string | null | undefined): boolean {
    return ['jpg', 'jpeg', 'png', 'webp'].includes(extension ?? '');
}

function scanDownloadExtension(
    extension: string | null | undefined,
): 'pdf' | 'jpg' | 'png' | 'webp' {
    const normalized = extension === 'jpeg' ? 'jpg' : (extension ?? 'pdf');

    if (normalized === 'jpg' || normalized === 'png' || normalized === 'webp') {
        return normalized;
    }

    return 'pdf';
}

export function InvoiceScanDialog({ entry, open, onOpenChange }: Props) {
    const pdfInputRef = useRef<HTMLInputElement>(null);
    const imageInputRef = useRef<HTMLInputElement>(null);
    const cameraInputRef = useRef<HTMLInputElement>(null);
    const imageRef = useRef<HTMLImageElement>(null);
    const cropContainerRef = useRef<HTMLDivElement>(null);
    const videoRef = useRef<HTMLVideoElement>(null);
    const cameraStreamRef = useRef<MediaStream | null>(null);
    const warpedCanvasRef = useRef<HTMLCanvasElement | null>(null);

    const [step, setStep] = useState<Step>('choose');
    const [error, setError] = useState<string | null>(null);
    const [imageSrc, setImageSrc] = useState<string | null>(null);
    const [quadPoints, setQuadPoints] = useState<QuadPoints>(defaultQuadPoints());
    const [outputFormat, setOutputFormat] = useState<'jpg' | 'png'>('jpg');
    const [documentFilter, setDocumentFilter] =
        useState<DocumentFilter>('enhanced');
    const [adjustments, setAdjustments] = useState<DocumentAdjustments>(
        DEFAULT_DOCUMENT_ADJUSTMENTS,
    );
    const [enhancedPreviewSrc, setEnhancedPreviewSrc] = useState<string | null>(
        null,
    );
    const [detectingDocument, setDetectingDocument] = useState(false);
    const [croppingDocument, setCroppingDocument] = useState(false);
    const [previewZoom, setPreviewZoom] = useState(PREVIEW_ZOOM_DEFAULT);

    const stopCamera = useCallback(() => {
        cameraStreamRef.current?.getTracks().forEach((track) => track.stop());
        cameraStreamRef.current = null;

        if (videoRef.current) {
            videoRef.current.srcObject = null;
        }
    }, []);

    const reset = useCallback(() => {
        stopCamera();
        setStep('choose');
        setError(null);
        setImageSrc(null);
        setQuadPoints(defaultQuadPoints());
        setOutputFormat('jpg');
        setDocumentFilter('enhanced');
        setAdjustments(DEFAULT_DOCUMENT_ADJUSTMENTS);
        setEnhancedPreviewSrc(null);
        setDetectingDocument(false);
        setCroppingDocument(false);
        setPreviewZoom(PREVIEW_ZOOM_DEFAULT);
        warpedCanvasRef.current = null;
    }, [stopCamera]);

    const close = useCallback(
        (nextOpen: boolean) => {
            if (!nextOpen) {
                reset();
            }

            onOpenChange(nextOpen);
        },
        [onOpenChange, reset],
    );

    const uploadFile = useCallback(
        (file: File) => {
            if (!entry) {
                return;
            }

            setStep('saving');
            setError(null);

            const formData = new FormData();
            formData.append('scan', file, file.name);

            router.post(
                StatementInvoiceScanController.store.url(entry.id),
                formData,
                {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => close(false),
                    onError: (errors) => {
                        setStep('enhance');
                        setError(
                            typeof errors.scan === 'string'
                                ? errors.scan
                                : 'Could not save the invoice scan.',
                        );
                    },
                },
            );
        },
        [close, entry],
    );

    const handlePdfSelected = useCallback(
        async (file: File | undefined) => {
            if (!file || !entry) {
                return;
            }

            if (file.type !== 'application/pdf') {
                setError('Please choose a PDF scan.');

                return;
            }

            uploadFile(
                new File(
                    [file],
                    scanFilenameForInvoice(entry.invoice_no, 'pdf'),
                    { type: 'application/pdf' },
                ),
            );
        },
        [entry, uploadFile],
    );

    const beginCropFlow = useCallback((source: string) => {
        warpedCanvasRef.current = null;
        setEnhancedPreviewSrc(null);
        setDocumentFilter('enhanced');
        setQuadPoints(defaultQuadPoints());
        setImageSrc(source);
        setStep('crop');
    }, []);

    const handleImageSelected = useCallback(
        async (file: File | undefined) => {
            if (!file) {
                return;
            }

            if (!file.type.startsWith('image/')) {
                setError('Please choose an image file.');

                return;
            }

            setError(null);
            beginCropFlow(await readFileAsDataUrl(file));
        },
        [beginCropFlow],
    );

    const openCamera = useCallback(() => {
        setError(null);

        if (navigator.mediaDevices) {
            setStep('camera');

            return;
        }

        cameraInputRef.current?.click();
    }, []);

    const captureFromCamera = useCallback(() => {
        const video = videoRef.current;

        if (!video || video.videoWidth === 0 || video.videoHeight === 0) {
            setError('Camera is not ready yet. Try again in a moment.');

            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;

        const context = canvas.getContext('2d');

        if (!context) {
            setError('Could not capture the photo. Try again.');

            return;
        }

        context.drawImage(video, 0, 0);

        stopCamera();
        setError(null);
        beginCropFlow(canvas.toDataURL('image/jpeg', 0.92));
    }, [beginCropFlow, stopCamera]);

    useEffect(() => {
        if (!open || step !== 'camera') {
            return;
        }

        let active = true;

        const startCamera = async (): Promise<void> => {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                    },
                    audio: false,
                });

                if (!active) {
                    stream.getTracks().forEach((track) => track.stop());

                    return;
                }

                cameraStreamRef.current = stream;

                if (videoRef.current) {
                    videoRef.current.srcObject = stream;
                    await videoRef.current.play();
                }
            } catch {
                if (!active) {
                    return;
                }

                setStep('choose');
                setError(
                    'Could not access the camera. Allow permission in your browser or upload a photo instead.',
                );
            }
        };

        void startCamera();

        return () => {
            active = false;
            stopCamera();
        };
    }, [open, step, stopCamera]);

    const handleSelectImageReady = useCallback(
        async (image: HTMLImageElement) => {
            setDetectingDocument(true);
            setError(null);

            try {
                const detected = await detectDocumentQuad(image);
                setQuadPoints(detected ?? defaultQuadPoints());
            } catch {
                setQuadPoints(defaultQuadPoints());
            } finally {
                setDetectingDocument(false);
            }
        },
        [],
    );

    const applyCrop = useCallback(async () => {
        if (!imageRef.current || !isValidQuad(quadPoints)) {
            return;
        }

        setCroppingDocument(true);
        setError(null);

        try {
            warpedCanvasRef.current = cropImageWithQuadToCanvas(
                imageRef.current,
                quadPoints,
            );
            setStep('enhance');
        } catch {
            setError('Could not crop the image. Adjust the corners and try again.');
        } finally {
            setCroppingDocument(false);
        }
    }, [quadPoints]);

    useEffect(() => {
        if (step !== 'enhance' || !warpedCanvasRef.current) {
            return;
        }

        const filteredCanvas = renderEnhancedDocument(
            warpedCanvasRef.current,
            documentFilter,
            adjustments,
        );
        setEnhancedPreviewSrc(
            canvasToDataUrl(
                filteredCanvas,
                outputFormat === 'png' ? 'image/png' : 'image/jpeg',
            ),
        );
    }, [adjustments, documentFilter, outputFormat, step]);

    const saveEnhancedImage = useCallback(async () => {
        if (!entry || !warpedCanvasRef.current) {
            return;
        }

        try {
            setStep('saving');
            setError(null);

            const mimeType =
                outputFormat === 'png' ? 'image/png' : 'image/jpeg';
            const filteredCanvas = renderEnhancedDocument(
                warpedCanvasRef.current,
                documentFilter,
                adjustments,
            );
            const blob = await canvasToBlob(filteredCanvas, mimeType);

            uploadFile(
                new File(
                    [blob],
                    scanFilenameForInvoice(entry.invoice_no, outputFormat),
                    { type: mimeType },
                ),
            );
        } catch {
            setStep('enhance');
            setError('Could not save the invoice scan. Try again.');
        }
    }, [adjustments, documentFilter, entry, outputFormat, uploadFile]);

    const removeScan = useCallback(() => {
        if (!entry) {
            return;
        }

        router.delete(
            StatementInvoiceScanController.destroy.url(entry.id),
            {
                preserveScroll: true,
                onSuccess: () => close(false),
            },
        );
    }, [close, entry]);

    const downloadScan = useCallback(async () => {
        if (!entry?.invoice_scan_url) {
            return;
        }

        try {
            setError(null);

            const response = await fetch(entry.invoice_scan_url, {
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error('Download failed.');
            }

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download = scanFilenameForInvoice(
                entry.invoice_no,
                scanDownloadExtension(entry.invoice_scan_extension),
            );
            anchor.click();
            URL.revokeObjectURL(objectUrl);
        } catch {
            setError('Could not download the invoice scan.');
        }
    }, [entry]);

    const printScan = useCallback(() => {
        if (
            !entry?.invoice_scan_url ||
            !isImageScanExtension(entry.invoice_scan_extension)
        ) {
            return;
        }

        printInvoiceScanImage(
            entry.invoice_scan_url,
            `Invoice scan ${entry.invoice_no}`,
        );
    }, [entry]);

    const openScanPreview = useCallback(() => {
        if (!entry?.invoice_scan_url) {
            return;
        }

        setStep('preview');
    }, [entry]);

    if (!entry) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="flex h-[min(95vh,920px)] max-h-[95vh] w-[min(100vw-1rem,72rem)] max-w-[min(100vw-1rem,72rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-[min(96vw,72rem)]">
                <div className="shrink-0 px-3 pt-4 pb-2 sm:px-6 sm:pt-6">
                    <DialogHeader className="text-center sm:text-center">
                        <DialogTitle>
                            Invoice scan — {entry.invoice_no}
                        </DialogTitle>
                        <DialogDescription>
                           
                        </DialogDescription>
                    </DialogHeader>
                </div>

                <div
                    className={cn(
                        'flex min-h-0 flex-1 flex-col pb-4',
                        step === 'choose' ? 'px-3 sm:pl-3 sm:pr-6' : 'px-3 sm:px-6',
                        step === 'choose' ||
                            step === 'crop' ||
                            step === 'enhance' ||
                            step === 'preview' ||
                            step === 'camera'
                            ? 'overflow-hidden'
                            : 'overflow-y-auto',
                    )}
                >
                    {step === 'choose' && (
                        <div className="flex min-h-0 flex-1 flex-col gap-2">
                            <div className="flex min-h-0 flex-1 flex-col gap-2 sm:flex-row sm:items-stretch">
                                <div className="flex shrink-0 flex-row flex-wrap gap-1.5 sm:w-[5.5rem] sm:flex-col sm:flex-nowrap sm:self-stretch">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-auto w-full flex-col gap-0.5 px-1.5 py-2 text-[10px]"
                                        onClick={() =>
                                            pdfInputRef.current?.click()
                                        }
                                    >
                                        <FileText className="size-3.5 shrink-0" />
                                        PDF
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-auto w-full flex-col gap-0.5 px-1.5 py-2 text-[10px]"
                                        onClick={() =>
                                            imageInputRef.current?.click()
                                        }
                                    >
                                        <ImageIcon className="size-3.5 shrink-0" />
                                        Photo
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-auto w-full flex-col gap-0.5 px-1.5 py-2 text-[10px]"
                                        onClick={openCamera}
                                    >
                                        <Camera className="size-3.5 shrink-0" />
                                        Camera
                                    </Button>

                                    {entry.has_invoice_scan &&
                                        entry.invoice_scan_url && (
                                            <div className="mt-1 flex min-h-0 flex-1 flex-col gap-1 border-t pt-1.5 sm:flex-col">
                                                <span className="text-center text-[9px] text-muted-foreground">
                                                    {formatPreviewZoom(
                                                        previewZoom,
                                                    )}
                                                </span>
                                                <VerticalPreviewZoomSlider
                                                    value={previewZoom}
                                                    onChange={setPreviewZoom}
                                                />
                                                {isImageScanExtension(
                                                    entry.invoice_scan_extension,
                                                ) && (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="h-auto w-full shrink-0 flex-col gap-0.5 px-1.5 py-2 text-[10px]"
                                                        onClick={printScan}
                                                    >
                                                        <Printer className="size-3.5 shrink-0" />
                                                        Print
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                </div>

                                <div className="min-h-[20rem] min-w-0 flex-1">
                                    {entry.has_invoice_scan &&
                                    entry.invoice_scan_url ? (
                                        <InvoiceScanPreviewViewer
                                            url={entry.invoice_scan_url}
                                            alt={`Invoice scan ${entry.invoice_no}`}
                                            isImage={isImageScanExtension(
                                                entry.invoice_scan_extension,
                                            )}
                                            zoom={previewZoom}
                                            onZoomChange={setPreviewZoom}
                                            onClick={openScanPreview}
                                            className="min-h-[20rem]"
                                        />
                                    ) : (
                                        <div className="flex h-full min-h-[20rem] items-center justify-center rounded-lg border border-dashed bg-muted/10 p-4 text-center text-xs text-muted-foreground">
                                            Upload or capture an invoice scan
                                        </div>
                                    )}
                                </div>
                            </div>

                            {entry.has_invoice_scan &&
                                entry.invoice_scan_url && (
                                    <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 rounded-lg border bg-muted/30 px-3 py-2">
                                        <p className="text-sm">
                                            Scan on file:{' '}
                                            <span className="font-mono font-medium">
                                                {scanFilenameForInvoice(
                                                    entry.invoice_no,
                                                    scanDownloadExtension(
                                                        entry.invoice_scan_extension,
                                                    ),
                                                )}
                                            </span>
                                        </p>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={openScanPreview}
                                            >
                                                <Maximize2 className="size-4" />
                                                Preview
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    void downloadScan();
                                                }}
                                            >
                                                <Download className="size-4" />
                                                Download
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={removeScan}
                                            >
                                                <Trash2 className="size-4" />
                                                Remove
                                            </Button>
                                        </div>
                                    </div>
                                )}

                            <input
                                ref={pdfInputRef}
                                type="file"
                                accept="application/pdf,.pdf"
                                className="hidden"
                                onChange={(event) => {
                                    void handlePdfSelected(
                                        event.target.files?.[0],
                                    );
                                    event.target.value = '';
                                }}
                            />
                            <input
                                ref={imageInputRef}
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={(event) => {
                                    void handleImageSelected(
                                        event.target.files?.[0],
                                    );
                                    event.target.value = '';
                                }}
                            />
                            <input
                                ref={cameraInputRef}
                                type="file"
                                accept="image/*"
                                capture="environment"
                                className="hidden"
                                onChange={(event) => {
                                    void handleImageSelected(
                                        event.target.files?.[0],
                                    );
                                    event.target.value = '';
                                }}
                            />

                            <InputError message={error ?? undefined} />
                        </div>
                    )}

                    {step === 'preview' && entry.invoice_scan_url && (
                        <div className="flex h-full min-h-0 flex-col gap-3">
                            {isImageScanExtension(
                                entry.invoice_scan_extension,
                            ) ? (
                                <div className="flex min-h-0 flex-1 items-stretch justify-center overflow-hidden rounded-lg border bg-muted/20">
                                    <img
                                        src={entry.invoice_scan_url}
                                        alt={`Invoice scan ${entry.invoice_no}`}
                                        className="max-h-full max-w-full object-contain"
                                    />
                                </div>
                            ) : (
                                <iframe
                                    title={`Invoice scan ${entry.invoice_no}`}
                                    src={entry.invoice_scan_url}
                                    className="min-h-0 w-full flex-1 rounded-lg border bg-muted/20"
                                />
                            )}
                        </div>
                    )}

                    {step === 'camera' && (
                        <div className="flex h-full min-h-0 flex-col items-center gap-3">
                            <p className="shrink-0 text-center text-sm text-muted-foreground">
                                Position the invoice in view, then capture the
                                photo.
                            </p>

                            <div className="flex min-h-0 w-full flex-1 items-center justify-center overflow-hidden rounded-lg border bg-black/90">
                                <video
                                    ref={videoRef}
                                    autoPlay
                                    playsInline
                                    muted
                                    className="max-h-full max-w-full object-contain"
                                />
                            </div>

                            <InputError message={error ?? undefined} />
                        </div>
                    )}

                    {step === 'crop' && imageSrc && (
                        <div className="flex h-full min-h-0 flex-col items-center gap-3">
                            <p className="shrink-0 text-center text-sm text-muted-foreground">
                                {detectingDocument
                                    ? 'Detecting document edges…'
                                    : 'Drag each corner to outline the invoice, then crop to flatten it.'}
                            </p>

                            <div className="relative flex min-h-0 w-full flex-1 rounded-lg border bg-muted/20 p-3">
                                {detectingDocument && (
                                    <div className="absolute inset-0 z-20 flex items-center justify-center rounded-lg bg-background/70">
                                        <Loader2 className="size-6 animate-spin text-muted-foreground" />
                                    </div>
                                )}
                                <InvoicePolygonCropper
                                    src={imageSrc}
                                    alt={`Invoice ${entry.invoice_no}`}
                                    quad={quadPoints}
                                    onChange={setQuadPoints}
                                    onImageReady={handleSelectImageReady}
                                    imageRef={imageRef}
                                    containerRef={cropContainerRef}
                                />
                            </div>

                            <InputError message={error ?? undefined} />
                        </div>
                    )}

                    {step === 'enhance' && enhancedPreviewSrc && (
                        <div className="flex h-full min-h-0 flex-col gap-2">
                            <div className="flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-md border bg-muted/20 p-2">
                                <img
                                    src={enhancedPreviewSrc}
                                    alt={`Flattened invoice ${entry.invoice_no}`}
                                    className="max-h-full max-w-full object-contain"
                                />
                            </div>

                            <DocumentEnhanceControls
                                documentFilter={documentFilter}
                                onFilterChange={setDocumentFilter}
                                adjustments={adjustments}
                                onAdjustmentsChange={setAdjustments}
                            />

                            <InputError message={error ?? undefined} />
                        </div>
                    )}

                    {step === 'saving' && (
                        <div className="flex items-center justify-center gap-2 py-10 text-muted-foreground">
                            <Loader2 className="size-5 animate-spin" />
                            Saving invoice scan…
                        </div>
                    )}
                </div>

                {step === 'preview' && (
                    <DialogFooter className="shrink-0 border-t bg-background px-6 py-4 sm:justify-center">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setStep('choose');
                            }}
                        >
                            Back
                        </Button>
                    </DialogFooter>
                )}

                {step === 'camera' && (
                    <DialogFooter className="shrink-0 gap-2 border-t bg-background px-6 py-4 sm:justify-center">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                stopCamera();
                                setStep('choose');
                            }}
                        >
                            Back
                        </Button>
                        <Button type="button" onClick={captureFromCamera}>
                            Capture photo
                        </Button>
                    </DialogFooter>
                )}

                {step === 'crop' && imageSrc && (
                    <DialogFooter className="shrink-0 gap-2 border-t bg-background px-6 py-4 sm:justify-center">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                reset();
                            }}
                        >
                            Back
                        </Button>
                        <Button
                            type="button"
                            disabled={
                                !isValidQuad(quadPoints) ||
                                detectingDocument ||
                                croppingDocument
                            }
                            onClick={() => {
                                void applyCrop();
                            }}
                        >
                            {croppingDocument ? (
                                <>
                                    <Loader2 className="size-4 animate-spin" />
                                    Cropping…
                                </>
                            ) : (
                                'Crop'
                            )}
                        </Button>
                    </DialogFooter>
                )}

                {step === 'enhance' && enhancedPreviewSrc && (
                    <DialogFooter className="shrink-0 gap-2 border-t bg-background px-4 py-2 sm:justify-center">
                        <select
                            id="scan-format"
                            value={outputFormat}
                            onChange={(event) =>
                                setOutputFormat(
                                    event.target.value as 'jpg' | 'png',
                                )
                            }
                            className="h-8 rounded-md border bg-background px-2 text-xs"
                            aria-label="Save as format"
                        >
                            <option value="jpg">JPG</option>
                            <option value="png">PNG</option>
                        </select>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                setError(null);
                                setStep('crop');
                            }}
                        >
                            Back
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => {
                                void saveEnhancedImage();
                            }}
                        >
                            Save as {entry.invoice_no}.{outputFormat}
                        </Button>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}
