export type SpreadsheetRow = {
    transaction_date: string;
    invoice_no: string;
    amount: string;
};

const HEADER_VALUES = new Set([
    'date',
    'transaction_date',
    'transaction date',
    'invoice',
    'invoice no',
    'invoice_no',
    'invoice number',
    'amount',
    'total',
    'value',
]);

function splitLine(line: string): string[] {
    if (line.includes('\t')) {
        return line.split('\t').map((cell) => cell.trim());
    }

    return line.split(',').map((cell) => cell.trim());
}

function isHeaderRow(cells: string[]): boolean {
    const normalized = cells
        .map((cell) => cell.trim().toLowerCase())
        .filter((cell) => cell !== '');

    if (normalized.length === 0) {
        return true;
    }

    return normalized.every((cell) => HEADER_VALUES.has(cell));
}

export function parseSpreadsheetGrid(text: string): string[][] {
    const grid = text
        .trim()
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '')
        .map((line) => splitLine(line));

    if (grid.length === 0) {
        return [];
    }

    if (isHeaderRow(grid[0])) {
        return grid.slice(1);
    }

    return grid;
}

export function parseSpreadsheetPaste(text: string): SpreadsheetRow[] {
    return parseSpreadsheetGrid(text)
        .map((cells) => ({
            transaction_date: cells[0] ?? '',
            invoice_no: cells[1] ?? '',
            amount: cells[2] ?? '',
        }))
        .filter(
            (row) =>
                row.transaction_date.trim() !== '' ||
                row.invoice_no.trim() !== '' ||
                row.amount.trim() !== '',
        );
}

export function createEmptySpreadsheetRow(): SpreadsheetRow {
    return {
        transaction_date: '',
        invoice_no: '',
        amount: '',
    };
}

export function createEmptySpreadsheetRows(count: number): SpreadsheetRow[] {
    return Array.from({ length: count }, () => createEmptySpreadsheetRow());
}

export function isSpreadsheetRowFilled(row: SpreadsheetRow): boolean {
    return (
        row.transaction_date.trim() !== '' ||
        row.invoice_no.trim() !== '' ||
        row.amount.trim() !== ''
    );
}

export function isSpreadsheetRowComplete(row: SpreadsheetRow): boolean {
    return (
        row.transaction_date.trim() !== '' &&
        row.invoice_no.trim() !== '' &&
        row.amount.trim() !== ''
    );
}

function normalizeDateInput(value: string): string {
    return value
        .trim()
        .replace(/[\uFEFF\u200B]/g, '')
        .replace(/-/g, '/');
}

function normalizeYear(year: number): number {
    if (year >= 100) {
        return year;
    }

    return year >= 70 ? 1900 + year : 2000 + year;
}

export function parseSpreadsheetDateParts(
    value: string,
): { day: number; month: number; year: number } | null {
    const normalized = normalizeDateInput(value);
    const parts = normalized.split('/');

    if (parts.length !== 3) {
        return null;
    }

    const day = Number(parts[0]);
    const month = Number(parts[1]);
    const year = normalizeYear(Number(parts[2]));

    if (
        !Number.isFinite(day) ||
        !Number.isFinite(month) ||
        !Number.isFinite(year) ||
        day < 1 ||
        day > 31 ||
        month < 1 ||
        month > 12 ||
        year < 2000 ||
        year > 2100
    ) {
        return null;
    }

    return { day, month, year };
}

export function detectStatementPeriodFromRows(
    rows: SpreadsheetRow[],
): { year: number; month: number } | null {
    let year: number | null = null;
    let month: number | null = null;

    for (const row of rows) {
        if (row.transaction_date.trim() === '') {
            continue;
        }

        const parts = parseSpreadsheetDateParts(row.transaction_date);

        if (parts === null) {
            continue;
        }

        if (year === null) {
            year = parts.year;
            month = parts.month;
            continue;
        }

        if (year !== parts.year || month !== parts.month) {
            return null;
        }
    }

    if (year === null || month === null) {
        return null;
    }

    return { year, month };
}

export function detectStatementPeriodFromCompleteRows(
    rows: SpreadsheetRow[],
): { year: number; month: number } | null {
    return detectStatementPeriodFromRows(rows.filter(isSpreadsheetRowComplete));
}

export type SpreadsheetRowValidation = {
    period: { year: number; month: number } | null;
    invalidRowNumbers: number[];
    mixedRowNumbers: number[];
};

export function validateCompleteSpreadsheetRows(
    rows: SpreadsheetRow[],
): SpreadsheetRowValidation {
    const completeRows = rows.filter(isSpreadsheetRowComplete);
    const invalidRowNumbers: number[] = [];
    const mixedRowNumbers: number[] = [];
    let period: { year: number; month: number } | null = null;

    completeRows.forEach((row, index) => {
        const rowNumber = index + 1;
        const parts = parseSpreadsheetDateParts(row.transaction_date);

        if (parts === null) {
            invalidRowNumbers.push(rowNumber);

            return;
        }

        if (period === null) {
            period = { year: parts.year, month: parts.month };

            return;
        }

        if (parts.year !== period.year || parts.month !== period.month) {
            mixedRowNumbers.push(rowNumber);
        }
    });

    if (invalidRowNumbers.length > 0 || mixedRowNumbers.length > 0) {
        return {
            period: null,
            invalidRowNumbers,
            mixedRowNumbers,
        };
    }

    return {
        period,
        invalidRowNumbers,
        mixedRowNumbers,
    };
}
