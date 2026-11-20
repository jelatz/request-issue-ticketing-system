<script setup>
import { computed } from 'vue';
import { STATUS_LABELS } from '../utils/formatters';

const props = defineProps({
    status: {
        type: String,
        required: true,
    },
});

const containerClass = computed(() => {
    const map = {
        pending_approval: 'border-indigo-200 bg-indigo-50/70 text-indigo-800',
        open: 'border-slate-200 bg-white text-slate-700',
        under_review: 'border-blue-200/80 bg-blue-50/50 text-blue-700',
        in_progress: 'border-blue-200 bg-blue-50/70 text-blue-700',
        waiting_on_user: 'border-amber-200/80 bg-amber-50/60 text-amber-800',
        resolved: 'border-emerald-200/80 bg-emerald-50/60 text-emerald-700',
        closed: 'border-slate-200 bg-slate-50 text-slate-600',
        rejected: 'border-rose-200/80 bg-rose-50/60 text-rose-700',
        cancelled: 'border-slate-200 bg-slate-50 text-slate-500',
    };

    return map[props.status] || 'border-slate-200 bg-white text-slate-700';
});

const dotClass = computed(() => {
    const map = {
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

    return map[props.status] || 'bg-slate-400';
});

const label = computed(() => STATUS_LABELS[props.status] || props.status);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md border text-xs font-medium whitespace-nowrap"
        :class="containerClass"
    >
        <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="dotClass" />
        <span>{{ label }}</span>
    </span>
</template>
