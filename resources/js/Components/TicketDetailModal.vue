<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import StatusBadge from './StatusBadge.vue';
import PriorityBadge from './PriorityBadge.vue';
import TypeBadge from './TypeBadge.vue';
import TicketDetailWorkspace from './TicketDetailWorkspace.vue';
import { formatDate, isTicketOverdue } from '../utils/formatters';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    ticket: {
        type: Object,
        default: null,
    },
    engineers: {
        type: Array,
        default: () => [],
    },
    permissions: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close']);

const scrollContainerRef = ref(null);
const workspaceRef = ref(null);

const overdue = computed(
    () => Boolean(props.ticket) && isTicketOverdue(props.ticket.target_due_date, props.ticket.status)
);

function close() {
    emit('close');
}

function handleKeydown(event) {
    if (!props.show || event.key !== 'Escape') {
        return;
    }

    event.stopPropagation();
    close();
}

watch(
    () => props.show,
    (isOpen) => {
        document.body.style.overflow = isOpen ? 'hidden' : '';
    },
    { immediate: true }
);

watch(
    () => props.ticket?.id,
    () => {
        if (scrollContainerRef.value) {
            scrollContainerRef.value.scrollTop = 0;
        }
    }
);

onMounted(() => {
    window.addEventListener('keydown', handleKeydown, true);
});

onUnmounted(() => {
    document.body.style.overflow = '';
    window.removeEventListener('keydown', handleKeydown, true);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show && ticket && permissions"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ticket-detail-modal-title"
            class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-6"
        >
            <div
                class="fixed inset-0 bg-slate-900/40 backdrop-blur-[1px]"
                @click="close"
            />

            <div class="relative z-10 w-full max-w-7xl max-h-[calc(100vh-1.5rem)] sm:max-h-[calc(100vh-3rem)] flex flex-col bg-[#F8FAFC] text-slate-900 border border-slate-200 rounded-xl shadow-2xl overflow-hidden">
                <!-- Sticky Top Header -->
                <header class="bg-white border-b border-slate-200 px-4 sm:px-6 py-3.5 shrink-0">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-mono text-slate-500">
                                    {{ ticket.ticket_number }}
                                </span>
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

                            <h2
                                id="ticket-detail-modal-title"
                                class="text-base sm:text-lg font-semibold text-slate-900 tracking-tight truncate"
                            >
                                {{ ticket.title }}
                            </h2>
                        </div>

                        <div class="flex items-center gap-2 shrink-0 self-start lg:self-center">
                            <button
                                v-if="permissions?.can_cancel && !['cancelled', 'closed'].includes(ticket.status)"
                                type="button"
                                :disabled="workspaceRef?.requesterActionProcessing"
                                class="px-3 py-1.5 text-xs font-medium text-rose-700 bg-white hover:bg-rose-50 border border-slate-200 hover:border-rose-200 rounded-md disabled:opacity-60 transition-colors"
                                @click="workspaceRef?.cancelTicket()"
                            >
                                Cancel ticket
                            </button>
                            <button
                                type="button"
                                class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                                @click="close"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </header>

                <!-- Scrollable Body -->
                <div ref="scrollContainerRef" class="flex-1 overflow-y-auto px-4 sm:px-6 py-5">
                    <TicketDetailWorkspace
                        ref="workspaceRef"
                        :ticket="ticket"
                        :engineers="engineers"
                        :permissions="permissions"
                    />
                </div>
            </div>
        </div>
    </Teleport>
</template>
