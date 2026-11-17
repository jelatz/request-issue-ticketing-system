export const STATUS_LABELS = {
    open: 'Open',
    under_review: 'Under Review',
    in_progress: 'In Progress',
    waiting_on_user: 'Waiting on User',
    resolved: 'Resolved',
    closed: 'Closed',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

export const PRIORITY_LABELS = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};

export const TYPE_LABELS = {
    issue: 'Reported Issue',
    engineering_request: 'Engineering Request',
};

export const ENVIRONMENT_LABELS = {
    production: 'Production',
    staging: 'Staging',
    internal_network: 'Internal Network',
    other: 'Other',
};

export const IMPACT_SCOPE_LABELS = {
    single_user: 'Single User',
    department: 'Department',
    company_wide: 'Company-Wide',
};

export const ACTIVE_STATUSES = [
    'open',
    'under_review',
    'in_progress',
    'waiting_on_user',
];

export function formatDateTime(value, fallback = 'Not set') {
    if (!value) {
        return fallback;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

export function formatDate(value, fallback = 'Not set') {
    if (!value) {
        return fallback;
    }

    const normalized = String(value).slice(0, 10);
    const parts = normalized.split('-');
    if (parts.length === 3) {
        const year = Number(parts[0]);
        const month = Number(parts[1]) - 1;
        const day = Number(parts[2]);
        const localDate = new Date(year, month, day);
        if (!Number.isNaN(localDate.getTime())) {
            return new Intl.DateTimeFormat('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
            }).format(localDate);
        }
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    }).format(date);
}

export function toDateInputValue(value) {
    if (!value) {
        return '';
    }
    return String(value).slice(0, 10);
}

export function isTicketOverdue(targetDueDate, status) {
    if (!targetDueDate || !ACTIVE_STATUSES.includes(status)) {
        return false;
    }

    const dueStr = String(targetDueDate).slice(0, 10);
    const now = new Date();
    const todayStr = [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
    ].join('-');

    return dueStr < todayStr;
}

export function formatFileSize(bytes) {
    const size = Number(bytes || 0);
    if (size < 1024) {
        return `${size} B`;
    }
    if (size < 1024 * 1024) {
        return `${(size / 1024).toFixed(1)} KB`;
    }
    return `${(size / (1024 * 1024)).toFixed(2)} MB`;
}
