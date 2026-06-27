export type DashboardOverview = {
    clients: number;
    branches: number;
    branch_entries: number;
    received_entries: number;
    annexure_entries: number;
    branch_total: string;
    received_total: string;
    annexure_total: string;
    statement_months: number;
    invoice_scans: number;
};

export type DashboardReconciliation = {
    invoices: number;
    matched_count: number;
    complete_count: number;
    mismatch_count: number;
    incomplete_count: number;
    branch_total: string;
    received_total: string;
    annexure_total: string;
};

export type DashboardClientSummary = {
    id: number;
    name: string;
    branches_count: number;
    branch_entries: number;
    received_entries: number;
    annexure_entries: number;
    branch_total: string;
    branch_total_value: number;
    cross_check_invoices: number;
    matched_count: number;
    mismatch_count: number;
    incomplete_count: number;
    statement_months: number;
    last_upload_at: string | null;
};

export type DashboardRecentUpload = {
    client_id: number | null | undefined;
    client_name: string;
    branch_id: number | null | undefined;
    branch_code: string;
    branch_name: string;
    entries_count: number;
    uploaded_at: string | null;
};

export type DashboardPayload = {
    overview: DashboardOverview;
    reconciliation: DashboardReconciliation;
    clients: DashboardClientSummary[];
    recent_uploads: DashboardRecentUpload[];
};
