import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import {
    patchUrlQuery,
    readReconciliationQuery,
} from '@/lib/reconciliation-url';

export function useFilterFromUrl<T extends string>(
    validValues: readonly T[],
    defaultValue: T = 'all' as T,
): [T, (value: T) => void] {
    const pageUrl = usePage().url;

    const readFilter = useCallback((): T => {
        const filter = readReconciliationQuery(pageUrl).filter;

        if (filter && validValues.includes(filter as T)) {
            return filter as T;
        }

        return defaultValue;
    }, [defaultValue, pageUrl, validValues]);

    const [filter, setFilterState] = useState<T>(readFilter);

    useEffect(() => {
        setFilterState(readFilter());
    }, [readFilter]);

    const setFilter = useCallback(
        (value: T) => {
            setFilterState(value);

            patchUrlQuery({
                filter: value === defaultValue ? undefined : value,
            });
        },
        [defaultValue],
    );

    return [filter, setFilter];
}

export function useStatusFiltersFromUrl<T extends string>(
    validValues: readonly T[],
): [T[], (values: T[]) => void] {
    const pageUrl = usePage().url;

    const readStatuses = useCallback((): T[] => {
        const statuses = readReconciliationQuery(pageUrl).status ?? [];

        return statuses.filter((value): value is T =>
            validValues.includes(value as T),
        );
    }, [pageUrl, validValues]);

    const [statusFilters, setStatusFiltersState] = useState<T[]>(readStatuses);

    useEffect(() => {
        setStatusFiltersState(readStatuses());
    }, [readStatuses]);

    const setStatusFilters = useCallback((values: T[]) => {
        setStatusFiltersState(values);

        patchUrlQuery({
            status: values.length > 0 ? values : undefined,
        });
    }, []);

    return [statusFilters, setStatusFilters];
}

export { buildClientReconciliationUrl } from '@/lib/reconciliation-url';
