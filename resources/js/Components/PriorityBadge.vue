<script setup>
import { computed } from 'vue';
import { PRIORITY_LABELS } from '../utils/formatters';

const props = defineProps({
    priority: {
        type: String,
        required: true,
    },
    compact: {
        type: Boolean,
        default: false,
    },
});

const textClass = computed(() => {
    const map = {
        critical: 'text-rose-700 font-medium',
        high: 'text-amber-700 font-medium',
        medium: 'text-slate-600 font-medium',
        low: 'text-slate-500',
    };

    return map[props.priority] || 'text-slate-600';
});

const dotClass = computed(() => {
    const map = {
        critical: 'bg-rose-500',
        high: 'bg-amber-500',
        medium: 'bg-slate-500',
        low: 'bg-slate-300',
    };

    return map[props.priority] || 'bg-slate-400';
});

const label = computed(() => PRIORITY_LABELS[props.priority] || props.priority);
</script>

<template>
    <span
        class="inline-flex items-center whitespace-nowrap"
        :class="[
            compact ? 'gap-1 text-[11px]' : 'gap-1.5 text-xs',
            textClass,
        ]"
    >
        <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="dotClass" />
        <span>{{ label }}</span>
    </span>
</template>
