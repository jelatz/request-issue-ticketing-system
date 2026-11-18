<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import PriorityBadge from '../../Components/PriorityBadge.vue';
import TypeBadge from '../../Components/TypeBadge.vue';
import TicketDetailWorkspace from '../../Components/TicketDetailWorkspace.vue';
import { formatDate, isTicketOverdue } from '../../utils/formatters';

const props = defineProps({
    ticket: {
        type: Object,
        required: true,
    },
    engineers: {
        type: Array,
        default: () => [],
    },
    permissions: {
        type: Object,
        required: true,
    },
});

const workspaceRef = ref(null);

const overdue = computed(() =>
    isTicketOverdue(props.ticket.target_due_date, props.ticket.status)
);
</script>

<template>
    <Head :title="`${ticket.ticket_number} - ${ticket.title}`" />

    <AppLayout :title="`Ticket ${ticket.ticket_number}`">
        <div>
            <!-- Compact Sticky Header Bar -->
            <div class="sticky top-12 z-10 bg-white border-b border-slate-200 -mx-4 sm:-mx-6 lg:-mx-8 -mt-6 px-4 sm:px-6 lg:px-8 py-3.5 mb-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <nav aria-label="Breadcrumb" class="flex items-center gap-1.5 text-xs text-slate-500">
                        <Link href="/tickets" class="hover:text-slate-900 transition-colors">
                            Tickets
                        </Link>
                        <span>/</span>
                        <span class="font-mono font-medium text-slate-900">{{ ticket.ticket_number }}</span>
                    </nav>

                    <div class="flex items-center gap-2">
                        <button
                            v-if="permissions.can_cancel && !['cancelled', 'closed'].includes(ticket.status)"
                            type="button"
                            :disabled="workspaceRef?.requesterActionProcessing"
                            class="px-2.5 py-1 text-xs font-medium text-rose-700 bg-white hover:bg-rose-50 border border-slate-200 hover:border-rose-200 rounded-md disabled:opacity-60 transition-colors"
                            @click="workspaceRef?.cancelTicket()"
                        >
                            Cancel ticket
                        </button>
                        <Link
                            href="/tickets"
                            class="px-2.5 py-1 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                        >
                            Back to queue
                        </Link>
                    </div>
                </div>

                <div class="mt-2 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2.5">
                    <h1 class="text-lg font-semibold text-slate-900 tracking-tight">
                        {{ ticket.title }}
                    </h1>

                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <StatusBadge :status="ticket.status" />
                        <PriorityBadge :priority="ticket.priority" />
                        <TypeBadge :type="ticket.type" />
                        <span
                            v-if="ticket.target_due_date"
                            class="inline-flex items-center gap-1.5 text-xs tabular-nums px-2 py-0.5 rounded-md border"
                            :class="
                                overdue
                                    ? 'bg-rose-50/70 border-rose-200 text-rose-700 font-medium'
                                    : 'bg-slate-50 border-slate-200 text-slate-600'
                            "
                        >
                            <span
                                v-if="overdue"
                                class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0"
                            />
                            <span>{{ overdue ? 'Overdue' : 'Due' }} {{ formatDate(ticket.target_due_date) }}</span>
                        </span>
                    </div>
                </div>
            </div>

            <TicketDetailWorkspace
                ref="workspaceRef"
                :ticket="ticket"
                :engineers="engineers"
                :permissions="permissions"
            />
        </div>
    </AppLayout>
</template>
