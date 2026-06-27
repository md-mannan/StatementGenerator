export type Point = {
    x: number;
    y: number;
};

export type QuadPoints = {
    topLeft: Point;
    topRight: Point;
    bottomRight: Point;
    bottomLeft: Point;
};

export function defaultQuadPoints(): QuadPoints {
    return {
        topLeft: { x: 0.05, y: 0.05 },
        topRight: { x: 0.95, y: 0.05 },
        bottomRight: { x: 0.95, y: 0.95 },
        bottomLeft: { x: 0.05, y: 0.95 },
    };
}

export function quadPointsToPolygon(quad: QuadPoints): string {
    const { topLeft, topRight, bottomRight, bottomLeft } = quad;

    return `${topLeft.x},${topLeft.y} ${topRight.x},${topRight.y} ${bottomRight.x},${bottomRight.y} ${bottomLeft.x},${bottomLeft.y}`;
}

export function isValidQuad(quad: QuadPoints): boolean {
    const natural = [
        { x: quad.topLeft.x, y: quad.topLeft.y },
        { x: quad.topRight.x, y: quad.topRight.y },
        { x: quad.bottomRight.x, y: quad.bottomRight.y },
        { x: quad.bottomLeft.x, y: quad.bottomLeft.y },
    ];

    const area = Math.abs(
        natural.reduce((sum, point, index) => {
            const next = natural[(index + 1) % natural.length];

            return sum + point.x * next.y - next.x * point.y;
        }, 0) / 2,
    );

    return area > 0.002;
}

function distance(a: Point, b: Point): number {
    return Math.hypot(a.x - b.x, a.y - b.y);
}

function displayQuadToNatural(
    quad: QuadPoints,
    image: HTMLImageElement,
): QuadPoints {
    const scaleX = image.naturalWidth / image.width;
    const scaleY = image.naturalHeight / image.height;

    const map = (point: Point): Point => ({
        x: point.x * image.width * scaleX,
        y: point.y * image.height * scaleY,
    });

    return {
        topLeft: map(quad.topLeft),
        topRight: map(quad.topRight),
        bottomRight: map(quad.bottomRight),
        bottomLeft: map(quad.bottomLeft),
    };
}

function computeOutputSize(quad: QuadPoints): { width: number; height: number } {
    const width = Math.max(
        distance(quad.topLeft, quad.topRight),
        distance(quad.bottomLeft, quad.bottomRight),
    );
    const height = Math.max(
        distance(quad.topLeft, quad.bottomLeft),
        distance(quad.topRight, quad.bottomRight),
    );

    return {
        width: Math.max(Math.round(width), 1),
        height: Math.max(Math.round(height), 1),
    };
}

function solveLinearSystem(matrix: number[][], values: number[]): number[] {
    const size = values.length;
    const augmented = matrix.map((row, index) => [...row, values[index]]);

    for (let column = 0; column < size; column++) {
        let pivotRow = column;

        for (let row = column + 1; row < size; row++) {
            if (
                Math.abs(augmented[row][column]) >
                Math.abs(augmented[pivotRow][column])
            ) {
                pivotRow = row;
            }
        }

        [augmented[column], augmented[pivotRow]] = [
            augmented[pivotRow],
            augmented[column],
        ];

        const pivot = augmented[column][column];

        if (Math.abs(pivot) < 1e-10) {
            continue;
        }

        for (let row = column + 1; row < size; row++) {
            const factor = augmented[row][column] / pivot;

            for (let col = column; col <= size; col++) {
                augmented[row][col] -= factor * augmented[column][col];
            }
        }
    }

    const solution = new Array<number>(size).fill(0);

    for (let row = size - 1; row >= 0; row--) {
        let sum = augmented[row][size];

        for (let col = row + 1; col < size; col++) {
            sum -= augmented[row][col] * solution[col];
        }

        solution[row] =
            Math.abs(augmented[row][row]) < 1e-10
                ? 0
                : sum / augmented[row][row];
    }

    return solution;
}

function computeHomography(from: Point[], to: Point[]): number[] {
    const matrix: number[][] = [];
    const values: number[] = [];

    for (let index = 0; index < 4; index++) {
        const { x, y } = from[index];
        const { x: targetX, y: targetY } = to[index];

        matrix.push([x, y, 1, 0, 0, 0, -targetX * x, -targetX * y]);
        values.push(targetX);
        matrix.push([0, 0, 0, x, y, 1, -targetY * x, -targetY * y]);
        values.push(targetY);
    }

    const coefficients = solveLinearSystem(matrix, values);

    return [...coefficients, 1];
}

function sampleBilinear(
    data: Uint8ClampedArray,
    width: number,
    height: number,
    x: number,
    y: number,
): [number, number, number] {
    if (x < 0 || y < 0 || x >= width - 1 || y >= height - 1) {
        return [255, 255, 255];
    }

    const x0 = Math.floor(x);
    const y0 = Math.floor(y);
    const x1 = x0 + 1;
    const y1 = y0 + 1;
    const xWeight = x - x0;
    const yWeight = y - y0;

    const sample = (sampleX: number, sampleY: number): [number, number, number] => {
        const offset = (sampleY * width + sampleX) * 4;

        return [data[offset], data[offset + 1], data[offset + 2]];
    };

    const topLeft = sample(x0, y0);
    const topRight = sample(x1, y0);
    const bottomLeft = sample(x0, y1);
    const bottomRight = sample(x1, y1);

    const interpolate = (channel: 0 | 1 | 2): number => {
        const top =
            topLeft[channel] +
            (topRight[channel] - topLeft[channel]) * xWeight;
        const bottom =
            bottomLeft[channel] +
            (bottomRight[channel] - bottomLeft[channel]) * xWeight;

        return Math.round(top + (bottom - top) * yWeight);
    };

    return [interpolate(0), interpolate(1), interpolate(2)];
}

function warpPerspective(
    image: HTMLImageElement,
    quad: QuadPoints,
    width: number,
    height: number,
): HTMLCanvasElement {
    const sourceCanvas = document.createElement('canvas');
    sourceCanvas.width = image.naturalWidth;
    sourceCanvas.height = image.naturalHeight;

    const sourceContext = sourceCanvas.getContext('2d');

    if (!sourceContext) {
        throw new Error('Could not prepare source canvas.');
    }

    sourceContext.drawImage(image, 0, 0);
    const sourceImage = sourceContext.getImageData(
        0,
        0,
        sourceCanvas.width,
        sourceCanvas.height,
    );

    const destinationPoints = [
        { x: 0, y: 0 },
        { x: width, y: 0 },
        { x: width, y: height },
        { x: 0, y: height },
    ];
    const sourcePoints = [
        quad.topLeft,
        quad.topRight,
        quad.bottomRight,
        quad.bottomLeft,
    ];
    const homography = computeHomography(destinationPoints, sourcePoints);

    const outputCanvas = document.createElement('canvas');
    outputCanvas.width = width;
    outputCanvas.height = height;

    const outputContext = outputCanvas.getContext('2d');

    if (!outputContext) {
        throw new Error('Could not prepare output canvas.');
    }

    const outputImage = outputContext.createImageData(width, height);

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const divisor =
                homography[6] * x + homography[7] * y + homography[8];
            const sourceX =
                (homography[0] * x + homography[1] * y + homography[2]) /
                divisor;
            const sourceY =
                (homography[3] * x + homography[4] * y + homography[5]) /
                divisor;
            const [red, green, blue] = sampleBilinear(
                sourceImage.data,
                sourceCanvas.width,
                sourceCanvas.height,
                sourceX,
                sourceY,
            );
            const offset = (y * width + x) * 4;
            outputImage.data[offset] = red;
            outputImage.data[offset + 1] = green;
            outputImage.data[offset + 2] = blue;
            outputImage.data[offset + 3] = 255;
        }
    }

    outputContext.putImageData(outputImage, 0, 0);

    return outputCanvas;
}

export async function cropImageWithQuadToBlob(
    image: HTMLImageElement,
    normalizedQuad: QuadPoints,
    mimeType: 'image/jpeg' | 'image/png' = 'image/jpeg',
): Promise<Blob> {
    const canvas = cropImageWithQuadToCanvas(image, normalizedQuad);

    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    reject(new Error('Could not export cropped image.'));

                    return;
                }

                resolve(blob);
            },
            mimeType,
            0.92,
        );
    });
}

export function cropImageWithQuadToCanvas(
    image: HTMLImageElement,
    normalizedQuad: QuadPoints,
): HTMLCanvasElement {
    if (!isValidQuad(normalizedQuad)) {
        throw new Error('Selection is too small.');
    }

    const quad = displayQuadToNatural(normalizedQuad, image);
    const { width, height } = computeOutputSize(quad);

    if (width < 10 || height < 10) {
        throw new Error('Selection is too small.');
    }

    return warpPerspective(image, quad, width, height);
}
