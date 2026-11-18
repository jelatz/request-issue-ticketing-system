<script setup>
import { computed, onUnmounted, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import PriorityBadge from '../../Components/PriorityBadge.vue';
import TicketDetailModal from '../../Components/TicketDetailModal.vue';
import { useNewTicketModal } from '../../composables/useNewTicketModal';
import { STATUS_LABELS, formatDate, isTicketOverdue } from '../../utils/formatters';

const props = defineProps({
    columns: {
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
    selectedTicket: {
        type: Object,
        default: null,
    },
    selectedTicketPermissions: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || null);
const { openNewTicketModal } = useNewTicketModal();

const columnDefinitions = [
    { key: 'open', label: 'Open', dotClass: 'bg-slate-400', closedOrRejected: false },
    { key: 'under_review', label: 'Under review', dotClass: 'bg-blue-500', closedOrRejected: false },
    { key: 'in_progress', label: 'In progress', dotClass: 'bg-blue-600', closedOrRejected: false },
    { key: 'waiting_on_user', label: 'Waiting on user', dotClass: 'bg-amber-500', closedOrRejected: false },
    { key: 'resolved', label: 'Resolved', dotClass: 'bg-emerald-500', closedOrRejected: false },
    { key: 'closed', label: 'Closed', dotClass: 'bg-slate-400', closedOrRejected: true },
    { key: 'rejected', label: 'Rejected', dotClass: 'bg-rose-500', closedOrRejected: true },
];

const transitionStatuses = [
    'open',
    'under_review',
    'in_progress',
    'waiting_on_user',
    'resolved',
    'closed',
    'rejected',
    'cancelled',
];

const filterForm = reactive({
    search: props.filters.search || '',
    type: props.filters.type || '',
    priority: props.filters.priority || '',
    internal_system_id: props.filters.internal_system_id ? String(props.filters.internal_system_id) : '',
    lead_engineer_id: props.filters.lead_engineer_id ? String(props.filters.lead_engineer_id) : '',
    scope: props.filters.scope || '',
});

const showClosedRejected = ref(false);
const draggedTicket = ref(null);
const dragOverColumn = ref(null);
const isDragging = ref(false);
let searchDebounceTimer = null;
let dragResetTimer = null;

const visibleColumns = computed(() => {
    return showClosedRejected.value
        ? columnDefinitions
        : columnDefinitions.filter((col) => !col.closedOrRejected);
});

const closedRejectedCount = computed(() => {
    return (props.columns.closed?.length || 0) + (props.columns.rejected?.length || 0);
});

const totalTickets = computed(() => {
    return visibleColumns.value.reduce((total, col) => total + (props.columns[col.key]?.length || 0), 0);
});

const hasActiveFilters = computed(() => {
    return Object.values(filterForm).some((value) => value !== '' && value !== null && value !== undefined);
});

function buildFilterParams() {
    const params = {};
    for (const [key, value] of Object.entries(filterForm)) {
        if (value !== '' && value !== null && value !== undefined) {
            params[key] = value;
        }
    }
    return params;
}

function applyFilters() {
    router.get('/tickets/kanban', buildFilterParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function openTicketModal(ticket) {
    if (isDragging.value) {
        return;
    }

    router.get(
        '/tickets/kanban',
        { ...buildFilterParams(), ticket: ticket.id },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['selectedTicket', 'selectedTicketPermissions'],
        }
    );
}

function closeTicketModal() {
    router.get('/tickets/kanban', buildFilterParams(), {
        preserveState: true,
        preserveScroll: true,
        only: ['selectedTicket', 'selectedTicketPermissions'],
    });
}

function handleSearchInput() {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }
    searchDebounceTimer = setTimeout(() => {
        applyFilters();
    }, 300);
}

function resetFilters() {
    filterForm.search = '';
    filterForm.type = '';
    filterForm.priority = '';
    filterForm.internal_system_id = '';
    filterForm.lead_engineer_id = '';
    filterForm.scope = '';

    router.get('/tickets/kanban', {}, {
        preserveState: true,
        replace: true,
    });
}

function quickTransition(ticket, nextStatus) {
    if (!nextStatus || nextStatus === ticket.status) {
        return;
    }

    router.patch(
        `/tickets/${ticket.id}/workflow`,
        { status: nextStatus },
        {
            preserveScroll: true,
        }
    );
}

function onDragStart(event, ticket) {
    if (dragResetTimer) {
        clearTimeout(dragResetTimer);
        dragResetTimer = null;
    }
    isDragging.value = true;
    draggedTicket.value = ticket;
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(ticket.id));
    }
}

function onDragEnd() {
    draggedTicket.value = null;
    dragOverColumn.value = null;
    if (dragResetTimer) {
        clearTimeout(dragResetTimer);
    }
    dragResetTimer = setTimeout(() => {
        isDragging.value = false;
        dragResetTimer = null;
    }, 0);
}

function onDragOver(colKey) {
    if (!draggedTicket.value) {
        return;
    }
    dragOverColumn.value = colKey;
}

function onDragLeave(event, colKey) {
    if (event.currentTarget && event.relatedTarget && event.currentTarget.contains(event.relatedTarget)) {
        return;
    }
    if (dragOverColumn.value === colKey) {
        dragOverColumn.value = null;
    }
}

function onDrop(targetStatus) {
    const ticket = draggedTicket.value;
    draggedTicket.value = null;
    dragOverColumn.value = null;

    if (!ticket || ticket.status === targetStatus) {
        return;
    }

    quickTransition(ticket, targetStatus);
}

function formatShortName(name) {
    if (!name) {
        return 'Unassigned';
    }
    const parts = String(name).trim().split(/\s+/);
    if (parts.length === 1) {
        return parts[0];
    }
    return `${parts[0]} ${parts[parts.length - 1].charAt(0)}.`;
}

onUnmounted(() => {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }
    if (dragResetTimer) {
        clearTimeout(dragResetTimer);
    }
});
</script>

<template>
    <Head title="Engineering Board" />

    <AppLayout title="Engineering Board">
        <div class="space-y-4">
            <!-- Compact Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-baseline gap-2.5">
                    <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                        Engineering Board
                    </h1>
                    <span class="text-xs font-mono text-slate-500 tabular-nums">
                        {{ totalTickets }} {{ totalTickets === 1 ? 'ticket' : 'tickets' }}
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <Link
                        href="/tickets"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 rounded-md transition-colors"
                    >
                        List view
                    </Link>
                    <button
                        type="button"
                        class="px-3.5 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors"
                        @click="openNewTicketModal"
                    >
                        + New Ticket
                    </button>
                </div>
            </div>

            <!-- Compact Inline Filter Bar -->
            <form
                class="bg-white border border-slate-200 rounded-lg px-3.5 py-2 flex flex-wrap items-center justify-between gap-2"
                @submit.prevent="applyFilters"
            >
                <div class="flex flex-wrap items-center gap-2 flex-1 min-w-0">
                    <input
                        id="kanban-search"
                        v-model="filterForm.search"
                        type="text"
                        placeholder="Search ticket # or title..."
                        aria-label="Search tickets"
                        class="w-44 sm:w-52 px-2.5 py-1 text-xs text-slate-900 placeholder:text-slate-400 border border-slate-200 rounded-md bg-slate-50/50 focus:bg-white focus:outline-none focus:border-blue-500"
                        @input="handleSearchInput"
                    />

                    <select
                        id="kanban-system"
                        v-model="filterForm.internal_system_id"
                        aria-label="Filter by system"
                        class="px-2.5 py-1 text-xs text-slate-700 border border-slate-200 rounded-md bg-white focus:outline-none focus:border-blue-500"
                        @change="applyFilters"
                    >
                        <option value="">All systems</option>
                        <option v-for="sys in systems" :key="sys.id" :value="String(sys.id)">
                            {{ sys.name }}
                        </option>
                    </select>

                    <select
                        v-if="user?.is_super_admin"
                        id="kanban-lead"
                        v-model="filterForm.lead_engineer_id"
                        aria-label="Filter by lead engineer"
                        class="px-2.5 py-1 text-xs text-slate-700 border border-slate-200 rounded-md bg-white focus:outline-none focus:border-blue-500"
                        @change="applyFilters"
                    >
                        <option value="">All engineers</option>
                        <option value="unassigned">Unassigned</option>
                        <option v-for="eng in engineers" :key="eng.id" :value="String(eng.id)">
                            {{ eng.name }}
                        </option>
                    </select>

                    <select
                        id="kanban-type"
                        v-model="filterForm.type"
                        aria-label="Filter by ticket type"
                        class="px-2.5 py-1 text-xs text-slate-700 border border-slate-200 rounded-md bg-white focus:outline-none focus:border-blue-500"
                        @change="applyFilters"
                    >
                        <option value="">All types</option>
                        <option value="issue">Reported Issue</option>
                        <option value="engineering_request">Engineering Request</option>
                    </select>

                    <select
                        id="kanban-priority"
                        v-model="filterForm.priority"
                        aria-label="Filter by priority"
                        class="px-2.5 py-1 text-xs text-slate-700 border border-slate-200 rounded-md bg-white focus:outline-none focus:border-blue-500"
                        @change="applyFilters"
                    >
                        <option value="">All priorities</option>
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>

                    <select
                        id="kanban-scope"
                        v-model="filterForm.scope"
                        aria-label="Filter by scope"
                        class="px-2.5 py-1 text-xs text-slate-700 border border-slate-200 rounded-md bg-white focus:outline-none focus:border-blue-500"
                        @change="applyFilters"
                    >
                        <option value="">All visible</option>
                        <option v-if="user?.is_super_admin" value="unassigned">Unassigned</option>
                        <option value="led_by_me">Led by me</option>
                        <option value="collaborating">Collaborating</option>
                        <option v-if="user?.is_super_admin" value="my_submitted">Submitted by me</option>
                        <option value="overdue">Overdue</option>
                    </select>

                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="px-2 py-1 text-xs font-medium text-slate-500 hover:text-slate-800 transition-colors"
                        @click="resetFilters"
                    >
                        Reset
                    </button>
                </div>

                <label class="inline-flex items-center gap-2 text-xs text-slate-600 cursor-pointer select-none shrink-0">
                    <input
                        v-model="showClosedRejected"
                        type="checkbox"
                        class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                    />
                    <span>Show closed &amp; rejected</span>
                    <span class="font-mono text-[11px] text-slate-500 tabular-nums">
                        ({{ closedRejectedCount }})
                    </span>
                </label>
            </form>

            <!-- Horizontal Scrollable Kanban Board -->
            <div class="overflow-x-auto pb-3">
                <div class="inline-flex items-start gap-3 min-w-full">
                    <div
                        v-for="col in visibleColumns"
                        :key="col.key"
                        class="w-72 shrink-0 flex flex-col bg-slate-100/70 border border-slate-200 rounded-lg max-h-[calc(100vh-14rem)] transition-colors"
                        :class="
                            dragOverColumn === col.key && draggedTicket?.status !== col.key
                                ? 'ring-2 ring-blue-500/40 bg-blue-50/40'
                                : ''
                        "
                        @dragover.prevent="onDragOver(col.key)"
                        @dragleave="onDragLeave($event, col.key)"
                        @drop.prevent="onDrop(col.key)"
                    >
                        <div class="px-3 py-2.5 border-b border-slate-200 bg-white rounded-t-lg flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="col.dotClass" />
                                <h2 class="text-xs font-semibold text-slate-800 truncate">
                                    {{ col.label }}
                                </h2>
                            </div>
                            <span class="px-1.5 py-0.5 rounded border border-slate-200 bg-slate-50 font-mono text-[11px] text-slate-600 tabular-nums">
                                {{ (columns[col.key] || []).length }}
                            </span>
                        </div>

                        <div class="p-2.5 space-y-2 overflow-y-auto flex-1">
                            <div
                                v-if="(columns[col.key] || []).length === 0"
                                class="py-6 px-3 text-center text-xs text-slate-400 border border-dashed border-slate-200 rounded-md bg-white/40"
                            >
                                No tickets
                            </div>

                            <div
                                v-for="ticket in columns[col.key] || []"
                                :key="ticket.id"
                                draggable="true"
                                tabindex="0"
                                class="group bg-white border border-slate-200 rounded-md p-3 space-y-2 hover:border-slate-300 transition-colors cursor-pointer active:cursor-grabbing focus:outline-none focus:ring-2 focus:ring-blue-500/40"
                                :class="draggedTicket?.id === ticket.id ? 'opacity-50' : ''"
                                @click="openTicketModal(ticket)"
                                @keydown.enter.self="openTicketModal(ticket)"
                                @dragstart="onDragStart($event, ticket)"
                                @dragend="onDragEnd"
                            >
                                <!-- Top Row: Ticket Key + Priority -->
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-[11px] text-slate-500 group-hover:text-blue-600 transition-colors">
                                        {{ ticket.ticket_number }}
                                    </span>
                                    <PriorityBadge :priority="ticket.priority" compact />
                                </div>

                                <!-- Title -->
                                <h3 class="block text-[13px] font-medium text-slate-900 leading-snug line-clamp-2 group-hover:text-blue-600 transition-colors">
                                    {{ ticket.title }}
                                </h3>

                                <!-- Compact Metadata Row -->
                                <div class="flex flex-wrap items-center justify-between gap-1.5 pt-0.5 text-[11px] text-slate-500">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <span
                                            v-if="ticket.internal_system"
                                            :title="ticket.internal_system.name"
                                            class="text-[11px] bg-slate-50 text-slate-600 border border-slate-200 px-1.5 py-0.5 rounded truncate max-w-[110px]"
                                        >
                                            {{ ticket.internal_system.name }}
                                        </span>
                                        <span
                                            v-if="ticket.subtasks_count > 0"
                                            class="font-mono text-[11px] text-slate-500 tabular-nums"
                                            title="Completed subtasks"
                                        >
                                            {{ ticket.completed_subtasks_count }}/{{ ticket.subtasks_count }}
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-2 shrink-0">
                                        <span
                                            v-if="ticket.target_due_date"
                                            class="tabular-nums"
                                            :class="
                                                isTicketOverdue(ticket.target_due_date, ticket.status)
                                                    ? 'text-rose-600 font-medium'
                                                    : 'text-slate-500'
                                            "
                                        >
                                            {{ formatDate(ticket.target_due_date) }}
                                        </span>
                                        <span
                                            class="truncate max-w-[90px]"
                                            :class="ticket.lead_engineer ? 'text-slate-600 font-medium' : 'text-slate-400'"
                                            :title="ticket.lead_engineer?.name || 'Unassigned'"
                                        >
                                            {{ formatShortName(ticket.lead_engineer?.name) }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Accessible Compact Status Selector -->
                                <div class="pt-1.5 border-t border-slate-100">
                                    <select
                                        :id="`kanban-move-${ticket.id}`"
                                        aria-label="Move ticket status"
                                        :value="ticket.status"
                                        class="text-[11px] border border-slate-200 rounded bg-slate-50/80 px-1.5 py-0.5 text-slate-600 w-full focus:outline-none focus:border-blue-500"
                                        @click.stop
                                        @mousedown.stop
                                        @change="quickTransition(ticket, $event.target.value)"
                                    >
                                        <option
                                            v-for="statusKey in transitionStatuses"
                                            :key="statusKey"
                                            :value="statusKey"
                                        >
                                            {{ STATUS_LABELS[statusKey] }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <TicketDetailModal
            :show="Boolean(selectedTicket && selectedTicketPermissions)"
            :ticket="selectedTicket"
            :engineers="engineers"
            :permissions="selectedTicketPermissions"
            @close="closeTicketModal"
        />
    </AppLayout>
</template>
