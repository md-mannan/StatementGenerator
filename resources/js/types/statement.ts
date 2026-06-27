export type Client = {
    id: number;
    name: string;
    branches_count?: number;
    branches?: Branch[];
    created_at: string;
    updated_at: string;
};

export type Branch = {
    id: number;
    client_id: number;
    code: string;
    name: string;
    created_at: string;
    updated_at: string;
    statement_entries_count?: number;
    total_amount?: string;
    total_amount_value?: number;
    last_uploaded_at?: string | null;
    has_statements?: boolean;
};

export type BranchesClientSummary = {
    context: 'branches';
    branches: number;
    branch_months: number;
    entries: number;
    total_amount: string;
};

export type GenerateStatementClientSummary = {
    context: 'generate_statement';
    branches: number;
    branches_with_data: number;
    statement_months: number;
    total_amount: string;
};

export type ReceivedStatementsClientSummary = {
    context: 'received_statements';
    period_label: string;
    entries: number;
    client_total: string;
    branch_total: string;
    difference_total: string;
    unresolved_count: number;
    mismatch_count: number;
    saved_months?: number;
};

export type AnnexureClientSummary = {
    context: 'annexure';
    period_label: string;
    entries: number;
    client_total: string;
    check_total: string;
    rebate: string;
    net_amount: string;
    saved_months?: number;
};

export type CrossCheckClientSummary = {
    context: 'cross_check';
    entries: number;
    branch_total: string;
    received_total: string;
    annexure_total: string;
    matched_count: number;
    complete_count: number;
    mismatch_count: number;
    incomplete_count: number;
    statement_months: number;
    branches: number;
};

export type InvoiceClientSummary = {
    context: 'invoice';
    invoice_no: string;
    status: CrossCheckRow['status'];
    branch_amount: string;
    received_amount: string;
    annexure_amount: string;
    cheque_number?: string | null;
    cheque_period?: string | null;
};

export type CrossCheckRow = {
    key: string;
    statement_year: number;
    statement_month: number;
    statement_period: string;
    branch_id: number | null;
    branch_code: string | null;
    invoice_no: string;
    invoice_date: string | null;
    branch_amount: string | null;
    branch_amount_value: number | null;
    received_amount: string | null;
    received_amount_value: number | null;
    annexure_amount: string | null;
    annexure_amount_value: number | null;
    cheque_number: string | null;
    cheque_period: string | null;
    has_branch: boolean;
    has_received: boolean;
    has_annexure: boolean;
    missing_sources: Array<'branch' | 'received' | 'annexure'>;
    status: 'matched' | 'complete' | 'mismatch' | 'incomplete';
    has_amount_mismatch?: boolean;
    cheque_issued?: boolean;
    invoice_date_differs_from_period?: boolean;
};

export type StatementViewClientSummary = {
    context: 'statement_view';
    period_label: string;
    entries: number;
    branch_total: string;
    client_total: string;
    difference_total: string;
    unresolved_count: number;
    mismatch_count: number;
};

export type ClientSummary =
    | BranchesClientSummary
    | GenerateStatementClientSummary
    | ReceivedStatementsClientSummary
    | AnnexureClientSummary
    | CrossCheckClientSummary
    | InvoiceClientSummary
    | StatementViewClientSummary;

export type BranchMonthStat = {
    branch_id: number;
    year: number;
    month: number;
    label: string;
    entries_count: number;
    total_amount: string;
    total_amount_value?: number;
    last_uploaded_at?: string | null;
};

export type BranchOption = {
    id: number;
    code: string;
    name: string;
};

export type AnnexureChequeSummary = {
    id: number;
    year: number;
    month: number;
    period_label: string;
    cheque_date: string;
    check_number: string;
    amount: string;
    amount_value: number;
    client_total: string;
    client_total_value: number;
    rebate: string;
    rebate_value: number;
    net_amount: string;
    net_amount_value: number;
    entries_count: number;
    payment_saved: boolean;
    review_completed: boolean;
};

export type ClientAnnexureSummary = {
    clientTotal: string;
    branchTotal: string;
    differenceTotal: string;
    rebate: string;
    totalDeducted: string;
    netCollected: string;
    checkTotal: string;
};

export type CombinedStatementEntry = {
    id: number;
    branch_id: number;
    branch_code: string;
    branch_name: string;
    transaction_date: string;
    invoice_no: string;
    amount: string;
    amount_value?: number;
    statement_period?: string | null;
    client_amount: string | null;
    client_amount_value?: number | null;
    difference_amount: string | null;
    difference_amount_value?: number | null;
    is_resolved: boolean;
    has_difference: boolean;
    invoice_date_differs_from_period?: boolean;
};

export type BranchStatementTotal = {
    branch_id: number;
    code: string;
    name: string;
    entries_count: number;
    total: string;
};

export type IncomingStatementEntry = {
    id: number;
    branch_id: number | null;
    branch_code: string | null;
    branch_name?: string | null;
    transaction_date: string;
    invoice_no: string;
    amount: string;
    amount_value?: number;
    branch_amount: string | null;
    branch_amount_value?: number | null;
    difference_amount: string | null;
    difference_amount_value?: number | null;
    is_resolved: boolean;
    has_difference: boolean;
    no_branch_expected?: boolean;
    suggested_branch_id?: number | null;
    statement_period?: string | null;
    invoice_date_differs_from_period?: boolean;
};

export type StatementEntry = {
    id: number;
    branch_id: number;
    branch_code?: string | null;
    branch_name?: string | null;
    transaction_date: string;
    invoice_no: string;
    amount: string;
    amount_value?: number;
    statement_period?: string | null;
    cheque_number?: string | null;
    cheque_received_amount?: string | null;
    cheque_received_amount_value?: number | null;
    client_statement_amount?: string | null;
    client_statement_amount_value?: number | null;
    client_difference_amount?: string | null;
    client_difference_amount_value?: number | null;
    has_client_difference?: boolean;
    difference_amount?: string | null;
    difference_amount_value?: number | null;
    has_difference?: boolean;
    is_resolved?: boolean;
    no_bill_expected?: boolean;
    has_invoice_scan?: boolean;
    invoice_scan_url?: string | null;
    invoice_scan_extension?: string | null;
    invoice_date_differs_from_period?: boolean;
};

export type StatementMonth = {
    year: number;
    month: number;
    label: string;
};

export type InvoiceSourceEntry = {
    id: number;
    source: 'branch' | 'received' | 'annexure';
    branch_id: number | null;
    branch_code: string | null;
    branch_name: string | null;
    transaction_date: string;
    invoice_no: string;
    amount: string;
    amount_value: number;
    statement_period?: string | null;
    cheque_id?: number | null;
    cheque_number?: string | null;
    cheque_period?: string | null;
    payment_saved?: boolean;
    source_url: string;
};

export type InvoiceDetail = {
    invoice_no: string;
    invoice_date: string | null;
    statement_period: string;
    branch_id: number | null;
    branch_code: string | null;
    status: CrossCheckRow['status'];
    missing_sources: CrossCheckRow['missing_sources'];
    has_amount_mismatch: boolean;
    cheque_issued: boolean;
    invoice_date_differs_from_period: boolean;
    branch_amount: string | null;
    branch_amount_value: number | null;
    received_amount: string | null;
    received_amount_value: number | null;
    annexure_amount: string | null;
    annexure_amount_value: number | null;
    cheque_number: string | null;
    cheque_period: string | null;
    branch_entries: Omit<InvoiceSourceEntry, 'source'>[];
    received_entries: Omit<InvoiceSourceEntry, 'source'>[];
    annexure_entries: Omit<InvoiceSourceEntry, 'source'>[];
};
