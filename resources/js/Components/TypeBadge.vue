<script setup>
import { computed } from 'vue';
import { TYPE_LABELS } from '../utils/formatters';

const props = defineProps({
    type: {
        type: String,
        required: true,
    },
});

const badgeClass = computed(() => {
    const map = {
        issue: 'bg-rose-50/70 text-rose-700 border-rose-200/80',
        engineering_request: 'bg-blue-50/70 text-blue-700 border-blue-200/80',
    };

    return map[props.type] || 'bg-slate-50 text-slate-700 border-slate-200';
});

const label = computed(() => TYPE_LABELS[props.type] || props.type);
</script>

<template>
    <span
        class="inline-flex items-center text-[11px] font-medium px-2 py-0.5 rounded border whitespace-nowrap"
        :class="badgeClass"
    >
        {{ label }}
    </span>
</template>
