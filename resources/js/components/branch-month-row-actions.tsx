import { Form, Link } from '@inertiajs/react';
import { FileSpreadsheet } from 'lucide-react';
import BranchController from '@/actions/App/Http/Controllers/BranchController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { index as statementsIndex } from '@/routes/branches/statements';
import { importMethod as statementsImport } from '@/routes/branches/statements';
import type { Branch, BranchMonthStat } from '@/types';

type Props = {
    branch: Branch;
    stat: BranchMonthStat | null;
    onEdit: (branch: Branch) => void;
    layout?: 'row' | 'grid';
};

export function BranchMonthRowActions({
    branch,
    stat,
    onEdit,
    layout = 'row',
}: Props) {
    const canViewStatement =
        stat !== null && stat.year > 0 && stat.month > 0;

    return (
        <div
            className={cn(
                layout === 'grid'
                    ? 'grid grid-cols-2 gap-2'
                    : 'flex flex-wrap justify-end gap-2',
            )}
        >
            <Button
                variant="outline"
                size="sm"
                className={cn(layout === 'grid' && 'h-10 w-full')}
                asChild={canViewStatement}
                disabled={!canViewStatement}
            >
                {canViewStatement && stat ? (
                    <Link
                        href={statementsIndex(branch.id, {
                            query: {
                                year: stat.year,
                                month: stat.month,
                            },
                        })}
                    >
                        <FileSpreadsheet className="size-4" />
                        Statement
                    </Link>
                ) : (
                    <>
                        <FileSpreadsheet className="size-4" />
                        Statement
                    </>
                )}
            </Button>
            <Button
                variant="outline"
                size="sm"
                className={cn(layout === 'grid' && 'h-10 w-full')}
                asChild
            >
                <Link href={statementsImport(branch.id)}>Upload</Link>
            </Button>
            <Button
                variant="ghost"
                size="sm"
                className={cn(layout === 'grid' && 'h-10 w-full')}
                onClick={() => onEdit(branch)}
            >
                Edit
            </Button>
            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="ghost"
                        size="sm"
                        className={cn(
                            layout === 'grid' && 'h-10 w-full',
                            layout === 'grid' &&
                                'text-destructive hover:text-destructive',
                        )}
                    >
                        Delete
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Delete branch?</DialogTitle>
                    <DialogDescription>
                        This will delete all statement entries for {branch.name}.
                    </DialogDescription>
                    <Form {...BranchController.destroy.form(branch.id)}>
                        {({ processing }) => (
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    disabled={processing}
                                    asChild
                                >
                                    <button type="submit">Delete</button>
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
