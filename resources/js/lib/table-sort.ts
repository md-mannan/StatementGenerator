export type SortDirection = 'asc' | 'desc';

export function toggleSortDirection(
    currentColumn: string,
    nextColumn: string,
    currentDirection: SortDirection,
): SortDirection {
    return currentColumn === nextColumn && currentDirection === 'asc'
        ? 'desc'
        : 'asc';
}

export function parseDdMmYyyy(value: string): number {
    const [day, month, year] = value.split('/').map(Number);

    if (!day || !month || !year) {
        return 0;
    }

    return new Date(year, month - 1, day).getTime();
}

export function parseDdMmYyyyHhMm(value: string): number {
    const [datePart, timePart = '00:00'] = value.split(' ');
    const [day, month, year] = datePart.split('/').map(Number);
    const [hours, minutes] = timePart.split(':').map(Number);

    if (!day || !month || !year) {
        return 0;
    }

    return new Date(year, month - 1, day, hours, minutes).getTime();
}

export function compareStrings(
    left: string,
    right: string,
    direction: SortDirection,
): number {
    const result = left.localeCompare(right, undefined, {
        numeric: true,
        sensitivity: 'base',
    });

    return direction === 'asc' ? result : -result;
}

export function compareNumbers(
    left: number,
    right: number,
    direction: SortDirection,
): number {
    const result = left - right;

    return direction === 'asc' ? result : -result;
}

export function compareBooleans(
    left: boolean,
    right: boolean,
    direction: SortDirection,
): number {
    const result = Number(left) - Number(right);

    return direction === 'asc' ? result : -result;
}
