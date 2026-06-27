import { Plus, Trash2 } from 'lucide-react';
import { useRef, type ClipboardEvent, type FocusEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    AppTable,
    AppTableScrollContainer,
    stickyTableHeadCellClassName,
} from '@/components/app-table';
import { cn } from '@/lib/utils';
import {
    createEmptySpreadsheetRow,
    parseSpreadsheetGrid,
    type SpreadsheetRow,
} from '@/lib/spreadsheet-paste';

type Props = {
    rows: SpreadsheetRow[];
    onChange: (rows: SpreadsheetRow[]) => void;
    rowErrors?: Record<number, Partial<Record<keyof SpreadsheetRow, string>>>;
};

type ColumnKey = keyof SpreadsheetRow;

const COLUMNS: Array<{
    key: ColumnKey;
    label: string;
    align: 'left' | 'right';
    placeholder: string;
    width: string;
}> = [
    {
        key: 'transaction_date',
        label: 'Invoice Date',
        align: 'left',
        placeholder: 'dd/mm/yyyy',
        width: 'min-w-[140px]',
    },
    {
        key: 'invoice_no',
        label: 'Invoice No',
        align: 'left',
        placeholder: 'Invoice no',
        width: 'min-w-[160px]',
    },
    {
        key: 'amount',
        label: 'Amount',
        align: 'right',
        placeholder: '0.000',
        width: 'min-w-[120px]',
    },
];

function parseAmount(value: string): number {
    const cleaned = value.replace(/,/g, '').trim();

    if (cleaned === '') {
        return 0;
    }

    const numeric = Number.parseFloat(cleaned);

    return Number.isFinite(numeric) ? numeric : 0;
}

function mapRowErrors(
    errors: Record<number, Partial<Record<ColumnKey, string>>>,
    index: number,
    field: ColumnKey,
): string | undefined {
    return errors[index]?.[field];
}

function SpreadsheetCell({
    value,
    onChange,
    align,
    placeholder,
    error,
    onFocus,
}: {
    value: string;
    onChange: (value: string) => void;
    align: 'left' | 'right';
    placeholder: string;
    error?: string;
    onFocus?: (event: FocusEvent<HTMLInputElement>) => void;
}) {
    return (
        <div className="relative h-full w-full">
            <input
                type="text"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                onFocus={onFocus}
                placeholder={placeholder}
                aria-invalid={Boolean(error)}
                className={cn(
                    'h-9 w-full border-0 bg-transparent px-2 text-sm shadow-none outline-none',
                    'focus:bg-white focus:ring-2 focus:ring-inset focus:ring-[#217346] dark:focus:bg-background',
                    align === 'right' ? 'text-right font-mono' : 'font-mono',
                    error && 'bg-red-50 ring-2 ring-inset ring-red-400 dark:bg-red-950/30',
                )}
            />
            {error && (
                <p className="absolute top-full left-0 z-10 mt-0.5 max-w-full truncate px-1 text-[10px] text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

export function StatementEntryGrid({ rows, onChange, rowErrors = {} }: Props) {
    const focusedCellRef = useRef<{ row: number; col: number }>({
        row: 0,
        col: 0,
    });
    const totalAmount = rows.reduce((sum, row) => sum + parseAmount(row.amount), 0);

    function updateRow(index: number, field: ColumnKey, value: string) {
        onChange(
            rows.map((row, rowIndex) =>
                rowIndex === index ? { ...row, [field]: value } : row,
            ),
        );
    }

    function addRow() {
        onChange([...rows, createEmptySpreadsheetRow()]);
    }

    function removeRow(index: number) {
        if (rows.length === 1) {
            onChange([createEmptySpreadsheetRow()]);

            return;
        }

        onChange(rows.filter((_, rowIndex) => rowIndex !== index));
    }

    function applyGridPaste(text: string, startRow: number, startCol: number) {
        const grid = parseSpreadsheetGrid(text);

        if (grid.length === 0) {
            return;
        }

        const nextRows = rows.map((row) => ({ ...row }));

        grid.forEach((cells, rowOffset) => {
            const targetRowIndex = startRow + rowOffset;

            while (nextRows.length <= targetRowIndex) {
                nextRows.push(createEmptySpreadsheetRow());
            }

            cells.forEach((cellValue, colOffset) => {
                const column = COLUMNS[startCol + colOffset];

                if (!column) {
                    return;
                }

                nextRows[targetRowIndex] = {
                    ...nextRows[targetRowIndex],
                    [column.key]: cellValue.trim(),
                };
            });
        });

        onChange(nextRows);
    }

    function handlePasteCapture(event: ClipboardEvent<HTMLDivElement>) {
        const text = event.clipboardData.getData('text');

        if (!text.includes('\t') && !text.includes('\n')) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const { row, col } = focusedCellRef.current;
        applyGridPaste(text, row, col);
    }

    return (
        <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
                Spreadsheet entry — paste invoice date, invoice no, and amount.
                Invoice date can be in a different month than the statement you
                are viewing.
            </p>
            <AppTableScrollContainer
                className="max-h-[min(70vh,640px)] rounded-sm border border-[#ababab] bg-white shadow-sm dark:border-border dark:bg-background"
                onPasteCapture={handlePasteCapture}
            >
                <AppTable className="app-table-spreadsheet w-full border-collapse">
                    <thead>
                        <tr className="bg-[#217346] text-white">
                            <th className="sticky top-0 left-0 z-30 w-10 min-w-10 border border-[#1a5c37] bg-[#217346] px-1 py-2 text-center text-xs font-medium">
                                #
                            </th>
                            {COLUMNS.map((column) => (
                                <th
                                    key={column.key}
                                    className={cn(
                                        stickyTableHeadCellClassName,
                                        'border border-[#1a5c37] bg-[#217346] px-2 py-2 text-xs font-semibold tracking-wide text-white uppercase shadow-none',
                                        column.align === 'right'
                                            ? 'text-right'
                                            : 'text-left',
                                        column.width,
                                    )}
                                >
                                    {column.label}
                                </th>
                            ))}
                            <th
                                className={cn(
                                    stickyTableHeadCellClassName,
                                    'w-10 min-w-10 border border-[#1a5c37] bg-[#217346] px-1 py-2 text-center text-xs font-medium text-white shadow-none',
                                )}
                            >
                                ···
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, rowIndex) => (
                            <tr
                                key={rowIndex}
                                className="group hover:bg-[#e8f4fc] dark:hover:bg-muted/40"
                            >
                                <td className="sticky left-0 z-10 border border-[#d4d4d4] bg-[#f3f3f3] px-1 py-0 text-center text-xs font-medium text-muted-foreground group-hover:bg-[#dceaf6] dark:border-border dark:bg-muted/60 dark:group-hover:bg-muted/50">
                                    {rowIndex + 1}
                                </td>
                                {COLUMNS.map((column, colIndex) => {
                                    const error = mapRowErrors(
                                        rowErrors,
                                        rowIndex,
                                        column.key,
                                    );

                                    return (
                                        <td
                                            key={column.key}
                                            className={cn(
                                                'relative border border-[#d4d4d4] p-0 align-middle dark:border-border',
                                                column.width,
                                                error && 'bg-red-50/80',
                                            )}
                                        >
                                            <SpreadsheetCell
                                                value={row[column.key]}
                                                onChange={(value) =>
                                                    updateRow(
                                                        rowIndex,
                                                        column.key,
                                                        value,
                                                    )
                                                }
                                                onFocus={() => {
                                                    focusedCellRef.current = {
                                                        row: rowIndex,
                                                        col: colIndex,
                                                    };
                                                }}
                                                align={column.align}
                                                placeholder={
                                                    column.placeholder
                                                }
                                                error={error}
                                            />
                                        </td>
                                    );
                                })}
                                <td className="border border-[#d4d4d4] p-0 text-center align-middle dark:border-border">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8 opacity-40 group-hover:opacity-100"
                                        onClick={() => removeRow(rowIndex)}
                                        aria-label={`Remove row ${rowIndex + 1}`}
                                    >
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot>
                        <tr className="bg-[#f3f3f3] dark:bg-muted/60">
                            <td className="sticky left-0 z-10 border border-[#d4d4d4] bg-[#f3f3f3] px-1 py-2 text-center text-xs font-semibold text-muted-foreground dark:border-border dark:bg-muted/60">
                                Total
                            </td>
                            <td className="border border-[#d4d4d4] px-2 py-2 text-xs dark:border-border" />
                            <td className="border border-[#d4d4d4] px-2 py-2 text-xs dark:border-border" />
                            <td className="border border-[#d4d4d4] px-2 py-2 text-right font-mono text-xs font-semibold dark:border-border">
                                {totalAmount.toFixed(3)}
                            </td>
                            <td className="border border-[#d4d4d4] px-1 py-2 text-center text-xs dark:border-border" />
                        </tr>
                    </tfoot>
                </AppTable>
            </AppTableScrollContainer>
            <Button type="button" variant="outline" size="sm" onClick={addRow}>
                <Plus className="size-4" />
                Add row
            </Button>
        </div>
    );
}
