<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    links: {
        type: Array,
        required: true,
    },
    from: {
        type: [Number, String],
        default: null,
    },
    to: {
        type: [Number, String],
        default: null,
    },
    total: {
        type: [Number, String],
        default: null,
    },
});

const hasRange = computed(() => {
    return props.from !== null && props.to !== null && props.total !== null;
});

const activeLink = computed(() => {
    return (props.links || []).find((link) => link.active) || null;
});

function formatLabel(rawLabel) {
    if (!rawLabel) {
        return '';
    }

    return String(rawLabel)
        .replace(/&laquo;\s*/gi, '')
        .replace(/\s*&raquo;/gi, '')
        .replace(/<[^>]+>/g, '')
        .trim();
}
</script>

<template>
    <nav
        v-if="links && links.length > 3"
        aria-label="Pagination"
        class="bg-slate-50/60 border-t border-slate-200 px-4 py-2.5 flex flex-wrap items-center justify-between gap-3 text-xs"
    >
        <div class="text-slate-500">
            <template v-if="hasRange">
                Showing
                <span class="font-medium text-slate-700 tabular-nums">{{ from }}–{{ to }}</span>
                of
                <span class="font-medium text-slate-700 tabular-nums">{{ total }}</span>
            </template>
            <template v-else-if="activeLink">
                Page
                <span class="font-medium text-slate-700 tabular-nums">{{ formatLabel(activeLink.label) }}</span>
            </template>
        </div>

        <div class="flex flex-wrap items-center gap-1">
            <template v-for="(link, index) in links" :key="index">
                <span
                    v-if="!link.url"
                    class="px-2.5 py-1 text-xs font-medium rounded border border-slate-200 bg-slate-50 text-slate-400 cursor-not-allowed select-none tabular-nums"
                >
                    {{ formatLabel(link.label) }}
                </span>

                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    class="px-2.5 py-1 text-xs font-medium rounded border transition-colors tabular-nums"
                    :class="
                        link.active
                            ? 'bg-blue-600 text-white border-blue-600'
                            : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50 hover:border-slate-300'
                    "
                >
                    {{ formatLabel(link.label) }}
                </Link>
            </template>
        </div>
    </nav>
</template>
