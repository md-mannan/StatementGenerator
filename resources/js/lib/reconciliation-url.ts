/** Query keys shared across client reconciliation tabs. */
const PRESERVED_KEYS = [
    'periods',
    'branch_ids',
    'search',
    'filter',
] as const;

export type ReconciliationQuery = {
    periods?: string[];
    branch_ids?: string[];
    search?: string;
    filter?: string;
    status?: string[];
};

export function readReconciliationQuery(url: string): ReconciliationQuery {
    const queryIndex = url.indexOf('?');

    if (queryIndex === -1) {
        return {};
    }

    const params = new URLSearchParams(url.slice(queryIndex + 1));
    const result: ReconciliationQuery = {};

    const periods: string[] = [];

    params.forEach((value, key) => {
        if (key === 'periods[]' || key === 'periods') {
            periods.push(value);
        }
    });

    if (periods.length > 0) {
        result.periods = periods;
    }

    const branchIds: string[] = [];

    params.forEach((value, key) => {
        if (key === 'branch_ids[]' || key === 'branch_ids') {
            branchIds.push(value);
        }
    });

    if (branchIds.length > 0) {
        result.branch_ids = branchIds;
    }

    const search = params.get('search');

    if (search) {
        result.search = search;
    }

    const filter = params.get('filter');

    if (filter) {
        result.filter = filter;
    }

    const status: string[] = [];

    params.forEach((value, key) => {
        if (key === 'status[]' || key === 'status') {
            status.push(value);
        }
    });

    if (status.length > 0) {
        result.status = status;
    }

    return result;
}

export function preservedTabQuery(currentUrl: string): ReconciliationQuery {
    const query = readReconciliationQuery(currentUrl);
    const preserved: ReconciliationQuery = {};

    for (const key of PRESERVED_KEYS) {
        if (query[key] !== undefined) {
            preserved[key] = query[key];
        }
    }

    return preserved;
}

export function reconciliationQueryRecord(
    query: ReconciliationQuery,
): Record<string, string | string[]> {
    const record: Record<string, string | string[]> = {};

    if (query.periods?.length) {
        record.periods = query.periods;
    }

    if (query.branch_ids?.length) {
        record.branch_ids = query.branch_ids;
    }

    if (query.search) {
        record.search = query.search;
    }

    if (query.filter) {
        record.filter = query.filter;
    }

    if (query.status?.length) {
        record.status = query.status;
    }

    return record;
}

export function mergeReconciliationQuery(
    currentUrl: string,
    extra: ReconciliationQuery = {},
): Record<string, string | string[]> {
    return reconciliationQueryRecord({
        ...preservedTabQuery(currentUrl),
        ...extra,
    });
}

export function buildClientReconciliationUrl(
    baseUrl: string,
    currentUrl: string,
    extra: ReconciliationQuery = {},
): string {
    const query = mergeReconciliationQuery(currentUrl, extra);
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (Array.isArray(value)) {
            value.forEach((item) => params.append(`${key}[]`, item));
        } else {
            params.set(key, value);
        }
    }

    const queryString = params.toString();

    return queryString ? `${baseUrl}?${queryString}` : baseUrl;
}

/** Read all query params from a URL, normalizing `key[]` to `key`. */
export function readAllQueryParams(url: string): Record<string, string[]> {
    const queryIndex = url.indexOf('?');

    if (queryIndex === -1) {
        return {};
    }

    const params = new URLSearchParams(url.slice(queryIndex + 1));
    const result: Record<string, string[]> = {};

    params.forEach((value, key) => {
        const normalizedKey = key.endsWith('[]') ? key.slice(0, -2) : key;

        result[normalizedKey] ??= [];
        result[normalizedKey].push(value);
    });

    return result;
}

/** Update the browser URL without triggering an Inertia visit. */
export function patchUrlQuery(
    patch: Record<string, string | string[] | undefined>,
): void {
    const existing = readAllQueryParams(window.location.href);
    const merged: Record<string, string[]> = { ...existing };

    for (const [key, value] of Object.entries(patch)) {
        if (value === undefined) {
            delete merged[key];
            continue;
        }

        merged[key] = Array.isArray(value) ? value : [value];
    }

    const params = new URLSearchParams();

    for (const [key, values] of Object.entries(merged)) {
        if (values.length === 1) {
            params.set(key, values[0]);
        } else {
            values.forEach((value) => params.append(`${key}[]`, value));
        }
    }

    const queryString = params.toString();
    const nextUrl = queryString
        ? `${window.location.pathname}?${queryString}`
        : window.location.pathname;

    window.history.replaceState(window.history.state, '', nextUrl);
}
