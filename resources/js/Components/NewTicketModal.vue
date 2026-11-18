<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { useFeedback } from '../composables/useFeedback';
import { useNewTicketModal } from '../composables/useNewTicketModal';
import { formatFileSize } from '../utils/formatters';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

const page = usePage();
const { notifyError } = useFeedback();
const { closeNewTicketModal } = useNewTicketModal();
const internalSystems = computed(() => page.props.internalSystems || []);
const scrollContainerRef = ref(null);

const form = useForm({
    type: 'issue',
    internal_system_id: '',
    priority: 'medium',
    title: '',
    description: '',
    environment: 'production',
    impact_scope: 'single_user',
    business_justification: '',
    proposed_functionality: '',
    expected_benefits: '',
    requested_deadline: '',
    attachments: [],
});

const priorityOptions = [
    {
        value: 'low',
        label: 'Low',
        description: 'Minor cosmetic issue, convenience improvement, or non-urgent inquiry.',
    },
    {
        value: 'medium',
        label: 'Medium',
        description: 'Standard issue or feature request with an available workaround.',
    },
    {
        value: 'high',
        label: 'High',
        description: 'Significant workflow degradation or urgent department deliverable.',
    },
    {
        value: 'critical',
        label: 'Critical',
        description: 'Production outage, data integrity risk, or company-wide blocker.',
    },
];

const selectedPriority = computed(
    () => priorityOptions.find((opt) => opt.value === form.priority) || priorityOptions[1]
);

const typeSubtitle = computed(() =>
    form.type === 'issue'
        ? 'Report a bug, system error, outage, or unexpected behavior on an internal system.'
        : 'Propose a new feature, workflow enhancement, or internal system capability.'
);

const extraAttachmentErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('attachments.') && key !== 'attachments.0')
        .map(([, message]) => message)
);

const hasFormErrors = computed(() => Object.keys(form.errors).length > 0);

async function scrollToFirstError() {
    await nextTick();
    const firstErrorEl = scrollContainerRef.value?.querySelector('[data-error="true"]');
    if (firstErrorEl) {
        firstErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
        scrollContainerRef.value?.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function close() {
    closeNewTicketModal();
    emit('close');
}

function handleFileChange(event) {
    const files = Array.from(event.target.files || []);
    if (files.length > 0) {
        form.attachments = [...form.attachments, ...files];
        const attachmentErrorKeys = Object.keys(form.errors).filter(
            (key) => key === 'attachments' || key.startsWith('attachments.')
        );
        if (attachmentErrorKeys.length > 0) {
            form.clearErrors(...attachmentErrorKeys);
        }
    }
    event.target.value = '';
}

function removeAttachment(index) {
    form.attachments = form.attachments.filter((_, idx) => idx !== index);
    const attachmentItemErrorKeys = Object.keys(form.errors).filter((key) =>
        key.startsWith('attachments.')
    );
    if (attachmentItemErrorKeys.length > 0) {
        form.clearErrors(...attachmentItemErrorKeys);
    }
}

function selectType(nextType) {
    form.type = nextType;
    form.clearErrors();
}

function submit() {
    form.clearErrors();

    if (!String(form.title || '').trim()) {
        form.setError('title', 'Please enter a ticket summary / title.');
    }
    if (!String(form.description || '').trim()) {
        form.setError('description', 'Please provide a description.');
    }

    if (form.type === 'issue') {
        if (!form.environment) {
            form.setError('environment', 'Please select an environment.');
        }
        if (!form.impact_scope) {
            form.setError('impact_scope', 'Please select an impact scope.');
        }
    }

    if (form.type === 'engineering_request') {
        if (!String(form.business_justification || '').trim()) {
            form.setError('business_justification', 'Please provide a business justification.');
        }
        if (!String(form.proposed_functionality || '').trim()) {
            form.setError('proposed_functionality', 'Please describe the proposed functionality.');
        }
        if (!String(form.expected_benefits || '').trim()) {
            form.setError('expected_benefits', 'Please describe the expected benefits.');
        }
    }

    if (form.attachments.length === 0) {
        form.setError('attachments', 'Please attach at least one file.');
    }

    if (Object.keys(form.errors).length > 0) {
        const errorMessages = Object.values(form.errors).filter(Boolean);
        notifyError(
            errorMessages.length > 1
                ? `Submission failed: ${errorMessages[0]} (+${errorMessages.length - 1} more)`
                : `Submission failed: ${errorMessages[0]}`
        );
        scrollToFirstError();
        return;
    }

    form.post('/tickets', {
        forceFormData: true,
        onSuccess: (responsePage) => {
            const flashErr = responsePage?.props?.flash?.error;
            if (flashErr) {
                form.setError('submission', flashErr);
                notifyError(flashErr);
                scrollToFirstError();
                return;
            }

            if (typeof document !== 'undefined') {
                document.body.style.overflow = '';
            }
            form.reset();
            form.clearErrors();
            closeNewTicketModal();
            emit('close');
        },
        onError: (errors) => {
            const errorMessages = Object.values(errors || form.errors || {}).filter(Boolean);
            if (errorMessages.length > 0) {
                notifyError(
                    errorMessages.length > 1
                        ? `Submission failed: ${errorMessages[0]} (+${errorMessages.length - 1} more)`
                        : `Submission failed: ${errorMessages[0]}`
                );
            }
            scrollToFirstError();
        },
    });
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

        if (isOpen) {
            form.reset();
            form.clearErrors();
        }
    },
    { immediate: true }
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
            v-if="show"
            role="dialog"
            aria-modal="true"
            aria-labelledby="new-ticket-modal-title"
            class="fixed inset-0 z-50 flex flex-col bg-[#F8FAFC] text-slate-900"
        >
            <!-- Sticky Top Header -->
            <header class="h-14 bg-white border-b border-slate-200 px-4 sm:px-6 lg:px-8 flex items-center justify-between gap-4 shrink-0">
                <div class="min-w-0">
                    <h2
                        id="new-ticket-modal-title"
                        class="text-base font-semibold tracking-tight text-slate-900"
                    >
                        New ticket
                    </h2>
                    <p class="text-xs text-slate-500 truncate">
                        Submit an internal system issue or engineering request.
                    </p>
                </div>

                <button
                    type="button"
                    class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors shrink-0"
                    @click="close"
                >
                    Close
                </button>
            </header>

            <form class="flex-1 flex flex-col min-h-0" novalidate @submit.prevent="submit">
                <!-- Scrollable Body -->
                <div ref="scrollContainerRef" class="flex-1 overflow-y-auto px-4 sm:px-6 lg:px-8 py-6">
                    <div class="max-w-4xl mx-auto">
                        <div
                            v-if="form.errors.submission"
                            role="alert"
                            data-error="true"
                            class="mb-4 flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-medium text-rose-900"
                        >
                            <span class="w-2 h-2 rounded-full bg-rose-500 shrink-0" />
                            <span>{{ form.errors.submission }}</span>
                        </div>

                        <div class="bg-white border border-slate-200 rounded-lg divide-y divide-slate-200 overflow-hidden">
                            <!-- 1. Type Segmented Control -->
                            <section class="p-5 space-y-2.5">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                    <div
                                        role="tablist"
                                        aria-label="Ticket type"
                                        class="inline-flex p-1 bg-slate-100 border border-slate-200 rounded-md self-start"
                                    >
                                        <button
                                            type="button"
                                            role="tab"
                                            :aria-selected="form.type === 'issue'"
                                            class="px-3 py-1.5 text-xs font-medium rounded transition-colors"
                                            :class="
                                                form.type === 'issue'
                                                    ? 'bg-white text-slate-900 shadow-xs border border-slate-200'
                                                    : 'text-slate-600 hover:text-slate-900 border border-transparent'
                                            "
                                            @click="selectType('issue')"
                                        >
                                            Issue
                                        </button>
                                        <button
                                            type="button"
                                            role="tab"
                                            :aria-selected="form.type === 'engineering_request'"
                                            class="px-3 py-1.5 text-xs font-medium rounded transition-colors"
                                            :class="
                                                form.type === 'engineering_request'
                                                    ? 'bg-white text-slate-900 shadow-xs border border-slate-200'
                                                    : 'text-slate-600 hover:text-slate-900 border border-transparent'
                                            "
                                            @click="selectType('engineering_request')"
                                        >
                                            Engineering Request
                                        </button>
                                    </div>
                                </div>
                                <p class="text-xs text-slate-500">
                                    {{ typeSubtitle }}
                                </p>
                                <p v-if="form.errors.type" data-error="true" class="text-xs text-rose-600">
                                    {{ form.errors.type }}
                                </p>
                            </section>

                            <!-- 2. Core Classification & Details -->
                            <section class="p-5 space-y-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label
                                            for="new-ticket-internal-system"
                                            class="block text-xs font-medium text-slate-700 mb-1.5"
                                        >
                                            Internal System
                                        </label>
                                        <select
                                            id="new-ticket-internal-system"
                                            v-model="form.internal_system_id"
                                            class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none"
                                            :class="form.errors.internal_system_id ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-blue-500'"
                                            @change="form.clearErrors('internal_system_id')"
                                        >
                                            <option value="">- SELECT -</option>
                                            <option v-for="sys in internalSystems" :key="sys.id" :value="sys.id">
                                                {{ sys.name }}
                                            </option>
                                        </select>
                                        <p class="mt-1.5 text-xs text-slate-500">
                                            Submissions enter the engineering triage queue.
                                        </p>
                                        <p v-if="form.errors.internal_system_id" data-error="true" class="mt-1 text-xs text-rose-600">
                                            {{ form.errors.internal_system_id }}
                                        </p>
                                    </div>

                                    <div>
                                        <label
                                            for="new-ticket-priority"
                                            class="block text-xs font-medium text-slate-700 mb-1.5"
                                        >
                                            Priority
                                        </label>
                                        <select
                                            id="new-ticket-priority"
                                            v-model="form.priority"
                                            class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none"
                                            :class="form.errors.priority ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-blue-500'"
                                            @change="form.clearErrors('priority')"
                                        >
                                            <option v-for="opt in priorityOptions" :key="opt.value" :value="opt.value">
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                        <p class="mt-1.5 text-xs text-slate-500">
                                            {{ selectedPriority.description }}
                                        </p>
                                        <p v-if="form.errors.priority" data-error="true" class="mt-1 text-xs text-rose-600">
                                            {{ form.errors.priority }}
                                        </p>
                                    </div>
                                </div>

                                <div>
                                    <label
                                        for="new-ticket-title"
                                        class="block text-xs font-medium text-slate-700 mb-1.5"
                                    >
                                        Summary / Title <span class="text-rose-600">*</span>
                                    </label>
                                    <input
                                        id="new-ticket-title"
                                        v-model="form.title"
                                        type="text"
                                        required
                                        maxlength="255"
                                        :placeholder="
                                            form.type === 'issue'
                                                ? 'e.g. Duplicate invoice batch generated during midnight cron failover'
                                                : 'e.g. Automated GCash & Maya webhook reconciliation ledger export'
                                        "
                                        class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none"
                                        :class="form.errors.title ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-blue-500'"
                                        @input="form.clearErrors('title')"
                                    />
                                    <p v-if="form.errors.title" data-error="true" class="mt-1 text-xs text-rose-600">
                                        {{ form.errors.title }}
                                    </p>
                                </div>

                                <div>
                                    <label
                                        for="new-ticket-description"
                                        class="block text-xs font-medium text-slate-700 mb-1.5"
                                    >
                                        Description <span class="text-rose-600">*</span>
                                    </label>
                                    <textarea
                                        id="new-ticket-description"
                                        v-model="form.description"
                                        rows="4"
                                        required
                                        placeholder="Summarize what is happening, affected modules, or the high-level scope..."
                                        class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none"
                                        :class="form.errors.description ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-blue-500'"
                                        @input="form.clearErrors('description')"
                                    />
                                    <p v-if="form.errors.description" data-error="true" class="mt-1 text-xs text-rose-600">
                                        {{ form.errors.description }}
                                    </p>
                                </div>
                            </section>

                            <!-- 3. Type-Specific Details: Issue -->
                            <section v-if="form.type === 'issue'" class="p-5 space-y-4">
                                <h3 class="text-xs font-semibold text-slate-900">
                                    Diagnostic details
                                </h3>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label
                                            for="new-ticket-environment"
                                            class="block text-xs font-medium text-slate-700 mb-1.5"
                                        >
                                            Environment <span class="text-rose-600">*</span>
                                        </label>
                                        <select
                                            id="new-ticket-environment"
                                            v-model="form.environment"
                                            required
                                            class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none"
                                            :class="form.errors.environment ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-blue-500'"
                                            @change="form.clearErrors('environment')"
                                        >
                                            <option value="production">Production</option>
                                            <option value="staging">Staging</option>
                                            <option value="internal_network">Internal Network</option>
                                            <option value="other">Other</option>
                                        </select>
                                        <p v-if="form.errors.environment" data-error="true" class="mt-1 text-xs text-rose-600">
                                            {{ form.errors.environment }}
                                        </p>
                                    </div>

                                    <div>
                                        <label
                                            for="new-ticket-impact-scope"
                                            class="block text-xs font-medium text-slate-700 mb-1.5"
                                        >
                                            Impact scope <span class="text-rose-600">*</span>
                                        </label>
                                        <select
                                            id="new-ticket-impact-scope"
                                            v-model="form.impact_scope"
                                            required
                                            class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none"
                                            :class="form.errors.impact_scope ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-blue-500'"
                                            @change="form.clearErrors('impact_scope')"
                                        >
                                            <option value="single_user">Single User</option>
                                            <option value="department">Department</option>
                                            <option value="company_wide">Company-Wide</option>
                                        </select>
                                        <p v-if="form.errors.impact_scope" data-error="true" class="mt-1 text-xs text-rose-600">
                                            {{ form.errors.impact_scope }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <!-- 3. Type-Specific Details: Engineering Request -->
                            <section v-if="form.type === 'engineering_request'" class="p-5 space-y-4">
                                <h3 class="text-xs font-semibold text-slate-900">
                                    Request scope
                                </h3>

                                <div>
                                    <label
                                        for="new-ticket-business-justification"
                                        class="block text-xs font-medium text-slate-700 mb-1.5"
                                    >
                                        Business justification <span class="text-rose-600">*</span>
                                    </label>
                                    <textarea
                                        id="new-ticket-business-justification"
                                        v-model="form.business_justification"
                                        rows="3"
                                        required
                                        placeholder="Why is this enhancement or new system needed now?"
                                        class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none"
                                        :class="form.errors.business_justification ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-blue-500'"
                                        @input="form.clearErrors('business_justification')"
                                    />
                                    <p v-if="form.errors.business_justification" data-error="true" class="mt-1 text-xs text-rose-600">
                                        {{ form.errors.business_justification }}
                                    </p>
                                </div>

                                <div>
                                    <label
                                        for="new-ticket-proposed-functionality"
                                        class="block text-xs font-medium text-slate-700 mb-1.5"
                                    >
                                        Proposed functionality <span class="text-rose-600">*</span>
                                    </label>
                                    <textarea
                                        id="new-ticket-proposed-functionality"
                                        v-model="form.proposed_functionality"
                                        rows="3"
                                        required
                                        placeholder="Describe the requested workflows, inputs, outputs, permissions, or integrations..."
                                        class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none"
                                        :class="form.errors.proposed_functionality ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-blue-500'"
                                        @input="form.clearErrors('proposed_functionality')"
                                    />
                                    <p v-if="form.errors.proposed_functionality" data-error="true" class="mt-1 text-xs text-rose-600">
                                        {{ form.errors.proposed_functionality }}
                                    </p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="md:col-span-2">
                                        <label
                                            for="new-ticket-expected-benefits"
                                            class="block text-xs font-medium text-slate-700 mb-1.5"
                                        >
                                            Expected benefits <span class="text-rose-600">*</span>
                                        </label>
                                        <textarea
                                            id="new-ticket-expected-benefits"
                                            v-model="form.expected_benefits"
                                            rows="3"
                                            required
                                            placeholder="Time saved, error reduction, SLA improvement, or compliance gains..."
                                            class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none"
                                            :class="form.errors.expected_benefits ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-blue-500'"
                                            @input="form.clearErrors('expected_benefits')"
                                        />
                                        <p v-if="form.errors.expected_benefits" data-error="true" class="mt-1 text-xs text-rose-600">
                                            {{ form.errors.expected_benefits }}
                                        </p>
                                    </div>

                                    <div>
                                        <label
                                            for="new-ticket-requested-deadline"
                                            class="block text-xs font-medium text-slate-700 mb-1.5"
                                        >
                                            Requested deadline
                                        </label>
                                        <input
                                            id="new-ticket-requested-deadline"
                                            v-model="form.requested_deadline"
                                            type="date"
                                            class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none tabular-nums"
                                            :class="form.errors.requested_deadline ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-blue-500'"
                                            @input="form.clearErrors('requested_deadline')"
                                        />
                                        <p class="mt-1.5 text-xs text-slate-500">
                                            Optional target date for sprint planning.
                                        </p>
                                        <p v-if="form.errors.requested_deadline" data-error="true" class="mt-1 text-xs text-rose-600">
                                            {{ form.errors.requested_deadline }}
                                        </p>
                                    </div>
                                </div>
                            </section>

                            <!-- 4. Attachments -->
                            <section class="p-5 bg-slate-50/60 space-y-4">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                    <div>
                                        <div class="text-xs font-medium text-slate-700">
                                            Attachments <span class="text-rose-600">*</span>
                                        </div>
                                        <p class="text-xs text-slate-500 mt-0.5">
                                            At least one file is required (max 10 MB each): screenshots, error logs, CSV samples, or specification files.
                                        </p>
                                    </div>

                                    <label
                                        class="self-start sm:self-auto inline-flex items-center px-3 py-1.5 text-xs font-medium bg-white hover:bg-slate-50 border rounded-md cursor-pointer transition-colors"
                                        :class="form.errors.attachments || form.errors['attachments.0'] || extraAttachmentErrors.length > 0 ? 'border-rose-400 text-rose-700' : 'border-slate-200 text-slate-700'"
                                    >
                                        <span>Select files</span>
                                        <input
                                            type="file"
                                            multiple
                                            class="hidden"
                                            @change="handleFileChange"
                                        />
                                    </label>
                                </div>

                                <div
                                    v-if="form.attachments.length > 0"
                                    class="bg-white border border-slate-200 rounded-md divide-y divide-slate-200"
                                >
                                    <div
                                        v-for="(file, index) in form.attachments"
                                        :key="`${file.name}-${index}`"
                                        class="px-3.5 py-2 space-y-1 text-xs"
                                    >
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0 flex items-center gap-3">
                                                <span class="font-medium text-slate-800 truncate">
                                                    {{ file.name }}
                                                </span>
                                                <span class="font-mono text-[11px] text-slate-500 tabular-nums shrink-0">
                                                    {{ formatFileSize(file.size) }}
                                                </span>
                                            </div>
                                            <button
                                                type="button"
                                                class="text-xs font-medium text-rose-600 hover:text-rose-800 shrink-0"
                                                @click="removeAttachment(index)"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                        <p v-if="form.errors[`attachments.${index}`]" data-error="true" class="text-xs text-rose-600">
                                            {{ form.errors[`attachments.${index}`] }}
                                        </p>
                                    </div>
                                </div>

                                <p v-if="form.errors.attachments" data-error="true" class="text-xs text-rose-600">
                                    {{ form.errors.attachments }}
                                </p>
                                <p v-if="form.errors['attachments.0']" data-error="true" class="text-xs text-rose-600">
                                    {{ form.errors['attachments.0'] }}
                                </p>
                                <p
                                    v-for="(errorMessage, errIdx) in extraAttachmentErrors"
                                    :key="`att-err-${errIdx}`"
                                    data-error="true"
                                    class="text-xs text-rose-600"
                                >
                                    {{ errorMessage }}
                                </p>
                            </section>
                        </div>
                    </div>
                </div>

                <!-- Sticky Bottom Footer Bar -->
                <footer class="shrink-0 bg-white border-t border-slate-200 px-4 sm:px-6 lg:px-8 py-3.5">
                    <div class="max-w-4xl mx-auto flex items-center justify-between gap-3">
                        <span
                            v-if="hasFormErrors"
                            class="text-xs font-medium text-rose-600 hidden sm:inline"
                        >
                            Submission failed — please review the errors above.
                        </span>
                        <span
                            v-else
                            class="text-xs text-slate-500 hidden sm:inline"
                        >
                            Submissions enter the engineering triage queue.
                        </span>
                        <div class="flex items-center gap-2.5 ml-auto">
                            <button
                                type="button"
                                class="px-3.5 py-2 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                                @click="close"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-4 py-2 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60 rounded-md transition-colors"
                            >
                                {{ form.processing ? 'Submitting...' : 'Submit ticket' }}
                            </button>
                        </div>
                    </div>
                </footer>
            </form>
        </div>
    </Teleport>
</template>
