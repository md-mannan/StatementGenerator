import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    patchUrlQuery,
    readReconciliationQuery,
} from '@/lib/reconciliation-url';

export function useSearchFromUrl(): [string, (value: string) => void] {
    const pageUrl = usePage().url;
    const [search, setSearchState] = useState(
        () => readReconciliationQuery(pageUrl).search ?? '',
    );
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        setSearchState(readReconciliationQuery(pageUrl).search ?? '');
    }, [pageUrl]);

    const setSearch = useCallback((value: string) => {
        setSearchState(value);

        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        debounceRef.current = setTimeout(() => {
            const trimmed = value.trim();

            patchUrlQuery({
                search: trimmed || undefined,
            });
        }, 350);
    }, []);

    useEffect(
        () => () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        },
        [],
    );

    return [search, setSearch];
}
