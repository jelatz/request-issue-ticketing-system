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
    bordered: {
        type: Boolean,
        default: true,
    },
});

const ROLE_STYLES = {
    dark: {
        superadmin: {
            surface: 'bg-blue-500/20 text-blue-200',
            border: 'border-blue-400/30',
            plainText: 'text-blue-200',
        },
        engineer: {
            surface: 'bg-slate-700/80 text-slate-200',
            border: 'border-slate-600',
            plainText: 'text-slate-200',
        },
        user: {
            surface: 'bg-slate-800 text-slate-300',
            border: 'border-slate-700',
            plainText: 'text-slate-300',
        },
    },
    light: {
        superadmin: {
            surface: 'bg-blue-50/80 text-blue-700',
            border: 'border-blue-200/80',
            plainText: 'text-blue-700',
        },
        engineer: {
            surface: 'bg-slate-100 text-slate-700',
            border: 'border-slate-200',
            plainText: 'text-slate-700',
        },
        user: {
            surface: 'bg-white text-slate-600',
            border: 'border-slate-200',
            plainText: 'text-slate-600',
        },
    },
};

const badgeClass = computed(() => {
    const palette = props.dark ? ROLE_STYLES.dark : ROLE_STYLES.light;
    const style = palette[props.role] || palette.user;

    if (!props.bordered) {
        return `text-xs ${style.plainText}`;
    }

    return `text-[11px] px-2 py-0.5 rounded border ${style.surface} ${style.border}`;
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
        class="inline-flex items-center font-medium whitespace-nowrap"
        :class="badgeClass"
    >
        {{ label }}
    </span>
</template>
