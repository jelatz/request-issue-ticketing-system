<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useFeedback } from '../composables/useFeedback';

defineProps({
    title: {
        type: String,
        default: 'Sign In',
    },
});

const page = usePage();
const { toasts, notifySuccess, notifyError, dismissToast, markFlashProcessed } = useFeedback();

const flashSuccess = computed(() => page.props.flash?.success || null);
const flashError = computed(() => page.props.flash?.error || null);
const pageErrors = computed(() => page.props.errors || {});
const validationErrorList = computed(() =>
    Object.values(pageErrors.value).filter((v) => typeof v === 'string' && v.length > 0)
);
const firstValidationError = computed(() => validationErrorList.value[0] || null);
const validationErrorCount = computed(() => validationErrorList.value.length);

let removeHttpExceptionListener = null;
let removeNetworkErrorListener = null;

watch(
    () => [
        page.props.flash?.id,
        page.props.flash?.success,
        page.props.flash?.error,
        page.props.errors,
    ],
    () => {
        const flashId = page.props.flash?.id || null;
        if (!flashId || markFlashProcessed(flashId)) {
            if (flashSuccess.value) {
                notifySuccess(flashSuccess.value);
            }
            if (flashError.value) {
                notifyError(flashError.value);
            } else if (firstValidationError.value) {
                notifyError(
                    validationErrorCount.value > 1
                        ? `Submission failed: ${firstValidationError.value} (+${validationErrorCount.value - 1} more)`
                        : `Submission failed: ${firstValidationError.value}`
                );
            }
        }
    },
    { immediate: true, deep: true }
);

onMounted(() => {
    removeHttpExceptionListener = router.on('httpException', (event) => {
        event.preventDefault();
        const status = event.detail?.response?.status;
        if (status === 403) {
            notifyError('You do not have permission to perform this action.');
        } else if (status === 419) {
            notifyError('Your session expired. Please refresh the page and try again.');
        } else if (status === 413) {
            notifyError('The uploaded files exceed the maximum allowed size.');
        } else {
            notifyError(
                `The server returned an unexpected error response${status ? ` (HTTP ${status})` : ''}. Please try again.`
            );
        }
    });

    removeNetworkErrorListener = router.on('networkError', (event) => {
        event.preventDefault();
        notifyError('Network or server error occurred while submitting. Please try again.');
    });
});

onUnmounted(() => {
    removeHttpExceptionListener?.();
    removeNetworkErrorListener?.();
});
</script>

<template>
    <div class="min-h-screen bg-[#F8FAFC] flex flex-col justify-between">
        <main class="flex-1 flex flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8">
            <div class="w-full max-w-sm space-y-6">
                <div class="text-center space-y-1">
                    <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                        CallTek Desk
                    </h1>
                    <p class="text-xs text-slate-500">
                        Internal Service &amp; Engineering Portal
                    </p>
                </div>

                <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-slate-200">
                        <h2 class="text-sm font-semibold text-slate-900">
                            {{ title }}
                        </h2>
                    </div>

                    <div class="p-5">
                        <slot />
                    </div>
                </div>
            </div>
        </main>

        <footer class="py-4 text-center text-[11px] text-slate-400">
            CallTek Desk
        </footer>

        <Teleport to="body">
            <div
                aria-live="polite"
                class="fixed top-4 right-4 z-[70] flex flex-col gap-2 w-full max-w-sm pointer-events-none px-4 sm:px-0"
            >
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    role="status"
                    class="pointer-events-auto rounded-lg border px-3.5 py-3 shadow-lg text-xs flex items-start justify-between gap-3"
                    :class="
                        toast.type === 'success'
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-950'
                            : 'border-rose-200 bg-rose-50 text-rose-950'
                    "
                >
                    <div class="flex items-start gap-2.5 min-w-0">
                        <span
                            class="w-2 h-2 rounded-full mt-1 shrink-0"
                            :class="toast.type === 'success' ? 'bg-emerald-500' : 'bg-rose-500'"
                        />
                        <div class="min-w-0 leading-relaxed">
                            <span class="font-semibold mr-1">
                                {{ toast.type === 'success' ? 'Success' : 'Failed' }}:
                            </span>
                            <span class="break-words">{{ toast.message }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="text-xs font-medium shrink-0"
                        :class="
                            toast.type === 'success'
                                ? 'text-emerald-700 hover:text-emerald-950'
                                : 'text-rose-700 hover:text-rose-950'
                        "
                        @click="dismissToast(toast.id)"
                    >
                        Dismiss
                    </button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
