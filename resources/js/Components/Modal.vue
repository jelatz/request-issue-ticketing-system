<script setup>
import { onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '',
    },
    maxWidth: {
        type: String,
        default: 'max-w-lg',
    },
});

const emit = defineEmits(['close']);

function close() {
    emit('close');
}

function handleKeydown(event) {
    if (event.key === 'Escape' && props.show) {
        close();
    }
}

watch(
    () => props.show,
    (isOpen) => {
        if (typeof document !== 'undefined') {
            document.body.style.overflow = isOpen ? 'hidden' : '';
        }
    }
);

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
    if (typeof document !== 'undefined') {
        document.body.style.overflow = '';
    }
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 overflow-y-auto"
            role="dialog"
            aria-modal="true"
            aria-labelledby="modal-dialog-title"
        >
            <div
                class="fixed inset-0 bg-slate-900/40 backdrop-blur-[1px]"
                @click="close"
            />

            <div
                class="relative z-10 w-full bg-white border border-slate-200 rounded-lg shadow-lg overflow-hidden"
                :class="maxWidth"
            >
                <div class="flex items-center justify-between px-5 py-3.5 border-b border-slate-200 bg-white">
                    <h3 id="modal-dialog-title" class="text-sm font-semibold text-slate-900">
                        {{ title }}
                    </h3>
                    <button
                        type="button"
                        class="px-2 py-1 text-xs font-medium text-slate-500 hover:text-slate-900 rounded hover:bg-slate-100 transition-colors"
                        @click="close"
                    >
                        Close
                    </button>
                </div>

                <div class="p-5">
                    <slot />
                </div>
            </div>
        </div>
    </Teleport>
</template>
