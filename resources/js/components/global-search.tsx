import { router, usePage } from '@inertiajs/react';
import {
    Building2,
    FileSpreadsheet,
    GitCompareArrows,
    LayoutGrid,
    Loader2,
    Receipt,
    Search,
    Settings,
    Store,
    Wallet,
} from 'lucide-react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { search as globalSearch } from '@/routes';

export type GlobalSearchResult = {
    id: string;
    title: string;
    subtitle: string | null;
    group: string;
    href: string;
    keywords: string;
};

type GlobalSearchContextValue = {
    open: boolean;
    setOpen: (open: boolean) => void;
};

const GlobalSearchContext = createContext<GlobalSearchContextValue | null>(
    null,
);

function groupIcon(group: string) {
    switch (group) {
        case 'Pages':
            return LayoutGrid;
        case 'Settings':
            return Settings;
        case 'Client':
            return Building2;
        case 'Branch':
            return Store;
        case 'Branch Statement':
            return FileSpreadsheet;
        case 'Received Statement':
            return Receipt;
        case 'Annexure Invoice':
            return FileSpreadsheet;
        case 'Annexure Cheque':
            return Wallet;
        default:
            return FileSpreadsheet;
    }
}

function resultIcon(result: GlobalSearchResult) {
    if (result.title === 'Cross Check') {
        return GitCompareArrows;
    }

    if (result.title === 'Generate Statement') {
        return FileSpreadsheet;
    }

    return groupIcon(result.group);
}

export function GlobalSearchProvider({ children }: { children: ReactNode }) {
    const { auth } = usePage().props;
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!auth.user) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setOpen((current) => !current);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [auth.user]);

    const value = useMemo(
        () => ({
            open,
            setOpen,
        }),
        [open],
    );

    return (
        <GlobalSearchContext.Provider value={value}>
            {children}
            {auth.user && <GlobalSearchDialog />}
        </GlobalSearchContext.Provider>
    );
}

export function useGlobalSearch() {
    const context = useContext(GlobalSearchContext);

    if (!context) {
        throw new Error('useGlobalSearch must be used within GlobalSearchProvider');
    }

    return context;
}

function GlobalSearchDialog() {
    const { open, setOpen } = useGlobalSearch();
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<GlobalSearchResult[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);

    const navigateTo = useCallback(
        (href: string) => {
            setOpen(false);
            router.visit(href);
        },
        [setOpen],
    );

    useEffect(() => {
        if (!open) {
            setQuery('');
            setResults([]);
            setActiveIndex(0);
            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(async () => {
            setIsLoading(true);

            try {
                const url = globalSearch.url({ query: { q: query } });
                const response = await fetch(url, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    return;
                }

                const data = (await response.json()) as {
                    results: GlobalSearchResult[];
                };

                setResults(data.results);
                setActiveIndex(0);
            } catch (error) {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }

                throw error;
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoading(false);
                }
            }
        }, 200);

        return () => {
            controller.abort();
            window.clearTimeout(timeout);
        };
    }, [open, query]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActiveIndex((current) =>
                    results.length === 0
                        ? 0
                        : Math.min(current + 1, results.length - 1),
                );
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActiveIndex((current) =>
                    results.length === 0 ? 0 : Math.max(current - 1, 0),
                );
            }

            if (event.key === 'Enter' && results[activeIndex]) {
                event.preventDefault();
                navigateTo(results[activeIndex].href);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [activeIndex, navigateTo, open, results]);

    const groupedResults = useMemo(() => {
        const groups = new Map<string, GlobalSearchResult[]>();

        for (const result of results) {
            const existing = groups.get(result.group) ?? [];
            existing.push(result);
            groups.set(result.group, existing);
        }

        return Array.from(groups.entries());
    }, [results]);

    let flatIndex = -1;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent
                showCloseButton={false}
                className="top-[8vh] flex max-h-[84vh] w-[90vw] max-w-[90vw] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-[90vw]"
            >
                <DialogTitle className="sr-only">Global search</DialogTitle>
                <DialogDescription className="sr-only">
                    Search pages, clients, branches, and settings.
                </DialogDescription>

                <div className="flex items-center gap-3 border-b px-4 py-3">
                    <Search className="size-5 shrink-0 text-muted-foreground" />
                    <Input
                        autoFocus
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search invoices, cheques, amounts, dates, clients..."
                        className="h-11 border-0 bg-transparent px-0 text-base shadow-none focus-visible:ring-0"
                    />
                    {isLoading && (
                        <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" />
                    )}
                    <kbd className="hidden rounded border bg-muted px-2 py-1 text-xs text-muted-foreground sm:inline">
                        Esc
                    </kbd>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto p-2">
                    {results.length === 0 && !isLoading ? (
                        <p className="px-3 py-8 text-center text-sm text-muted-foreground">
                            {query
                                ? 'No results found.'
                                : 'Type an invoice, cheque number, amount, date, or page name.'}
                        </p>
                    ) : (
                        groupedResults.map(([group, groupResults]) => (
                            <div key={group} className="mb-2">
                                <p className="px-3 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    {group}
                                </p>
                                <ul>
                                    {groupResults.map((result) => {
                                        flatIndex += 1;
                                        const currentIndex = flatIndex;
                                        const Icon = resultIcon(result);
                                        const isActive = currentIndex === activeIndex;

                                        return (
                                            <li key={result.id}>
                                                <button
                                                    type="button"
                                                    onMouseEnter={() =>
                                                        setActiveIndex(currentIndex)
                                                    }
                                                    onClick={() =>
                                                        navigateTo(result.href)
                                                    }
                                                    className={cn(
                                                        'flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left transition-colors',
                                                        isActive
                                                            ? 'bg-accent text-accent-foreground'
                                                            : 'hover:bg-accent/60',
                                                    )}
                                                >
                                                    <Icon className="size-4 shrink-0 text-muted-foreground" />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate font-medium">
                                                            {result.title}
                                                        </span>
                                                        {result.subtitle && (
                                                            <span className="block truncate text-sm text-muted-foreground">
                                                                {result.subtitle}
                                                            </span>
                                                        )}
                                                    </span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        ))
                    )}
                </div>

                <div className="flex items-center justify-between border-t px-4 py-2 text-xs text-muted-foreground">
                    <span>Use arrow keys to navigate</span>
                    <span>Enter to open · Esc to close</span>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export function GlobalSearchTrigger({
    className,
}: {
    className?: string;
}) {
    const { setOpen } = useGlobalSearch();

    return (
        <button
            type="button"
            onClick={() => setOpen(true)}
            className={cn(
                'inline-flex h-9 items-center gap-2 rounded-md border border-input bg-background px-3 text-sm text-muted-foreground shadow-xs transition-colors hover:bg-accent hover:text-accent-foreground',
                className,
            )}
        >
            <Search className="size-4" />
            <span className="hidden sm:inline">Search</span>
            <kbd className="hidden rounded border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground md:inline">
                Ctrl+K
            </kbd>
        </button>
    );
}
