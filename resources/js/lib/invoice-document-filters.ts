export type DocumentFilter =
    | 'original'
    | 'grayscale'
    | 'black_white'
    | 'enhanced';

export type DocumentAdjustments = {
    brightness: number;
    contrast: number;
    sharpness: number;
    hue: number;
    colorCorrection: number;
};

export const DEFAULT_DOCUMENT_ADJUSTMENTS: DocumentAdjustments = {
    brightness: 50,
    contrast: 50,
    sharpness: 50,
    hue: 50,
    colorCorrection: 50,
};

export const DOCUMENT_FILTERS: {
    value: DocumentFilter;
    label: string;
}[] = [
    { value: 'original', label: 'Original' },
    { value: 'grayscale', label: 'Grayscale' },
    { value: 'black_white', label: 'Black & white' },
    { value: 'enhanced', label: 'Enhanced' },
];

export const DOCUMENT_ADJUSTMENT_CONTROLS: {
    key: keyof DocumentAdjustments;
    label: string;
}[] = [
    { key: 'brightness', label: 'Brightness' },
    { key: 'colorCorrection', label: 'Color correction' },
    { key: 'contrast', label: 'Contrast' },
    { key: 'sharpness', label: 'Sharpness' },
    { key: 'hue', label: 'Hue' },
];

function cloneCanvas(source: HTMLCanvasElement): HTMLCanvasElement {
    const canvas = document.createElement('canvas');
    canvas.width = source.width;
    canvas.height = source.height;

    const context = canvas.getContext('2d');

    if (!context) {
        return canvas;
    }

    context.drawImage(source, 0, 0);

    return canvas;
}

function clampByte(value: number): number {
    return Math.min(255, Math.max(0, Math.round(value)));
}

function clampUnit(value: number): number {
    return Math.min(100, Math.max(0, Math.round(value)));
}

function percentile(values: Float32Array, ratio: number): number {
    const sorted = Array.from(values).sort((left, right) => left - right);
    const index = Math.min(
        sorted.length - 1,
        Math.max(0, Math.floor((sorted.length - 1) * ratio)),
    );

    return sorted[index];
}

function boxBlurChannel(
    source: Float32Array,
    width: number,
    height: number,
    radius: number,
): Float32Array {
    const output = new Float32Array(source.length);

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            let sum = 0;
            let count = 0;

            for (let offsetY = -radius; offsetY <= radius; offsetY++) {
                for (let offsetX = -radius; offsetX <= radius; offsetX++) {
                    const sampleX = Math.min(Math.max(x + offsetX, 0), width - 1);
                    const sampleY = Math.min(Math.max(y + offsetY, 0), height - 1);
                    sum += source[sampleY * width + sampleX];
                    count++;
                }
            }

            output[y * width + x] = sum / count;
        }
    }

    return output;
}

function localMean(
    gray: Float32Array,
    width: number,
    height: number,
    x: number,
    y: number,
    radius: number,
): number {
    let sum = 0;
    let count = 0;

    for (let offsetY = -radius; offsetY <= radius; offsetY++) {
        for (let offsetX = -radius; offsetX <= radius; offsetX++) {
            const sampleX = Math.min(Math.max(x + offsetX, 0), width - 1);
            const sampleY = Math.min(Math.max(y + offsetY, 0), height - 1);
            sum += gray[sampleY * width + sampleX];
            count++;
        }
    }

    return sum / count;
}

function unsharpMask(
    source: Float32Array,
    width: number,
    height: number,
    amount: number,
): Float32Array {
    const blurred = boxBlurChannel(source, width, height, 1);
    const output = new Float32Array(source.length);

    for (let index = 0; index < source.length; index++) {
        output[index] = clampByte(
            source[index] + amount * (source[index] - blurred[index]),
        );
    }

    return output;
}

function stretchGray(gray: Float32Array): Float32Array {
    const low = percentile(gray, 0.02);
    const high = percentile(gray, 0.98);
    const range = Math.max(high - low, 1);
    const output = new Float32Array(gray.length);

    for (let index = 0; index < gray.length; index++) {
        output[index] = clampByte(((gray[index] - low) / range) * 255);
    }

    return output;
}

function rgbToHsl(
    red: number,
    green: number,
    blue: number,
): [number, number, number] {
    const redNorm = red / 255;
    const greenNorm = green / 255;
    const blueNorm = blue / 255;
    const max = Math.max(redNorm, greenNorm, blueNorm);
    const min = Math.min(redNorm, greenNorm, blueNorm);
    const lightness = (max + min) / 2;
    let hue = 0;
    let saturation = 0;

    if (max !== min) {
        const delta = max - min;
        saturation =
            lightness > 0.5
                ? delta / (2 - max - min)
                : delta / (max + min);

        switch (max) {
            case redNorm:
                hue =
                    ((greenNorm - blueNorm) / delta +
                        (greenNorm < blueNorm ? 6 : 0)) /
                    6;
                break;
            case greenNorm:
                hue = ((blueNorm - redNorm) / delta + 2) / 6;
                break;
            default:
                hue = ((redNorm - greenNorm) / delta + 4) / 6;
                break;
        }
    }

    return [hue * 360, saturation, lightness];
}

function hueToRgb(p: number, q: number, t: number): number {
    let value = t;

    if (value < 0) {
        value += 1;
    }

    if (value > 1) {
        value -= 1;
    }

    if (value < 1 / 6) {
        return p + (q - p) * 6 * value;
    }

    if (value < 1 / 2) {
        return q;
    }

    if (value < 2 / 3) {
        return p + (q - p) * (2 / 3 - value) * 6;
    }

    return p;
}

function hslToRgb(
    hue: number,
    saturation: number,
    lightness: number,
): [number, number, number] {
    if (saturation === 0) {
        const gray = clampByte(lightness * 255);

        return [gray, gray, gray];
    }

    const normalizedHue = ((hue % 360) + 360) % 360;
    const hueSection = normalizedHue / 360;
    const q =
        lightness < 0.5
            ? lightness * (1 + saturation)
            : lightness + saturation - lightness * saturation;
    const p = 2 * lightness - q;

    return [
        clampByte(hueToRgb(p, q, hueSection + 1 / 3) * 255),
        clampByte(hueToRgb(p, q, hueSection) * 255),
        clampByte(hueToRgb(p, q, hueSection - 1 / 3) * 255),
    ];
}

function applyBlackAndWhite(
    data: Uint8ClampedArray,
    width: number,
    height: number,
): void {
    const gray = new Float32Array(width * height);

    for (let index = 0; index < width * height; index++) {
        const offset = index * 4;
        gray[index] =
            data[offset] * 0.299 +
            data[offset + 1] * 0.587 +
            data[offset + 2] * 0.114;
    }

    const stretched = stretchGray(
        unsharpMask(boxBlurChannel(gray, width, height, 1), width, height, 0.85),
    );

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const index = y * width + x;
            const offset = index * 4;
            const threshold = localMean(stretched, width, height, x, y, 10) - 8;
            const value = stretched[index] >= threshold ? 255 : 0;
            data[offset] = value;
            data[offset + 1] = value;
            data[offset + 2] = value;
        }
    }
}

function applyEnhancedColor(
    data: Uint8ClampedArray,
    width: number,
    height: number,
): void {
    const red = new Float32Array(width * height);
    const green = new Float32Array(width * height);
    const blue = new Float32Array(width * height);
    const luminance = new Float32Array(width * height);

    for (let index = 0; index < width * height; index++) {
        const offset = index * 4;
        red[index] = data[offset];
        green[index] = data[offset + 1];
        blue[index] = data[offset + 2];
        luminance[index] =
            red[index] * 0.299 + green[index] * 0.587 + blue[index] * 0.114;
    }

    const low = percentile(luminance, 0.02);
    const high = percentile(luminance, 0.98);
    const range = Math.max(high - low, 1);

    for (let index = 0; index < width * height; index++) {
        const currentLuminance = luminance[index];
        const targetLuminance = clampByte(
            ((currentLuminance - low) / range) * 255,
        );
        const scale =
            currentLuminance > 1 ? targetLuminance / currentLuminance : 1;

        red[index] = clampByte(red[index] * scale);
        green[index] = clampByte(green[index] * scale);
        blue[index] = clampByte(blue[index] * scale);
    }

    const sharpRed = unsharpMask(red, width, height, 0.55);
    const sharpGreen = unsharpMask(green, width, height, 0.55);
    const sharpBlue = unsharpMask(blue, width, height, 0.55);

    for (let index = 0; index < width * height; index++) {
        const offset = index * 4;
        const average =
            (sharpRed[index] + sharpGreen[index] + sharpBlue[index]) / 3;
        const saturation = 1.12;

        data[offset] = clampByte(
            average + (sharpRed[index] - average) * saturation,
        );
        data[offset + 1] = clampByte(
            average + (sharpGreen[index] - average) * saturation,
        );
        data[offset + 2] = clampByte(
            average + (sharpBlue[index] - average) * saturation,
        );
    }
}

function applyDocumentAdjustments(
    data: Uint8ClampedArray,
    width: number,
    height: number,
    adjustments: DocumentAdjustments,
): void {
    const brightnessOffset = ((adjustments.brightness - 50) / 50) * 80;
    const contrastFactor = Math.max(0.05, 1 + (adjustments.contrast - 50) / 50);
    const hueShift = ((adjustments.hue - 50) / 50) * 180;
    const saturationFactor = Math.max(
        0,
        1 + (adjustments.colorCorrection - 50) / 50,
    );
    const sharpnessAmount = ((adjustments.sharpness - 50) / 50) * 2.4;

    for (let index = 0; index < width * height; index++) {
        const offset = index * 4;
        let red = data[offset];
        let green = data[offset + 1];
        let blue = data[offset + 2];

        red = clampByte((red - 128) * contrastFactor + 128 + brightnessOffset);
        green = clampByte(
            (green - 128) * contrastFactor + 128 + brightnessOffset,
        );
        blue = clampByte(
            (blue - 128) * contrastFactor + 128 + brightnessOffset,
        );

        const [hue, saturation, lightness] = rgbToHsl(red, green, blue);
        const adjustedSaturation = Math.min(1, saturation * saturationFactor);
        const adjustedHue = hue + hueShift;
        [red, green, blue] = hslToRgb(
            adjustedHue,
            adjustedSaturation,
            lightness,
        );

        data[offset] = red;
        data[offset + 1] = green;
        data[offset + 2] = blue;
    }

    if (sharpnessAmount <= 0.01) {
        return;
    }

    const redChannel = new Float32Array(width * height);
    const greenChannel = new Float32Array(width * height);
    const blueChannel = new Float32Array(width * height);

    for (let index = 0; index < width * height; index++) {
        const offset = index * 4;
        redChannel[index] = data[offset];
        greenChannel[index] = data[offset + 1];
        blueChannel[index] = data[offset + 2];
    }

    const sharpRed = unsharpMask(
        redChannel,
        width,
        height,
        sharpnessAmount,
    );
    const sharpGreen = unsharpMask(
        greenChannel,
        width,
        height,
        sharpnessAmount,
    );
    const sharpBlue = unsharpMask(
        blueChannel,
        width,
        height,
        sharpnessAmount,
    );

    for (let index = 0; index < width * height; index++) {
        const offset = index * 4;
        data[offset] = sharpRed[index];
        data[offset + 1] = sharpGreen[index];
        data[offset + 2] = sharpBlue[index];
    }
}

export function applyDocumentFilter(
    source: HTMLCanvasElement,
    filter: DocumentFilter,
): HTMLCanvasElement {
    if (filter === 'original') {
        return cloneCanvas(source);
    }

    const canvas = cloneCanvas(source);
    const context = canvas.getContext('2d');

    if (!context) {
        return canvas;
    }

    const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
    const { width, height, data } = imageData;

    if (filter === 'grayscale') {
        const gray = stretchGray(
            new Float32Array(
                Array.from({ length: width * height }, (_, index) => {
                    const offset = index * 4;

                    return (
                        data[offset] * 0.299 +
                        data[offset + 1] * 0.587 +
                        data[offset + 2] * 0.114
                    );
                }),
            ),
        );

        for (let index = 0; index < width * height; index++) {
            const value = gray[index];
            const offset = index * 4;
            data[offset] = value;
            data[offset + 1] = value;
            data[offset + 2] = value;
        }
    }

    if (filter === 'black_white') {
        applyBlackAndWhite(data, width, height);
    }

    if (filter === 'enhanced') {
        applyEnhancedColor(data, width, height);
    }

    context.putImageData(imageData, 0, 0);

    return canvas;
}

export function renderEnhancedDocument(
    source: HTMLCanvasElement,
    filter: DocumentFilter,
    adjustments: DocumentAdjustments,
): HTMLCanvasElement {
    const canvas = applyDocumentFilter(source, filter);
    const context = canvas.getContext('2d');

    if (!context) {
        return canvas;
    }

    const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
    applyDocumentAdjustments(
        imageData.data,
        canvas.width,
        canvas.height,
        {
            brightness: clampUnit(adjustments.brightness),
            contrast: clampUnit(adjustments.contrast),
            sharpness: clampUnit(adjustments.sharpness),
            hue: clampUnit(adjustments.hue),
            colorCorrection: clampUnit(adjustments.colorCorrection),
        },
    );
    context.putImageData(imageData, 0, 0);

    return canvas;
}

export function canvasToBlob(
    canvas: HTMLCanvasElement,
    mimeType: 'image/jpeg' | 'image/png' = 'image/jpeg',
): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    reject(new Error('Could not export image.'));

                    return;
                }

                resolve(blob);
            },
            mimeType,
            0.92,
        );
    });
}

export function canvasToDataUrl(
    canvas: HTMLCanvasElement,
    mimeType: 'image/jpeg' | 'image/png' = 'image/jpeg',
): string {
    return canvas.toDataURL(mimeType, 0.92);
}
