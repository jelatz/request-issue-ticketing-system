<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { formatFileSize } from '../utils/formatters';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

const page = usePage();
const internalSystems = computed(() => page.props.internalSystems || []);

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

function close() {
    emit('close');
}

function handleFileChange(event) {
    const files = Array.from(event.target.files || []);
    if (files.length > 0) {
        form.attachments = [...form.attachments, ...files];
        form.clearErrors('attachments');
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
    if (form.attachments.length === 0) {
        form.setError('attachments', 'Please attach at least one file.');
        return;
    }

    form.post('/tickets', {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            form.clearErrors();
            emit('close');
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

            <form class="flex-1 flex flex-col min-h-0" @submit.prevent="submit">
                <!-- Scrollable Body -->
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 lg:px-8 py-6">
                    <div class="max-w-4xl mx-auto">
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
                                <p v-if="form.errors.type" class="text-xs text-rose-600">
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
                                            class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                                        >
                                            <option value="">- SELECT -</option>
                                            <option v-for="sys in internalSystems" :key="sys.id" :value="sys.id">
                                                {{ sys.name }}
                                            </option>
                                        </select>
                                        <p class="mt-1.5 text-xs text-slate-500">
                                            Submissions enter the engineering triage queue.
                                        </p>
                                        <p v-if="form.errors.internal_system_id" class="mt-1 text-xs text-rose-600">
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
                                            class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                                        >
                                            <option v-for="opt in priorityOptions" :key="opt.value" :value="opt.value">
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                        <p class="mt-1.5 text-xs text-slate-500">
                                            {{ selectedPriority.description }}
                                        </p>
                                        <p v-if="form.errors.priority" class="mt-1 text-xs text-rose-600">
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
                                        class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                                    />
                                    <p v-if="form.errors.title" class="mt-1 text-xs text-rose-600">
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
                                        class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                                    />
                                    <p v-if="form.errors.description" class="mt-1 text-xs text-rose-600">
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
                                            class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                                        >
                                            <option value="production">Production</option>
                                            <option value="staging">Staging</option>
                                            <option value="internal_network">Internal Network</option>
                                            <option value="other">Other</option>
                                        </select>
                                        <p v-if="form.errors.environment" class="mt-1 text-xs text-rose-600">
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
                                            class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                                        >
                                            <option value="single_user">Single User</option>
                                            <option value="department">Department</option>
                                            <option value="company_wide">Company-Wide</option>
                                        </select>
                                        <p v-if="form.errors.impact_scope" class="mt-1 text-xs text-rose-600">
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
                                        class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                                    />
                                    <p v-if="form.errors.business_justification" class="mt-1 text-xs text-rose-600">
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
                                        class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                                    />
                                    <p v-if="form.errors.proposed_functionality" class="mt-1 text-xs text-rose-600">
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
                                            class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                                        />
                                        <p v-if="form.errors.expected_benefits" class="mt-1 text-xs text-rose-600">
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
                                            class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500 tabular-nums"
                                        />
                                        <p class="mt-1.5 text-xs text-slate-500">
                                            Optional target date for sprint planning.
                                        </p>
                                        <p v-if="form.errors.requested_deadline" class="mt-1 text-xs text-rose-600">
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

                                    <label class="self-start sm:self-auto inline-flex items-center px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md cursor-pointer transition-colors">
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
                                        <p v-if="form.errors[`attachments.${index}`]" class="text-xs text-rose-600">
                                            {{ form.errors[`attachments.${index}`] }}
                                        </p>
                                    </div>
                                </div>

                                <p v-if="form.errors.attachments" class="text-xs text-rose-600">
                                    {{ form.errors.attachments }}
                                </p>
                                <p v-if="form.errors['attachments.0']" class="text-xs text-rose-600">
                                    {{ form.errors['attachments.0'] }}
                                </p>
                                <p
                                    v-for="(errorMessage, errIdx) in extraAttachmentErrors"
                                    :key="`att-err-${errIdx}`"
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
                        <span class="text-xs text-slate-500 hidden sm:inline">
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
