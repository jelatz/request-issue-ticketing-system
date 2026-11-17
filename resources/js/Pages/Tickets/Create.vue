<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatFileSize } from '../../utils/formatters';

const props = defineProps({
    internalSystems: {
        type: Array,
        default: () => [],
    },
});

const form = useForm({
    type: 'issue',
    internal_system_id: '',
    priority: 'medium',
    title: '',
    description: '',
    environment: 'production',
    impact_scope: 'single_user',
    steps_to_reproduce: '',
    actual_behavior: '',
    expected_behavior: '',
    business_justification: '',
    proposed_functionality: '',
    expected_benefits: '',
    requested_deadline: '',
    attachments: [],
});

const selectedSystem = computed(() => {
    if (!form.internal_system_id) {
        return null;
    }
    return props.internalSystems.find((sys) => String(sys.id) === String(form.internal_system_id)) || null;
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

function handleFileChange(event) {
    const files = Array.from(event.target.files || []);
    form.attachments = [...form.attachments, ...files];
    event.target.value = '';
}

function removeAttachment(index) {
    form.attachments = form.attachments.filter((_, idx) => idx !== index);
}

function selectType(nextType) {
    form.type = nextType;
    form.clearErrors();
}

function submit() {
    form.post('/tickets', {
        forceFormData: true,
    });
}
</script>

<template>
    <Head title="New Ticket" />

    <AppLayout title="New Ticket">
        <div class="max-w-3xl mx-auto space-y-5">
            <!-- Compact Header -->
            <div class="flex items-center justify-between gap-4">
                <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                    New ticket
                </h1>
                <Link
                    href="/tickets"
                    class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                >
                    Back to tickets
                </Link>
            </div>

            <form
                class="bg-white border border-slate-200 rounded-lg divide-y divide-slate-200 overflow-hidden"
                @submit.prevent="submit"
            >
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
                            <label for="internal_system_id" class="block text-xs font-medium text-slate-700 mb-1.5">
                                Internal System
                            </label>
                            <select
                                id="internal_system_id"
                                v-model="form.internal_system_id"
                                class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                            >
                                <option value="">General / New system proposal</option>
                                <option v-for="sys in internalSystems" :key="sys.id" :value="sys.id">
                                    {{ sys.code }} - {{ sys.name }}
                                </option>
                            </select>
                            <p v-if="selectedSystem?.default_lead_engineer" class="mt-1.5 text-xs text-blue-700">
                                Default lead: {{ selectedSystem.default_lead_engineer.name }} (auto-assigned on submit)
                            </p>
                            <p v-else class="mt-1.5 text-xs text-slate-500">
                                Unassigned submissions enter the engineering triage queue.
                            </p>
                            <p v-if="form.errors.internal_system_id" class="mt-1 text-xs text-rose-600">
                                {{ form.errors.internal_system_id }}
                            </p>
                        </div>

                        <div>
                            <label for="priority" class="block text-xs font-medium text-slate-700 mb-1.5">
                                Priority
                            </label>
                            <select
                                id="priority"
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
                        <label for="title" class="block text-xs font-medium text-slate-700 mb-1.5">
                            Summary / Title <span class="text-rose-600">*</span>
                        </label>
                        <input
                            id="title"
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
                        <label for="description" class="block text-xs font-medium text-slate-700 mb-1.5">
                            Description <span class="text-rose-600">*</span>
                        </label>
                        <textarea
                            id="description"
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
                    <h2 class="text-xs font-semibold text-slate-900">
                        Diagnostic details
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="environment" class="block text-xs font-medium text-slate-700 mb-1.5">
                                Environment <span class="text-rose-600">*</span>
                            </label>
                            <select
                                id="environment"
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
                            <label for="impact_scope" class="block text-xs font-medium text-slate-700 mb-1.5">
                                Impact scope <span class="text-rose-600">*</span>
                            </label>
                            <select
                                id="impact_scope"
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

                    <div>
                        <label for="steps_to_reproduce" class="block text-xs font-medium text-slate-700 mb-1.5">
                            Steps to reproduce <span class="text-rose-600">*</span>
                        </label>
                        <textarea
                            id="steps_to_reproduce"
                            v-model="form.steps_to_reproduce"
                            rows="4"
                            required
                            placeholder="1. Navigate to...&#10;2. Click...&#10;3. Observe error..."
                            class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                        />
                        <p v-if="form.errors.steps_to_reproduce" class="mt-1 text-xs text-rose-600">
                            {{ form.errors.steps_to_reproduce }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="actual_behavior" class="block text-xs font-medium text-slate-700 mb-1.5">
                                Actual behavior <span class="text-rose-600">*</span>
                            </label>
                            <textarea
                                id="actual_behavior"
                                v-model="form.actual_behavior"
                                rows="3"
                                required
                                placeholder="Exact error code, message, or incorrect behavior observed..."
                                class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                            />
                            <p v-if="form.errors.actual_behavior" class="mt-1 text-xs text-rose-600">
                                {{ form.errors.actual_behavior }}
                            </p>
                        </div>

                        <div>
                            <label for="expected_behavior" class="block text-xs font-medium text-slate-700 mb-1.5">
                                Expected behavior
                            </label>
                            <textarea
                                id="expected_behavior"
                                v-model="form.expected_behavior"
                                rows="3"
                                placeholder="What should happen instead when working normally..."
                                class="w-full px-3 py-2 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                            />
                            <p v-if="form.errors.expected_behavior" class="mt-1 text-xs text-rose-600">
                                {{ form.errors.expected_behavior }}
                            </p>
                        </div>
                    </div>
                </section>

                <!-- 3. Type-Specific Details: Engineering Request -->
                <section v-if="form.type === 'engineering_request'" class="p-5 space-y-4">
                    <h2 class="text-xs font-semibold text-slate-900">
                        Request scope
                    </h2>

                    <div>
                        <label for="business_justification" class="block text-xs font-medium text-slate-700 mb-1.5">
                            Business justification <span class="text-rose-600">*</span>
                        </label>
                        <textarea
                            id="business_justification"
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
                        <label for="proposed_functionality" class="block text-xs font-medium text-slate-700 mb-1.5">
                            Proposed functionality <span class="text-rose-600">*</span>
                        </label>
                        <textarea
                            id="proposed_functionality"
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
                            <label for="expected_benefits" class="block text-xs font-medium text-slate-700 mb-1.5">
                                Expected benefits <span class="text-rose-600">*</span>
                            </label>
                            <textarea
                                id="expected_benefits"
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
                            <label for="requested_deadline" class="block text-xs font-medium text-slate-700 mb-1.5">
                                Requested deadline
                            </label>
                            <input
                                id="requested_deadline"
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

                <!-- 4. Attachments & Submit Footer -->
                <section class="p-5 bg-slate-50/60 space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div>
                            <div class="text-xs font-medium text-slate-700">
                                Attachments
                            </div>
                            <p class="text-xs text-slate-500 mt-0.5">
                                Screenshots, error logs, CSV samples, or specification files (max 10 MB each).
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
                            class="px-3.5 py-2 flex items-center justify-between gap-3 text-xs"
                        >
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
                    </div>

                    <p v-if="form.errors.attachments" class="text-xs text-rose-600">
                        {{ form.errors.attachments }}
                    </p>
                    <p v-if="form.errors['attachments.0']" class="text-xs text-rose-600">
                        {{ form.errors['attachments.0'] }}
                    </p>

                    <div class="pt-3 border-t border-slate-200 flex items-center justify-end gap-2.5">
                        <Link
                            href="/tickets"
                            class="px-3.5 py-2 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-4 py-2 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60 rounded-md transition-colors"
                        >
                            {{ form.processing ? 'Submitting...' : 'Submit ticket' }}
                        </button>
                    </div>
                </section>
            </form>
        </div>
    </AppLayout>
</template>
