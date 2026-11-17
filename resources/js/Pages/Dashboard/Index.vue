<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import PriorityBadge from '../../Components/PriorityBadge.vue';
import TypeBadge from '../../Components/TypeBadge.vue';
import { useNewTicketModal } from '../../composables/useNewTicketModal';
import { formatDate, formatDateTime, isTicketOverdue } from '../../utils/formatters';

const props = defineProps({
    metrics: {
        type: Object,
        required: true,
    },
    recent_tickets: {
        type: Array,
        required: true,
    },
    attention_tickets: {
        type: Array,
        required: true,
    },
    recent_activities: {
        type: Array,
        required: true,
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || null);
const { openNewTicketModal } = useNewTicketModal();

const resolvedClosedCount = computed(
    () => (props.metrics.resolved_count || 0) + (props.metrics.closed_count || 0)
);

const headerContext = computed(() => {
    const base = user.value?.is_staff
        ? 'Operations & engineering queue'
        : 'Your submitted requests & issues';

    return user.value?.department ? `${base} · ${user.value.department}` : base;
});

function visitTicket(ticketId) {
    router.visit(`/tickets/${ticketId}`);
}
</script>

<template>
    <Head title="Overview" />

    <AppLayout title="Overview">
        <div class="space-y-5">
            <!-- Compact Page Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                        Overview
                    </h1>
                    <p class="text-xs text-slate-500 mt-0.5 truncate">
                        {{ headerContext }}
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

            <!-- Unified Horizontal 5-Cell Summary Bar -->
            <div class="bg-white border border-slate-200 rounded-lg grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 divide-y sm:divide-y-0 sm:divide-x divide-slate-200 overflow-hidden">
                <div class="px-4 py-3.5">
                    <div class="text-xs font-medium text-slate-500">
                        Open
                    </div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">
                        {{ metrics.open_count }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500 flex items-center gap-1.5 tabular-nums">
                        <span
                            v-if="metrics.critical_high_active_count > 0"
                            class="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0"
                        />
                        <span>
                            {{
                                metrics.critical_high_active_count > 0
                                    ? `${metrics.critical_high_active_count} critical / high active`
                                    : 'No critical / high active'
                            }}
                        </span>
                    </div>
                </div>

                <div class="px-4 py-3.5">
                    <div class="text-xs font-medium text-slate-500">
                        Under review
                    </div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">
                        {{ metrics.under_review_count }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500 tabular-nums">
                        {{ metrics.in_progress_count }} in progress
                    </div>
                </div>

                <div class="px-4 py-3.5">
                    <div class="text-xs font-medium text-slate-500">
                        Waiting on user
                    </div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">
                        {{ metrics.waiting_on_user_count }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500">
                        Awaiting requester reply
                    </div>
                </div>

                <div class="px-4 py-3.5">
                    <div class="text-xs font-medium text-slate-500">
                        Overdue SLA
                    </div>
                    <div
                        class="mt-1 text-2xl font-semibold tabular-nums"
                        :class="metrics.overdue_count > 0 ? 'text-rose-600' : 'text-slate-900'"
                    >
                        {{ metrics.overdue_count }}
                    </div>
                    <div class="mt-1 text-xs flex items-center gap-1.5" :class="metrics.overdue_count > 0 ? 'text-rose-600' : 'text-slate-500'">
                        <span
                            v-if="metrics.overdue_count > 0"
                            class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0"
                        />
                        <span>{{ metrics.overdue_count > 0 ? 'Requires escalation' : 'All within target' }}</span>
                    </div>
                </div>

                <div class="px-4 py-3.5 col-span-2 sm:col-span-1">
                    <div class="flex items-center justify-between gap-2 text-xs font-medium text-slate-500">
                        <span>Resolved &amp; closed</span>
                        <span class="text-[11px] text-slate-400 tabular-nums">{{ metrics.total_visible }} total</span>
                    </div>
                    <div class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">
                        {{ resolvedClosedCount }}
                    </div>
                    <div class="mt-1 text-xs text-slate-500 tabular-nums">
                        {{ metrics.issues_count }} issues · {{ metrics.engineering_requests_count }} requests
                    </div>
                </div>
            </div>

            <!-- Two-Column Operational Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
                <!-- Left: Requires Attention -->
                <section class="lg:col-span-7 bg-white border border-slate-200 rounded-lg overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-semibold text-slate-900">
                                Requires attention
                            </h2>
                            <span class="px-1.5 py-0.5 text-[11px] font-medium rounded bg-slate-100 text-slate-700 tabular-nums">
                                {{ attention_tickets.length }}
                            </span>
                        </div>
                        <Link
                            href="/tickets"
                            class="text-xs font-medium text-blue-600 hover:text-blue-700 shrink-0"
                        >
                            View queue
                        </Link>
                    </div>

                    <div v-if="attention_tickets.length === 0" class="px-4 py-10 text-center text-xs text-slate-500">
                        No tickets currently require attention.
                    </div>

                    <div v-else class="divide-y divide-slate-200">
                        <div
                            v-for="ticket in attention_tickets"
                            :key="ticket.id"
                            class="px-4 py-3 hover:bg-slate-50/80 transition-colors cursor-pointer"
                            @click="visitTicket(ticket.id)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1 space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Link
                                            :href="`/tickets/${ticket.id}`"
                                            class="font-mono text-xs font-medium text-blue-600 hover:text-blue-700"
                                            @click.stop
                                        >
                                            {{ ticket.ticket_number }}
                                        </Link>
                                        <PriorityBadge :priority="ticket.priority" compact />
                                        <span
                                            v-if="ticket.internal_system"
                                            class="text-[11px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 border border-slate-200"
                                        >
                                            {{ ticket.internal_system.name }}
                                        </span>
                                    </div>

                                    <Link
                                        :href="`/tickets/${ticket.id}`"
                                        class="block text-sm font-medium text-slate-900 hover:text-blue-600 truncate"
                                        @click.stop
                                    >
                                        {{ ticket.title }}
                                    </Link>

                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                        <span>{{ ticket.lead_engineer?.name || 'Unassigned' }}</span>
                                        <span
                                            v-if="ticket.target_due_date"
                                            class="tabular-nums"
                                            :class="
                                                isTicketOverdue(ticket.target_due_date, ticket.status)
                                                    ? 'text-rose-600 font-medium'
                                                    : 'text-slate-500'
                                            "
                                        >
                                            Due {{ formatDate(ticket.target_due_date) }}
                                        </span>
                                    </div>
                                </div>

                                <div class="shrink-0 pt-0.5">
                                    <StatusBadge :status="ticket.status" />
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Right: Recent Activity -->
                <section class="lg:col-span-5 bg-white border border-slate-200 rounded-lg overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-slate-900">
                            Recent activity
                        </h2>
                    </div>

                    <div v-if="recent_activities.length === 0" class="px-4 py-10 text-center text-xs text-slate-500">
                        No recent ticket activity recorded.
                    </div>

                    <div v-else class="divide-y divide-slate-100 max-h-[26rem] overflow-y-auto">
                        <div
                            v-for="activity in recent_activities"
                            :key="activity.id"
                            class="px-4 py-2.5 hover:bg-slate-50/70 transition-colors"
                        >
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <span class="font-medium text-slate-900 truncate">
                                    {{ activity.user?.name || 'System' }}
                                </span>
                                <Link
                                    v-if="activity.ticket"
                                    :href="`/tickets/${activity.ticket.id}`"
                                    class="font-mono text-[11px] font-medium text-blue-600 hover:text-blue-700 shrink-0"
                                >
                                    {{ activity.ticket.ticket_number }}
                                </Link>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-600 leading-snug">
                                {{ activity.description }}
                            </p>
                            <div class="mt-1 font-mono text-[11px] text-slate-400 tabular-nums">
                                {{ formatDateTime(activity.created_at) }}
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Bottom Full-Width: Recent Tickets Table -->
            <section class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-slate-900">
                        Recent tickets
                    </h2>
                    <Link
                        href="/tickets"
                        class="text-xs font-medium text-blue-600 hover:text-blue-700 shrink-0"
                    >
                        View all tickets
                    </Link>
                </div>

                <div v-if="recent_tickets.length === 0" class="px-4 py-10 text-center text-xs text-slate-500">
                    No tickets have been submitted yet.
                </div>

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
                                    Lead Engineer
                                </th>
                                <th class="px-4 py-2.5 text-xs font-medium text-slate-500 text-right">
                                    Created
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            <tr
                                v-for="ticket in recent_tickets"
                                :key="ticket.id"
                                class="hover:bg-slate-50/80 transition-colors cursor-pointer"
                                @click="visitTicket(ticket.id)"
                            >
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <Link
                                        :href="`/tickets/${ticket.id}`"
                                        class="font-mono text-xs font-medium text-blue-600 hover:text-blue-700"
                                        @click.stop
                                    >
                                        {{ ticket.ticket_number }}
                                    </Link>
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <TypeBadge :type="ticket.type" />
                                </td>
                                <td class="px-4 py-2.5 min-w-[240px] max-w-md">
                                    <Link
                                        :href="`/tickets/${ticket.id}`"
                                        class="text-sm font-medium text-slate-900 hover:text-blue-600 block truncate"
                                        @click.stop
                                    >
                                        {{ ticket.title }}
                                    </Link>
                                    <div v-if="ticket.internal_system" class="text-xs text-slate-500 mt-0.5 truncate">{{ ticket.internal_system.name }}</div>
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <StatusBadge :status="ticket.status" />
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <PriorityBadge :priority="ticket.priority" />
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-xs text-slate-600">
                                    {{ ticket.lead_engineer?.name || 'Unassigned' }}
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right text-xs text-slate-500 tabular-nums">
                                    {{ formatDate(ticket.created_at) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
