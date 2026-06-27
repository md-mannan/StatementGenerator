export function scanFilenameForInvoice(
    invoiceNo: string,
    extension: 'pdf' | 'jpg' | 'png' | 'webp',
): string {
    const sanitized = invoiceNo.trim().replace(/[\\/:*?"<>|]/g, '_');

    return `${sanitized}.${extension}`;
}

export function readFileAsDataUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(new Error('Failed to read file.'));
        reader.readAsDataURL(file);
    });
}

export function fitImageToContainer(
    image: HTMLImageElement,
    container: HTMLElement,
    padding = 32,
): void {
    const maxWidth = Math.max(container.clientWidth - padding, 1);
    const maxHeight = Math.max(container.clientHeight - padding, 1);
    const scale = Math.min(
        maxWidth / image.naturalWidth,
        maxHeight / image.naturalHeight,
    );
    const width = Math.floor(image.naturalWidth * scale);
    const height = Math.floor(image.naturalHeight * scale);

    image.style.width = `${width}px`;
    image.style.height = `${height}px`;
    image.style.maxWidth = 'none';
    image.style.maxHeight = 'none';
}
