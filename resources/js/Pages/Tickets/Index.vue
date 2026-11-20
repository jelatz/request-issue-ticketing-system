<script setup>
import { computed, onBeforeUnmount, reactive, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import PriorityBadge from '../../Components/PriorityBadge.vue';
import TypeBadge from '../../Components/TypeBadge.vue';
import Pagination from '../../Components/Pagination.vue';
import { useNewTicketModal } from '../../composables/useNewTicketModal';
import { formatDate, isTicketOverdue } from '../../utils/formatters';

const props = defineProps({
    tickets: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    systems: {
        type: Array,
        default: () => [],
    },
    engineers: {
        type: Array,
        default: () => [],
    },
    statusCounts: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || null);
const { openNewTicketModal } = useNewTicketModal();

const filterForm = reactive({
    search: props.filters.search || '',
    type: props.filters.type || '',
    status: props.filters.status || '',
    priority: props.filters.priority || '',
    internal_system_id: props.filters.internal_system_id ? String(props.filters.internal_system_id) : '',
    lead_engineer_id: props.filters.lead_engineer_id ? String(props.filters.lead_engineer_id) : '',
    scope: props.filters.scope || '',
});

watch(
    () => props.filters,
    (nextFilters) => {
        const next = nextFilters || {};
        filterForm.search = next.search || '';
        filterForm.type = next.type || '';
        filterForm.status = next.status || '';
        filterForm.priority = next.priority || '';
        filterForm.internal_system_id = next.internal_system_id ? String(next.internal_system_id) : '';
        filterForm.lead_engineer_id = next.lead_engineer_id ? String(next.lead_engineer_id) : '';
        filterForm.scope = next.scope || '';
    },
    { deep: true }
);

const statusTabs = [
    { key: '', label: 'All', countKey: 'all' },
    { key: 'pending_approval', label: 'Pending Approval', countKey: 'pending_approval' },
    { key: 'open', label: 'Open', countKey: 'open' },
    { key: 'under_review', label: 'Under Review', countKey: 'under_review' },
    { key: 'in_progress', label: 'In Progress', countKey: 'in_progress' },
    { key: 'waiting_on_user', label: 'Waiting on User', countKey: 'waiting_on_user' },
    { key: 'resolved', label: 'Resolved', countKey: 'resolved' },
    { key: 'closed', label: 'Closed', countKey: 'closed' },
    { key: 'rejected', label: 'Rejected', countKey: 'rejected' },
    { key: 'cancelled', label: 'Cancelled', countKey: 'cancelled' },
];

const hasActiveFilters = computed(() => {
    return Boolean(
        filterForm.search ||
            filterForm.type ||
            filterForm.status ||
            filterForm.priority ||
            filterForm.internal_system_id ||
            filterForm.lead_engineer_id ||
            filterForm.scope
    );
});

let searchTimer = null;

function clearSearchTimer() {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }
}

onBeforeUnmount(() => {
    clearSearchTimer();
});

function buildQueryParams() {
    const params = {};
    for (const [key, value] of Object.entries(filterForm)) {
        const normalized = typeof value === 'string' ? value.trim() : value;
        if (normalized !== '' && normalized !== null && normalized !== undefined) {
            params[key] = normalized;
        }
    }
    return params;
}

function applyFilters() {
    clearSearchTimer();
    router.get('/tickets', buildQueryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function onSearchInput() {
    clearSearchTimer();
    searchTimer = setTimeout(() => {
        applyFilters();
    }, 300);
}

function onSearchEscape() {
    clearSearchTimer();
    if (filterForm.search !== '') {
        filterForm.search = '';
        applyFilters();
    }
}

function selectStatusTab(statusKey) {
    filterForm.status = statusKey;
    applyFilters();
}

function resetFilters() {
    clearSearchTimer();
    filterForm.search = '';
    filterForm.type = '';
    filterForm.status = '';
    filterForm.priority = '';
    filterForm.internal_system_id = '';
    filterForm.lead_engineer_id = '';
    filterForm.scope = '';

    router.get('/tickets', {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function visitTicket(ticketId) {
    router.visit(`/tickets/${ticketId}`);
}

function collaboratorTooltip(ticket) {
    if (!ticket.collaborators || ticket.collaborators.length === 0) {
        return '';
    }
    return `Collaborators: ${ticket.collaborators.map((c) => c.name).join(', ')}`;
}
</script>

<template>
    <Head title="Tickets" />

    <AppLayout title="Tickets">
        <div class="space-y-4">
            <!-- Compact Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                        Tickets
                    </h1>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Search, triage, and monitor reported system issues and engineering requests.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <Link
                        v-if="user?.is_staff"
                        href="/tickets/kanban"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 hover:border-slate-300 rounded-md transition-colors"
                    >
                        Board View
                    </Link>
                    <button
                        type="button"
                        class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors"
                        @click="openNewTicketModal"
                    >
                        + New Ticket
                    </button>
                </div>
            </div>

            <!-- Single Unified White Sheet Container -->
            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                <!-- Status Tabs -->
                <div class="border-b border-slate-200 px-4 overflow-x-auto">
                    <div role="tablist" aria-label="Filter tickets by status" class="flex items-center gap-5 min-w-max">
                        <button
                            v-for="tab in statusTabs"
                            :key="tab.countKey"
                            type="button"
                            role="tab"
                            :aria-selected="(filterForm.status || '') === tab.key"
                            class="py-2.5 text-xs font-medium border-b-2 -mb-px transition-colors inline-flex items-center gap-1.5 whitespace-nowrap"
                            :class="
                                (filterForm.status || '') === tab.key
                                    ? 'border-blue-600 text-slate-900'
                                    : 'border-transparent text-slate-500 hover:text-slate-800'
                            "
                            @click="selectStatusTab(tab.key)"
                        >
                            <span>{{ tab.label }}</span>
                            <span
                                class="px-1.5 py-0.5 text-[11px] rounded tabular-nums"
                                :class="
                                    (filterForm.status || '') === tab.key
                                        ? 'bg-blue-50 text-blue-700'
                                        : 'bg-slate-100 text-slate-600'
                                "
                            >
                                {{ statusCounts[tab.countKey] ?? 0 }}
                            </span>
                        </button>
                    </div>
                </div>

                <!-- Compact Inline Filter Strip -->
                <form
                    class="bg-slate-50/60 border-b border-slate-200 px-4 py-2.5"
                    @submit.prevent="applyFilters"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <label for="filter-search" class="sr-only">Search tickets</label>
                        <input
                            id="filter-search"
                            v-model="filterForm.search"
                            type="text"
                            maxlength="255"
                            aria-label="Search tickets"
                            placeholder="Search key, title, or keyword..."
                            class="w-full sm:w-56 lg:w-64 px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-md text-slate-800 placeholder:text-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            @input="onSearchInput"
                            @keydown.escape="onSearchEscape"
                        />

                        <label for="filter-type" class="sr-only">Filter by ticket type</label>
                        <select
                            id="filter-type"
                            v-model="filterForm.type"
                            aria-label="Filter by ticket type"
                            class="px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-md text-slate-700 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            @change="applyFilters"
                        >
                            <option value="">All types</option>
                            <option value="issue">Reported Issue</option>
                            <option value="engineering_request">Engineering Request</option>
                        </select>

                        <label for="filter-priority" class="sr-only">Filter by priority</label>
                        <select
                            id="filter-priority"
                            v-model="filterForm.priority"
                            aria-label="Filter by priority"
                            class="px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-md text-slate-700 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            @change="applyFilters"
                        >
                            <option value="">All priorities</option>
                            <option value="critical">Critical</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>

                        <label for="filter-system" class="sr-only">Filter by internal system</label>
                        <select
                            id="filter-system"
                            v-model="filterForm.internal_system_id"
                            aria-label="Filter by internal system"
                            class="px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-md text-slate-700 max-w-[13rem] focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            @change="applyFilters"
                        >
                            <option value="">All systems</option>
                            <option v-for="sys in systems" :key="sys.id" :value="String(sys.id)">
                                {{ sys.name }}
                            </option>
                        </select>

                        <label v-if="user?.is_super_admin" for="filter-lead" class="sr-only">Filter by lead engineer</label>
                        <select
                            v-if="user?.is_super_admin"
                            id="filter-lead"
                            v-model="filterForm.lead_engineer_id"
                            aria-label="Filter by lead engineer"
                            class="px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-md text-slate-700 max-w-[12rem] focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            @change="applyFilters"
                        >
                            <option value="">All engineers</option>
                            <option value="unassigned">Unassigned</option>
                            <option v-for="eng in engineers" :key="eng.id" :value="String(eng.id)">
                                {{ eng.name }}
                            </option>
                        </select>

                        <label for="filter-scope" class="sr-only">Filter by scope</label>
                        <select
                            id="filter-scope"
                            v-model="filterForm.scope"
                            aria-label="Filter by scope"
                            class="px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-md text-slate-700 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            @change="applyFilters"
                        >
                            <option value="">All visible</option>
                            <option v-if="!user?.is_engineer" value="unassigned">Unassigned</option>
                            <option value="led_by_me">Led by me</option>
                            <option value="collaborating">Collaborating</option>
                            <option v-if="!user?.is_engineer" value="my_submitted">Submitted by me</option>
                            <option value="overdue">Overdue</option>
                        </select>

                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            class="ml-auto px-2 py-1 text-xs font-medium text-slate-500 hover:text-slate-900 transition-colors"
                            @click="resetFilters"
                        >
                            Reset
                        </button>
                    </div>
                </form>

                <!-- Empty State -->
                <div v-if="tickets.data.length === 0" class="px-4 py-12 text-center space-y-2">
                    <div class="text-sm font-medium text-slate-800">
                        No tickets match the selected filters.
                    </div>
                    <p class="text-xs text-slate-500">
                        Adjust your search terms or reset filters to view all accessible tickets.
                    </p>
                    <div v-if="hasActiveFilters" class="pt-1">
                        <button
                            type="button"
                            class="text-xs font-medium text-blue-600 hover:text-blue-700"
                            @click="resetFilters"
                        >
                            Reset filters
                        </button>
                    </div>
                </div>

                <!-- High-Density Table -->
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50/60 text-left">
                            <tr>
                                <th class="px-4 py-2.5 text-xs font-medium text-slate-500">
                                    Key
                                </th>
                                <th class="px-4 py-2.5 text-xs font-medium text-slate-500">
                                    Type
                                </th>
                                <th class="px-4 py-2.5 text-xs font-medium text-slate-500">
                                    Summary &amp; System
                                </th>
                                <th class="px-4 py-2.5 text-xs font-medium text-slate-500">
                                    Status
                                </th>
                                <th class="px-4 py-2.5 text-xs font-medium text-slate-500">
                                    Priority
                                </th>
                                <th class="px-4 py-2.5 text-xs font-medium text-slate-500">
                                    Assignee
                                </th>
                                <th class="px-4 py-2.5 text-xs font-medium text-slate-500">
                                    Due date
                                </th>
                                <th class="px-4 py-2.5 text-xs font-medium text-slate-500 text-right">
                                    Updated
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            <tr
                                v-for="ticket in tickets.data"
                                :key="ticket.id"
                                class="hover:bg-slate-50/80 transition-colors cursor-pointer"
                                @click="visitTicket(ticket.id)"
                            >
                                <td class="px-4 py-3 whitespace-nowrap align-top">
                                    <Link
                                        :href="`/tickets/${ticket.id}`"
                                        class="font-mono text-xs font-medium text-blue-600 hover:text-blue-700"
                                        @click.stop
                                    >
                                        {{ ticket.ticket_number }}
                                    </Link>
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap align-top">
                                    <TypeBadge :type="ticket.type" />
                                </td>

                                <td class="px-4 py-3 min-w-[240px] max-w-md align-top">
                                    <Link
                                        :href="`/tickets/${ticket.id}`"
                                        class="text-sm font-medium text-slate-900 hover:text-blue-600 block truncate"
                                        @click.stop
                                    >
                                        {{ ticket.title }}
                                    </Link>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                        <span v-if="ticket.internal_system">{{ ticket.internal_system.name }}</span>
                                        <span v-else class="text-slate-400">General / New System</span>
                                        <span v-if="ticket.requester?.name" class="text-slate-400">
                                            · {{ ticket.requester.name }}
                                        </span>
                                    </div>
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap align-top">
                                    <StatusBadge :status="ticket.status" />
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap align-top">
                                    <PriorityBadge :priority="ticket.priority" />
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap align-top">
                                    <div class="inline-flex items-center gap-1.5 text-xs text-slate-700">
                                        <span :class="ticket.lead_engineer ? 'font-medium text-slate-800' : 'text-slate-400'">
                                            {{ ticket.lead_engineer?.name || 'Unassigned' }}
                                        </span>
                                        <span
                                            v-if="ticket.collaborators && ticket.collaborators.length > 0"
                                            :title="collaboratorTooltip(ticket)"
                                            class="px-1.5 py-0.5 text-[11px] font-medium rounded bg-slate-100 text-slate-600 border border-slate-200 tabular-nums"
                                        >
                                            +{{ ticket.collaborators.length }}
                                        </span>
                                    </div>
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap align-top">
                                    <span
                                        v-if="ticket.target_due_date"
                                        class="text-xs tabular-nums"
                                        :class="
                                            isTicketOverdue(ticket.target_due_date, ticket.status)
                                                ? 'text-rose-600 font-medium'
                                                : 'text-slate-600'
                                        "
                                    >
                                        {{ formatDate(ticket.target_due_date) }}
                                    </span>
                                    <span v-else class="text-xs text-slate-400">
                                        —
                                    </span>
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap text-right align-top text-xs text-slate-500 tabular-nums">
                                    {{ formatDate(ticket.updated_at || ticket.created_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination
                    :links="tickets.links"
                    :from="tickets.from"
                    :to="tickets.to"
                    :total="tickets.total"
                />
            </div>
        </div>
    </AppLayout>
</template>
