import type { Point, QuadPoints } from '@/lib/invoice-polygon-crop';

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

function toGrayscale(data: Uint8ClampedArray, length: number): Uint8Array {
    const gray = new Uint8Array(length);

    for (let index = 0; index < length; index++) {
        const offset = index * 4;
        gray[index] =
            (data[offset] * 0.299 +
                data[offset + 1] * 0.587 +
                data[offset + 2] * 0.114) |
            0;
    }

    return gray;
}

function boxBlur(
    source: Uint8Array,
    width: number,
    height: number,
    radius: number,
): Uint8Array {
    const output = new Uint8Array(source.length);
    const windowSize = radius * 2 + 1;

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            let sum = 0;

            for (let offsetY = -radius; offsetY <= radius; offsetY++) {
                for (let offsetX = -radius; offsetX <= radius; offsetX++) {
                    const sampleX = clamp(x + offsetX, 0, width - 1);
                    const sampleY = clamp(y + offsetY, 0, height - 1);
                    sum += source[sampleY * width + sampleX];
                }
            }

            output[y * width + x] = Math.round(sum / (windowSize * windowSize));
        }
    }

    return output;
}

function otsuThreshold(source: Uint8Array): number {
    const histogram = new Array<number>(256).fill(0);

    for (const value of source) {
        histogram[value]++;
    }

    const total = source.length;
    let sum = 0;

    for (let index = 0; index < 256; index++) {
        sum += index * histogram[index];
    }

    let sumBackground = 0;
    let weightBackground = 0;
    let maximum = 0;
    let threshold = 128;

    for (let index = 0; index < 256; index++) {
        weightBackground += histogram[index];
        if (weightBackground === 0) {
            continue;
        }

        const weightForeground = total - weightBackground;
        if (weightForeground === 0) {
            break;
        }

        sumBackground += index * histogram[index];
        const meanBackground = sumBackground / weightBackground;
        const meanForeground = (sum - sumBackground) / weightForeground;
        const between =
            weightBackground *
            weightForeground *
            (meanBackground - meanForeground) ** 2;

        if (between > maximum) {
            maximum = between;
            threshold = index;
        }
    }

    return threshold;
}

function toBinary(source: Uint8Array, threshold: number): Uint8Array {
    const binary = new Uint8Array(source.length);

    for (let index = 0; index < source.length; index++) {
        binary[index] = source[index] >= threshold ? 1 : 0;
    }

    return binary;
}

function dilate(source: Uint8Array, width: number, height: number): Uint8Array {
    const output = new Uint8Array(source.length);

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            let value = 0;

            for (const [offsetX, offsetY] of [
                [-1, 0],
                [1, 0],
                [0, -1],
                [0, 1],
                [0, 0],
            ]) {
                const sampleX = x + offsetX;
                const sampleY = y + offsetY;

                if (
                    sampleX >= 0 &&
                    sampleX < width &&
                    sampleY >= 0 &&
                    sampleY < height &&
                    source[sampleY * width + sampleX] === 1
                ) {
                    value = 1;
                    break;
                }
            }

            output[y * width + x] = value;
        }
    }

    return output;
}

function erode(source: Uint8Array, width: number, height: number): Uint8Array {
    const output = new Uint8Array(source.length);

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            let value = 1;

            for (const [offsetX, offsetY] of [
                [-1, 0],
                [1, 0],
                [0, -1],
                [0, 1],
                [0, 0],
            ]) {
                const sampleX = x + offsetX;
                const sampleY = y + offsetY;

                if (
                    sampleX < 0 ||
                    sampleX >= width ||
                    sampleY < 0 ||
                    sampleY >= height ||
                    source[sampleY * width + sampleX] === 0
                ) {
                    value = 0;
                    break;
                }
            }

            output[y * width + x] = value;
        }
    }

    return output;
}

function largestComponent(binary: Uint8Array, width: number, height: number): Point[] {
    const visited = new Uint8Array(binary.length);
    let bestPoints: Point[] = [];

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const startIndex = y * width + x;

            if (binary[startIndex] === 0 || visited[startIndex] === 1) {
                continue;
            }

            const queue: number[] = [startIndex];
            const points: Point[] = [];
            visited[startIndex] = 1;

            while (queue.length > 0) {
                const index = queue.pop() as number;
                const pointX = index % width;
                const pointY = Math.floor(index / width);
                points.push({ x: pointX, y: pointY });

                for (const [offsetX, offsetY] of [
                    [-1, 0],
                    [1, 0],
                    [0, -1],
                    [0, 1],
                ]) {
                    const sampleX = pointX + offsetX;
                    const sampleY = pointY + offsetY;

                    if (
                        sampleX < 0 ||
                        sampleX >= width ||
                        sampleY < 0 ||
                        sampleY >= height
                    ) {
                        continue;
                    }

                    const sampleIndex = sampleY * width + sampleX;

                    if (
                        binary[sampleIndex] === 1 &&
                        visited[sampleIndex] === 0
                    ) {
                        visited[sampleIndex] = 1;
                        queue.push(sampleIndex);
                    }
                }
            }

            if (points.length > bestPoints.length) {
                bestPoints = points;
            }
        }
    }

    return bestPoints;
}

function boundaryPoints(
    points: Point[],
    binary: Uint8Array,
    width: number,
    height: number,
): Point[] {
    const pointSet = new Set(points.map((point) => `${point.x},${point.y}`));
    const boundary: Point[] = [];

    for (const point of points) {
        const neighbors = [
            [point.x - 1, point.y],
            [point.x + 1, point.y],
            [point.x, point.y - 1],
            [point.x, point.y + 1],
        ];
        const isBoundary = neighbors.some(([x, y]) => {
            if (x < 0 || x >= width || y < 0 || y >= height) {
                return true;
            }

            return !pointSet.has(`${x},${y}`);
        });

        if (isBoundary) {
            boundary.push(point);
        }
    }

    return boundary.length > 0 ? boundary : points;
}

function cornersFromPoints(points: Point[]): Point[] | null {
    if (points.length < 4) {
        return null;
    }

    let topLeft = points[0];
    let topRight = points[0];
    let bottomRight = points[0];
    let bottomLeft = points[0];

    for (const point of points) {
        const sum = point.x + point.y;
        const diff = point.x - point.y;

        if (sum < topLeft.x + topLeft.y) {
            topLeft = point;
        }

        if (diff > topRight.x - topRight.y) {
            topRight = point;
        }

        if (sum > bottomRight.x + bottomRight.y) {
            bottomRight = point;
        }

        if (diff < bottomLeft.x - bottomLeft.y) {
            bottomLeft = point;
        }
    }

    return [topLeft, topRight, bottomRight, bottomLeft];
}

function normalizeQuad(
    corners: Point[],
    width: number,
    height: number,
): QuadPoints | null {
    const [topLeft, topRight, bottomRight, bottomLeft] = corners;
    const area = Math.abs(
        (topLeft.x * topRight.y -
            topRight.x * topLeft.y +
            topRight.x * bottomRight.y -
            bottomRight.x * topRight.y +
            bottomRight.x * bottomLeft.y -
            bottomLeft.x * bottomRight.y +
            bottomLeft.x * topLeft.y -
            topLeft.x * bottomLeft.y) /
            2,
    );

    if (area < width * height * 0.08) {
        return null;
    }

    const pad = 0.01;

    return {
        topLeft: {
            x: clamp(topLeft.x / width, pad, 1 - pad),
            y: clamp(topLeft.y / height, pad, 1 - pad),
        },
        topRight: {
            x: clamp(topRight.x / width, pad, 1 - pad),
            y: clamp(topRight.y / height, pad, 1 - pad),
        },
        bottomRight: {
            x: clamp(bottomRight.x / width, pad, 1 - pad),
            y: clamp(bottomRight.y / height, pad, 1 - pad),
        },
        bottomLeft: {
            x: clamp(bottomLeft.x / width, pad, 1 - pad),
            y: clamp(bottomLeft.y / height, pad, 1 - pad),
        },
    };
}

function detectFromBinary(
    binary: Uint8Array,
    width: number,
    height: number,
): QuadPoints | null {
    const component = largestComponent(binary, width, height);
    const edgePoints = boundaryPoints(component, binary, width, height);
    const sampled = edgePoints.filter(
        (_, index) => index % Math.max(1, Math.floor(edgePoints.length / 400)) === 0,
    );
    const corners = cornersFromPoints(sampled);

    if (!corners) {
        return null;
    }

    return normalizeQuad(corners, width, height);
}

export async function detectDocumentQuad(
    image: HTMLImageElement,
): Promise<QuadPoints | null> {
    const maxDimension = 720;
    const scale = Math.min(
        1,
        maxDimension / Math.max(image.naturalWidth, image.naturalHeight),
    );
    const width = Math.max(1, Math.round(image.naturalWidth * scale));
    const height = Math.max(1, Math.round(image.naturalHeight * scale));
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d');

    if (!context) {
        return null;
    }

    context.drawImage(image, 0, 0, width, height);
    const { data } = context.getImageData(0, 0, width, height);
    const gray = toGrayscale(data, width * height);
    const blurred = boxBlur(gray, width, height, 2);
    const threshold = otsuThreshold(blurred);

    const lightDocument = detectFromBinary(
        toBinary(blurred, threshold),
        width,
        height,
    );
    const inverted = new Uint8Array(blurred.length);

    for (let index = 0; index < blurred.length; index++) {
        inverted[index] = blurred[index] >= threshold ? 0 : 1;
    }

    const darkDocument = detectFromBinary(inverted, width, height);
    const closedLight = detectFromBinary(
        dilate(erode(toBinary(blurred, threshold), width, height), width, height),
        width,
        height,
    );

    return lightDocument ?? darkDocument ?? closedLight;
}
