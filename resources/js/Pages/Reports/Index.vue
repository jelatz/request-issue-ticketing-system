<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import PriorityBadge from '../../Components/PriorityBadge.vue';
import { PRIORITY_LABELS, STATUS_LABELS } from '../../utils/formatters';

const props = defineProps({
    summary: {
        type: Object,
        required: true,
    },
    by_system: {
        type: Array,
        default: () => [],
    },
    by_status: {
        type: Object,
        default: () => ({}),
    },
    by_priority: {
        type: Object,
        default: () => ({}),
    },
    by_engineer: {
        type: Array,
        default: () => [],
    },
    by_department: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    systems: {
        type: Array,
        default: () => [],
    },
});

const filterForm = reactive({
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
    type: props.filters.type || '',
    internal_system_id: props.filters.internal_system_id ? String(props.filters.internal_system_id) : '',
});

watch(
    () => props.filters,
    (next) => {
        filterForm.date_from = next?.date_from || '';
        filterForm.date_to = next?.date_to || '';
        filterForm.type = next?.type || '';
        filterForm.internal_system_id = next?.internal_system_id ? String(next.internal_system_id) : '';
    },
    { deep: true }
);

function buildFilterParams(source) {
    const params = {};
    if (source.date_from) {
        params.date_from = source.date_from;
    }
    if (source.date_to) {
        params.date_to = source.date_to;
    }
    if (source.type) {
        params.type = source.type;
    }
    if (source.internal_system_id) {
        params.internal_system_id = source.internal_system_id;
    }
    return params;
}

function applyFilters() {
    router.get('/reports', buildFilterParams(filterForm), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function resetFilters() {
    filterForm.date_from = '';
    filterForm.date_to = '';
    filterForm.type = '';
    filterForm.internal_system_id = '';

    router.get('/reports', {}, {
        preserveState: true,
        replace: true,
    });
}

function exportUrl(section) {
    const params = new URLSearchParams(buildFilterParams(props.filters || {}));
    params.set('section', section);
    return `/reports/export?${params.toString()}`;
}

const statusBarColors = {
    pending_approval: 'bg-indigo-500',
    open: 'bg-slate-400',
    under_review: 'bg-blue-500',
    in_progress: 'bg-blue-600',
    waiting_on_user: 'bg-amber-500',
    resolved: 'bg-emerald-500',
    closed: 'bg-slate-400',
    rejected: 'bg-rose-500',
    cancelled: 'bg-slate-300',
};

const priorityBarColors = {
    critical: 'bg-rose-500',
    high: 'bg-amber-500',
    medium: 'bg-slate-500',
    low: 'bg-slate-300',
};

const statusEntries = computed(() => {
    const total = props.summary?.total_tickets || 0;
    return Object.entries(props.by_status || {}).map(([key, count]) => ({
        key,
        label: STATUS_LABELS[key] || key,
        count: Number(count || 0),
        percent: total > 0 ? Math.round((Number(count || 0) / total) * 1000) / 10 : 0,
        barClass: statusBarColors[key] || 'bg-slate-400',
    }));
});

const priorityEntries = computed(() => {
    const total = props.summary?.total_tickets || 0;
    return Object.entries(props.by_priority || {}).map(([key, count]) => ({
        key,
        label: PRIORITY_LABELS[key] || key,
        count: Number(count || 0),
        percent: total > 0 ? Math.round((Number(count || 0) / total) * 1000) / 10 : 0,
        barClass: priorityBarColors[key] || 'bg-slate-400',
    }));
});

function systemResolutionPercent(row) {
    if (!row.total) {
        return 0;
    }
    return Math.round((row.resolved_closed / row.total) * 1000) / 10;
}
</script>

<template>
    <Head title="Reports & metrics" />

    <AppLayout title="Reports & metrics">
        <div class="space-y-5">
            <!-- Compact Header + Export Toolbar -->
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                        Reports &amp; metrics
                    </h1>
                    <p class="text-sm text-slate-500 mt-0.5">
                        Ticket volume, SLA turnaround, and system workload
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <a
                        :href="exportUrl('tickets')"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                    >
                        Export Tickets CSV
                    </a>
                    <a
                        :href="exportUrl('systems')"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                    >
                        Systems CSV
                    </a>
                    <a
                        :href="exportUrl('engineers')"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                    >
                        Engineers CSV
                    </a>
                    <a
                        :href="exportUrl('departments')"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                    >
                        Departments CSV
                    </a>
                </div>
            </div>

            <!-- Compact Filter Strip -->
            <form
                class="bg-white border border-slate-200 rounded-lg px-4 py-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end"
                @submit.prevent="applyFilters"
            >
                <div>
                    <label for="report-date-from" class="block text-xs font-medium text-slate-600 mb-1">
                        Date from
                    </label>
                    <input
                        id="report-date-from"
                        v-model="filterForm.date_from"
                        type="date"
                        class="w-full rounded-md border border-slate-200 px-2.5 py-1.5 text-xs text-slate-900 focus:outline-none focus:border-blue-500"
                    />
                </div>

                <div>
                    <label for="report-date-to" class="block text-xs font-medium text-slate-600 mb-1">
                        Date to
                    </label>
                    <input
                        id="report-date-to"
                        v-model="filterForm.date_to"
                        type="date"
                        class="w-full rounded-md border border-slate-200 px-2.5 py-1.5 text-xs text-slate-900 focus:outline-none focus:border-blue-500"
                    />
                </div>

                <div>
                    <label for="report-type" class="block text-xs font-medium text-slate-600 mb-1">
                        Ticket type
                    </label>
                    <select
                        id="report-type"
                        v-model="filterForm.type"
                        class="w-full rounded-md border border-slate-200 px-2.5 py-1.5 text-xs text-slate-900 bg-white focus:outline-none focus:border-blue-500"
                    >
                        <option value="">All types</option>
                        <option value="issue">Reported Issue</option>
                        <option value="engineering_request">Engineering Request</option>
                    </select>
                </div>

                <div>
                    <label for="report-system" class="block text-xs font-medium text-slate-600 mb-1">
                        Internal system
                    </label>
                    <select
                        id="report-system"
                        v-model="filterForm.internal_system_id"
                        class="w-full rounded-md border border-slate-200 px-2.5 py-1.5 text-xs text-slate-900 bg-white focus:outline-none focus:border-blue-500"
                    >
                        <option value="">All systems</option>
                        <option
                            v-for="system in systems"
                            :key="system.id"
                            :value="String(system.id)"
                        >
                            {{ system.name }}
                        </option>
                    </select>
                </div>

                <div class="sm:col-span-2 flex items-center gap-2">
                    <button
                        type="submit"
                        class="px-3.5 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors"
                    >
                        Apply
                    </button>
                    <button
                        type="button"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                        @click="resetFilters"
                    >
                        Reset
                    </button>
                </div>
            </form>

            <!-- Unified 5-Cell Horizontal Summary Bar -->
            <div class="bg-white border border-slate-200 rounded-lg grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 divide-y sm:divide-y-0 sm:divide-x divide-slate-200">
                <div class="p-4">
                    <div class="text-xs font-medium text-slate-500">
                        Total tickets
                    </div>
                    <div class="mt-1.5 text-2xl font-semibold text-slate-900 tabular-nums">
                        {{ summary.total_tickets }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500 tabular-nums">
                        {{ summary.issues_count }} issues · {{ summary.engineering_requests_count }} requests
                    </div>
                </div>

                <div class="p-4">
                    <div class="text-xs font-medium text-slate-500">
                        Resolution rate
                    </div>
                    <div class="mt-1.5 text-2xl font-semibold text-slate-900 tabular-nums">
                        {{ summary.resolution_rate_percent }}%
                    </div>
                    <div class="mt-1 text-xs text-slate-500 tabular-nums">
                        {{ summary.resolved_closed_count }} resolved/closed
                    </div>
                </div>

                <div class="p-4">
                    <div class="text-xs font-medium text-slate-500">
                        Active backlog
                    </div>
                    <div class="mt-1.5 text-2xl font-semibold text-slate-900 tabular-nums">
                        {{ summary.open_active_count }}
                    </div>
                    <div
                        class="mt-1 text-xs tabular-nums"
                        :class="summary.overdue_count > 0 ? 'text-rose-600 font-medium' : 'text-slate-500'"
                    >
                        {{ summary.overdue_count }} overdue
                    </div>
                </div>

                <div class="p-4">
                    <div class="text-xs font-medium text-slate-500">
                        Avg first response
                    </div>
                    <div class="mt-1.5 text-2xl font-semibold text-slate-900 tabular-nums">
                        {{ summary.avg_first_response_hours != null ? `${summary.avg_first_response_hours}h` : '—' }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500">
                        Initial staff reply
                    </div>
                </div>

                <div class="p-4 col-span-2 sm:col-span-1">
                    <div class="text-xs font-medium text-slate-500">
                        Avg resolution time
                    </div>
                    <div class="mt-1.5 text-2xl font-semibold text-slate-900 tabular-nums">
                        {{ summary.avg_resolution_hours != null ? `${summary.avg_resolution_hours}h` : '—' }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500">
                        Mean cycle time
                    </div>
                </div>
            </div>

            <!-- Status & Priority Distribution -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <!-- By status -->
                <div class="bg-white border border-slate-200 rounded-lg p-5">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-3 mb-4">
                        <h2 class="text-sm font-semibold text-slate-900">
                            By status
                        </h2>
                        <span class="text-xs font-mono text-slate-500 tabular-nums">
                            {{ summary.total_tickets }} total
                        </span>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-for="item in statusEntries"
                            :key="item.key"
                            class="space-y-1.5"
                        >
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <StatusBadge :status="item.key" />
                                <span class="font-medium text-slate-700 tabular-nums">
                                    {{ item.count }} <span class="text-slate-400">({{ item.percent }}%)</span>
                                </span>
                            </div>
                            <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all duration-300"
                                    :class="item.barClass"
                                    :style="{ width: `${item.percent}%` }"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- By priority -->
                <div class="bg-white border border-slate-200 rounded-lg p-5">
                    <div class="flex items-center justify-between border-b border-slate-200 pb-3 mb-4">
                        <h2 class="text-sm font-semibold text-slate-900">
                            By priority
                        </h2>
                        <span class="text-xs font-mono text-slate-500 tabular-nums">
                            {{ summary.total_tickets }} total
                        </span>
                    </div>

                    <div class="space-y-3.5">
                        <div
                            v-for="item in priorityEntries"
                            :key="item.key"
                            class="space-y-1.5"
                        >
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <PriorityBadge :priority="item.key" />
                                <span class="font-medium text-slate-700 tabular-nums">
                                    {{ item.count }} <span class="text-slate-400">({{ item.percent }}%)</span>
                                </span>
                            </div>
                            <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all duration-300"
                                    :class="item.barClass"
                                    :style="{ width: `${item.percent}%` }"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Internal systems -->
            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">
                        Internal systems
                    </h2>
                    <span class="text-xs font-mono text-slate-500 tabular-nums">
                        {{ by_system.length }} {{ by_system.length === 1 ? 'system' : 'systems' }}
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50/80 text-xs font-medium text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5 text-left">System name</th>
                                <th class="px-4 py-2.5 text-right">Total tickets</th>
                                <th class="px-4 py-2.5 text-right">Issues</th>
                                <th class="px-4 py-2.5 text-right">Requests</th>
                                <th class="px-4 py-2.5 text-right">Active</th>
                                <th class="px-4 py-2.5 text-right">Resolved / closed</th>
                                <th class="px-4 py-2.5 text-right">Critical</th>
                                <th class="px-4 py-2.5 text-left min-w-[140px]">Resolution %</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white text-[13px]">
                            <tr v-if="by_system.length === 0">
                                <td colspan="8" class="px-4 py-8 text-center text-xs text-slate-500">
                                    No system breakdown data matches the current filters.
                                </td>
                            </tr>
                            <tr
                                v-for="row in by_system"
                                :key="row.system_name"
                                class="hover:bg-slate-50/70"
                            >
                                <td class="px-4 py-2.5 font-medium text-slate-900">
                                    {{ row.system_name }}
                                </td>
                                <td class="px-4 py-2.5 text-right font-medium text-slate-900 tabular-nums">
                                    {{ row.total }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-slate-600 tabular-nums">
                                    {{ row.issues }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-slate-600 tabular-nums">
                                    {{ row.requests }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-amber-700 font-medium tabular-nums">
                                    {{ row.active }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-emerald-700 font-medium tabular-nums">
                                    {{ row.resolved_closed }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">
                                    <span
                                        class="px-1.5 py-0.5 text-xs font-medium rounded"
                                        :class="row.critical > 0 ? 'bg-rose-50 text-rose-700 border border-rose-200/80' : 'text-slate-400'"
                                    >
                                        {{ row.critical }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                            <div
                                                class="h-full bg-emerald-500 rounded-full"
                                                :style="{ width: `${systemResolutionPercent(row)}%` }"
                                            />
                                        </div>
                                        <span class="text-xs font-medium text-slate-600 tabular-nums w-11 text-right">
                                            {{ systemResolutionPercent(row) }}%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Engineer workload & SLA -->
            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">
                        Engineer workload &amp; SLA
                    </h2>
                    <span class="text-xs font-mono text-slate-500 tabular-nums">
                        {{ by_engineer.length }} {{ by_engineer.length === 1 ? 'engineer' : 'engineers' }}
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50/80 text-xs font-medium text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Engineer</th>
                                <th class="px-4 py-2.5 text-left">Department</th>
                                <th class="px-4 py-2.5 text-right">Lead tickets</th>
                                <th class="px-4 py-2.5 text-right">Collaborating</th>
                                <th class="px-4 py-2.5 text-right">Active led</th>
                                <th class="px-4 py-2.5 text-right">Resolved / closed led</th>
                                <th class="px-4 py-2.5 text-right">Subtasks completed</th>
                                <th class="px-4 py-2.5 text-right">Avg resolution time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white text-[13px]">
                            <tr v-if="by_engineer.length === 0">
                                <td colspan="8" class="px-4 py-8 text-center text-xs text-slate-500">
                                    No engineer workload records found.
                                </td>
                            </tr>
                            <tr
                                v-for="engineer in by_engineer"
                                :key="engineer.id"
                                class="hover:bg-slate-50/70"
                            >
                                <td class="px-4 py-2.5 font-medium text-slate-900 whitespace-nowrap">
                                    {{ engineer.name }}
                                </td>
                                <td class="px-4 py-2.5 text-slate-600">
                                    {{ engineer.department || 'Engineering' }}
                                </td>
                                <td class="px-4 py-2.5 text-right font-medium text-slate-900 tabular-nums">
                                    {{ engineer.led_total }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-slate-600 tabular-nums">
                                    {{ engineer.collaborating_total }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-blue-700 font-medium tabular-nums">
                                    {{ engineer.active_led }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-emerald-700 font-medium tabular-nums">
                                    {{ engineer.resolved_closed_led }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-slate-600 tabular-nums">
                                    {{ engineer.subtasks_completed }}
                                </td>
                                <td class="px-4 py-2.5 text-right font-medium text-slate-800 tabular-nums whitespace-nowrap">
                                    {{ engineer.avg_resolution_hours != null ? `${engineer.avg_resolution_hours}h` : '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Requester departments -->
            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-900">
                        Requester departments
                    </h2>
                    <span class="text-xs font-mono text-slate-500 tabular-nums">
                        {{ by_department.length }} {{ by_department.length === 1 ? 'department' : 'departments' }}
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50/80 text-xs font-medium text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5 text-left">Department</th>
                                <th class="px-4 py-2.5 text-right">Total submitted</th>
                                <th class="px-4 py-2.5 text-right">Issues</th>
                                <th class="px-4 py-2.5 text-right">Requests</th>
                                <th class="px-4 py-2.5 text-right">Resolved / closed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white text-[13px]">
                            <tr v-if="by_department.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-xs text-slate-500">
                                    No department submissions match the current filters.
                                </td>
                            </tr>
                            <tr
                                v-for="dept in by_department"
                                :key="dept.department"
                                class="hover:bg-slate-50/70"
                            >
                                <td class="px-4 py-2.5 font-medium text-slate-900">
                                    {{ dept.department }}
                                </td>
                                <td class="px-4 py-2.5 text-right font-medium text-slate-900 tabular-nums">
                                    {{ dept.total }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-slate-600 tabular-nums">
                                    {{ dept.issues }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-slate-600 tabular-nums">
                                    {{ dept.requests }}
                                </td>
                                <td class="px-4 py-2.5 text-right text-emerald-700 font-medium tabular-nums">
                                    {{ dept.resolved_closed }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
