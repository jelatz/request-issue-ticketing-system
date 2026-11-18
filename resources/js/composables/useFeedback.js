import { ref } from 'vue';

const toasts = ref([]);
const toastTimers = new Map();

let nextToastId = 1;
let lastProcessedFlashId = null;
let lastToastSignature = '';
let lastToastTime = 0;

function addToast(type, message, duration) {
    const normalizedMessage = String(message || '').trim();
    if (!normalizedMessage) {
        return null;
    }

    const signature = `${type}:${normalizedMessage}`;
    const now = Date.now();
    if (signature === lastToastSignature && now - lastToastTime < 150) {
        return null;
    }

    lastToastSignature = signature;
    lastToastTime = now;

    const id = nextToastId++;
    toasts.value = [
        ...toasts.value,
        {
            id,
            type,
            message: normalizedMessage,
        },
    ];

    if (duration > 0) {
        const timer = setTimeout(() => {
            dismissToast(id);
        }, duration);
        toastTimers.set(id, timer);
    }

    return id;
}

function notifySuccess(message) {
    return addToast('success', message, 5000);
}

function notifyError(message) {
    return addToast('error', message, 6000);
}

function dismissToast(id) {
    const timer = toastTimers.get(id);
    if (timer) {
        clearTimeout(timer);
        toastTimers.delete(id);
    }

    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

function clearToasts() {
    for (const timer of toastTimers.values()) {
        clearTimeout(timer);
    }
    toastTimers.clear();
    toasts.value = [];
}

function markFlashProcessed(flashId) {
    if (flashId === null || flashId === undefined || flashId === '') {
        return true;
    }

    if (lastProcessedFlashId === flashId) {
        return false;
    }

    lastProcessedFlashId = flashId;
    return true;
}

export function useFeedback() {
    return {
        toasts,
        notifySuccess,
        notifyError,
        dismissToast,
        clearToasts,
        markFlashProcessed,
    };
}
