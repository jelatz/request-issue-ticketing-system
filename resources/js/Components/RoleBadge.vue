<script setup>
import { computed } from 'vue';

const props = defineProps({
    role: {
        type: String,
        required: true,
    },
    dark: {
        type: Boolean,
        default: false,
    },
});

const badgeClass = computed(() => {
    if (props.dark) {
        const darkMap = {
            superadmin: 'bg-blue-500/20 text-blue-200 border-blue-400/30',
            engineer: 'bg-slate-700/80 text-slate-200 border-slate-600',
            user: 'bg-slate-800 text-slate-300 border-slate-700',
        };
        return darkMap[props.role] || 'bg-slate-800 text-slate-300 border-slate-700';
    }

    const lightMap = {
        superadmin: 'bg-blue-50/80 text-blue-700 border-blue-200/80',
        engineer: 'bg-slate-100 text-slate-700 border-slate-200',
        user: 'bg-white text-slate-600 border-slate-200',
    };

    return lightMap[props.role] || 'bg-white text-slate-600 border-slate-200';
});

const label = computed(() => {
    const map = {
        superadmin: 'Superadmin',
        engineer: 'Engineer',
        user: 'Requester',
    };

    return map[props.role] || props.role;
});
</script>

<template>
    <span
        class="inline-flex items-center text-[11px] font-medium px-2 py-0.5 rounded border whitespace-nowrap"
        :class="badgeClass"
    >
        {{ label }}
    </span>
</template>
