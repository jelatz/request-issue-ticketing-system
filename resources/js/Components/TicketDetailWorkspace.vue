<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import RoleBadge from './RoleBadge.vue';
import { useFeedback } from '../composables/useFeedback';
import {
    ENVIRONMENT_LABELS,
    IMPACT_SCOPE_LABELS,
    PRIORITY_LABELS,
    STATUS_LABELS,
    formatDate,
    formatDateTime,
    formatFileSize,
    toDateInputValue,
} from '../utils/formatters';

const props = defineProps({
    ticket: {
        type: Object,
        required: true,
    },
    engineers: {
        type: Array,
        default: () => [],
    },
    permissions: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || null);
const pageErrors = computed(() => page.props.errors || {});
const { notifyError } = useFeedback();

const activeActivityTab = ref('comments');

const initialAttachments = computed(() =>
    (props.ticket.attachments || []).filter((att) => !att.ticket_comment_id)
);

const subtasksTotal = computed(() => (props.ticket.subtasks || []).length);
const subtasksCompleted = computed(
    () => (props.ticket.subtasks || []).filter((st) => st.is_completed).length
);
const subtasksProgress = computed(() => {
    if (subtasksTotal.value === 0) {
        return 0;
    }
    return Math.round((subtasksCompleted.value / subtasksTotal.value) * 100);
});

const diagnosticRows = computed(() => {
    const t = props.ticket;
    const rows = [];

    if (t.type === 'issue') {
        if (t.environment) {
            rows.push({
                key: 'environment',
                label: 'Environment',
                value: ENVIRONMENT_LABELS[t.environment] || t.environment,
            });
        }
        if (t.impact_scope) {
            rows.push({
                key: 'impact_scope',
                label: 'Impact scope',
                value: IMPACT_SCOPE_LABELS[t.impact_scope] || t.impact_scope,
            });
        }
        if (t.steps_to_reproduce) {
            rows.push({
                key: 'steps_to_reproduce',
                label: 'Steps to reproduce',
                value: t.steps_to_reproduce,
                mono: true,
            });
        }
        if (t.actual_behavior) {
            rows.push({
                key: 'actual_behavior',
                label: 'Actual behavior',
                value: t.actual_behavior,
                labelClass: 'text-rose-700 font-medium',
            });
        }
        if (t.expected_behavior) {
            rows.push({
                key: 'expected_behavior',
                label: 'Expected behavior',
                value: t.expected_behavior,
                labelClass: 'text-emerald-700 font-medium',
            });
        }
    } else if (t.type === 'engineering_request') {
        if (t.business_justification) {
            rows.push({
                key: 'business_justification',
                label: 'Business justification',
                value: t.business_justification,
            });
        }
        if (t.proposed_functionality) {
            rows.push({
                key: 'proposed_functionality',
                label: 'Proposed functionality',
                value: t.proposed_functionality,
            });
        }
        if (t.expected_benefits) {
            rows.push({
                key: 'expected_benefits',
                label: 'Expected benefits',
                value: t.expected_benefits,
            });
        }
        if (t.requested_deadline) {
            rows.push({
                key: 'requested_deadline',
                label: 'Requested deadline',
                value: formatDate(t.requested_deadline),
                tabular: true,
            });
        }
    }

    if (t.review_decision_notes) {
        rows.push({
            key: 'review_decision_notes',
            label: 'Review decision notes',
            value: t.review_decision_notes,
        });
    }

    if (t.resolution_summary) {
        rows.push({
            key: 'resolution_summary',
            label: 'Resolution summary',
            value: t.resolution_summary,
            labelClass: 'text-emerald-700 font-medium',
        });
    }

    return rows;
});

// Requester Verification Actions (for non-staff requester)
const showRequesterBanner = computed(() => {
    if (!user.value || user.value.is_staff || props.permissions?.can_approve_request) {
        return false;
    }
    if (props.ticket.requester_id !== user.value.id) {
        return false;
    }
    return ['pending_approval', 'resolved', 'waiting_on_user', 'closed', 'open'].includes(props.ticket.status);
});

const approvalForm = useForm({
    decision: '',
    decision_notes: '',
});

function submitApprovalDecision(decision) {
    approvalForm.clearErrors();

    if (decision === 'reject' && !String(approvalForm.decision_notes || '').trim()) {
        const message = 'Please provide a reason for rejecting this engineering request.';
        approvalForm.setError('decision_notes', message);
        notifyError(message);
        return;
    }

    approvalForm.decision = decision;
    approvalForm.patch(`/tickets/${props.ticket.id}/approval`, {
        preserveScroll: true,
        onSuccess: () => {
            approvalForm.reset();
            approvalForm.clearErrors();
        },
        onError: (errors) => {
            const firstError = Object.values(errors || {}).find((v) => typeof v === 'string' && v.length > 0);
            if (firstError) {
                notifyError(firstError);
            }
        },
    });
}

const isAutoApproved = computed(
    () =>
        props.ticket.type === 'engineering_request' &&
        props.ticket.approval_status === 'approved' &&
        Boolean(props.ticket.approval_decided_by_id) &&
        props.ticket.approval_decided_by_id === props.ticket.requester_id
);

const reopenReason = ref('');
const showReopenInput = ref(false);
const requesterActionProcessing = ref(false);

function submitRequesterAction(action) {
    requesterActionProcessing.value = true;
    router.patch(
        `/tickets/${props.ticket.id}/workflow`,
        {
            action,
            reopen_reason: action === 'reopen' ? reopenReason.value : null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                reopenReason.value = '';
                showReopenInput.value = false;
            },
            onFinish: () => {
                requesterActionProcessing.value = false;
            },
        }
    );
}

function cancelTicket() {
    if (user.value?.is_staff) {
        requesterActionProcessing.value = true;
        router.patch(
            `/tickets/${props.ticket.id}/workflow`,
            { status: 'cancelled' },
            {
                preserveScroll: true,
                onFinish: () => {
                    requesterActionProcessing.value = false;
                },
            }
        );
        return;
    }

    submitRequesterAction('cancel');
}

// Subtask Form
const subtaskForm = useForm({
    title: '',
    assigned_engineer_id: '',
});

function addSubtask() {
    subtaskForm.post(`/tickets/${props.ticket.id}/subtasks`, {
        preserveScroll: true,
        onSuccess: () => subtaskForm.reset(),
    });
}

function toggleSubtask(subtask) {
    router.patch(
        `/tickets/${props.ticket.id}/subtasks/${subtask.id}/toggle`,
        {},
        {
            preserveScroll: true,
        }
    );
}

function deleteSubtask(subtask) {
    router.delete(`/tickets/${props.ticket.id}/subtasks/${subtask.id}`, {
        preserveScroll: true,
    });
}

// Comment / Internal Note Form
const commentForm = useForm({
    body: '',
    is_internal: false,
    attachments: [],
});

const commentAttachmentErrors = computed(() =>
    Object.entries(commentForm.errors)
        .filter(([key]) => key === 'attachments' || key.startsWith('attachments.'))
        .map(([, msg]) => msg)
);

function handleCommentFiles(event) {
    const files = Array.from(event.target.files || []);
    commentForm.attachments = [...commentForm.attachments, ...files];
    event.target.value = '';
}

function removeCommentAttachment(index) {
    commentForm.attachments = commentForm.attachments.filter((_, idx) => idx !== index);
}

function submitComment() {
    commentForm.post(`/tickets/${props.ticket.id}/comments`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            commentForm.reset('body', 'attachments');
            commentForm.is_internal = false;
        },
    });
}

// Assignment Form
const assignmentForm = useForm({
    lead_engineer_id: props.ticket.lead_engineer_id ? String(props.ticket.lead_engineer_id) : '',
    collaborator_ids: (props.ticket.collaborators || []).map((c) => c.id),
});

const collaboratorErrors = computed(() =>
    Object.entries(assignmentForm.errors)
        .filter(([key]) => key === 'collaborator_ids' || key.startsWith('collaborator_ids.'))
        .map(([, msg]) => msg)
);

watch(
    () => props.ticket,
    (updated) => {
        assignmentForm.lead_engineer_id = updated.lead_engineer_id ? String(updated.lead_engineer_id) : '';
        assignmentForm.collaborator_ids = (updated.collaborators || []).map((c) => c.id);
    },
    { deep: true }
);

function saveAssignment() {
    assignmentForm
        .transform((data) => ({
            lead_engineer_id: data.lead_engineer_id ? Number(data.lead_engineer_id) : null,
            collaborator_ids: data.collaborator_ids,
        }))
        .patch(`/tickets/${props.ticket.id}/assignment`, {
            preserveScroll: true,
        });
}

// Staff Workflow & Triage Form
const workflowForm = useForm({
    status: props.ticket.status,
    priority: props.ticket.priority,
    target_due_date: toDateInputValue(props.ticket.target_due_date),
    review_decision_notes: props.ticket.review_decision_notes || '',
    resolution_summary: props.ticket.resolution_summary || '',
});

watch(
    () => props.ticket,
    (updated) => {
        workflowForm.status = updated.status;
        workflowForm.priority = updated.priority;
        workflowForm.target_due_date = toDateInputValue(updated.target_due_date);
        workflowForm.review_decision_notes = updated.review_decision_notes || '';
        workflowForm.resolution_summary = updated.resolution_summary || '';
    },
    { deep: true }
);

watch(
    () => props.ticket.id,
    () => {
        activeActivityTab.value = 'comments';
        reopenReason.value = '';
        showReopenInput.value = false;
        approvalForm.reset();
        approvalForm.clearErrors();
        subtaskForm.reset();
        subtaskForm.clearErrors();
        commentForm.reset();
        commentForm.clearErrors();
        commentForm.is_internal = false;
        assignmentForm.clearErrors();
        workflowForm.clearErrors();
    }
);

function saveWorkflow() {
    workflowForm.patch(`/tickets/${props.ticket.id}/workflow`, {
        preserveScroll: true,
    });
}

const workflowStatuses = Object.keys(STATUS_LABELS).filter((status) => status !== 'pending_approval');
const workflowPriorities = Object.keys(PRIORITY_LABELS);

defineExpose({
    cancelTicket,
    requesterActionProcessing,
});
</script>

<template>
    <div>
        <!-- Action Error Alert (when requester banner is not shown) -->
        <div
            v-if="(pageErrors.action || pageErrors.reopen_reason) && !showRequesterBanner"
            class="mb-6 flex items-start gap-2 px-3.5 py-2.5 rounded-md bg-rose-50/80 border border-rose-200 text-rose-900 text-xs"
        >
            <span class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0 mt-1" />
            <div class="space-y-0.5">
                <p v-if="pageErrors.action">{{ pageErrors.action }}</p>
                <p v-if="pageErrors.reopen_reason">{{ pageErrors.reopen_reason }}</p>
            </div>
        </div>

        <!-- Director Approval Decision Card -->
        <section
            v-if="permissions.can_approve_request"
            class="mb-6 bg-indigo-50/70 border border-indigo-200 rounded-lg p-4 space-y-3"
        >
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <div class="space-y-0.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xs font-semibold text-indigo-950">
                            Director approval required
                        </h2>
                        <span
                            v-if="!user?.is_director"
                            class="px-1.5 py-0.5 text-[11px] font-medium rounded border border-indigo-200 bg-white text-indigo-700"
                        >
                            Superadmin fallback
                        </span>
                    </div>
                    <p class="text-xs text-slate-600">
                        Review the justification, proposed functionality, and expected benefits before releasing this engineering request to the engineering triage queue.
                    </p>
                </div>
            </div>

            <div>
                <label for="approval-decision-notes" class="block text-xs font-medium text-slate-700 mb-1">
                    Decision notes (optional for approval, required for rejection)
                </label>
                <textarea
                    id="approval-decision-notes"
                    v-model="approvalForm.decision_notes"
                    rows="2"
                    maxlength="10000"
                    placeholder="Document approval scope, sprint guidance, or reason for rejection..."
                    class="w-full px-3 py-2 text-sm bg-white border rounded-md focus:outline-none"
                    :class="approvalForm.errors.decision_notes ? 'border-rose-400 focus:border-rose-500' : 'border-slate-200 focus:border-indigo-500'"
                    @input="approvalForm.clearErrors('decision_notes', 'decision')"
                />
                <p v-if="approvalForm.errors.decision_notes" class="mt-1 text-xs text-rose-600">
                    {{ approvalForm.errors.decision_notes }}
                </p>
                <p v-if="approvalForm.errors.decision" class="mt-1 text-xs text-rose-600">
                    {{ approvalForm.errors.decision }}
                </p>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <button
                    type="button"
                    :disabled="approvalForm.processing"
                    class="px-3.5 py-1.5 text-xs font-medium text-white bg-rose-600 hover:bg-rose-700 disabled:opacity-60 rounded-md transition-colors"
                    @click="submitApprovalDecision('reject')"
                >
                    {{ approvalForm.processing && approvalForm.decision === 'reject' ? 'Rejecting...' : 'Reject request' }}
                </button>
                <button
                    type="button"
                    :disabled="approvalForm.processing"
                    class="px-3.5 py-1.5 text-xs font-medium text-white bg-emerald-600 hover:bg-emerald-700 disabled:opacity-60 rounded-md transition-colors"
                    @click="submitApprovalDecision('approve')"
                >
                    {{ approvalForm.processing && approvalForm.decision === 'approve' ? 'Approving...' : 'Approve request' }}
                </button>
            </div>
        </section>

        <!-- Requester Verification / Action Banner -->
        <div
            v-if="showRequesterBanner"
            class="mb-6 bg-blue-50/70 border border-blue-200 rounded-lg p-4 space-y-3"
        >
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="space-y-0.5">
                    <div class="text-xs font-semibold text-blue-900">
                        Requester action available
                    </div>
                    <p v-if="ticket.status === 'resolved'" class="text-sm text-slate-700">
                        Engineering has marked this ticket as <span class="font-medium text-slate-900">Resolved</span>. Verify the fix and confirm closure, or reopen if the issue persists.
                    </p>
                    <p v-else-if="ticket.status === 'waiting_on_user'" class="text-sm text-slate-700">
                        Engineering is waiting on your feedback. Post a reply below, or confirm resolution to close this ticket.
                    </p>
                    <p v-else-if="ticket.status === 'closed'" class="text-sm text-slate-700">
                        This ticket is closed. If the issue has recurred, you can reopen it for engineering review.
                    </p>
                    <p v-else-if="ticket.status === 'pending_approval'" class="text-sm text-slate-700">
                        This engineering request is awaiting director approval before entering the engineering queue. You can cancel the request if it is no longer needed.
                    </p>
                    <p v-else-if="ticket.status === 'open'" class="text-sm text-slate-700">
                        This ticket has not entered active engineering work yet. You can cancel the request if it is no longer needed.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    <button
                        v-if="['resolved', 'waiting_on_user'].includes(ticket.status)"
                        type="button"
                        :disabled="requesterActionProcessing"
                        class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60 rounded-md transition-colors"
                        @click="submitRequesterAction('confirm_close')"
                    >
                        Confirm &amp; close
                    </button>

                    <button
                        v-if="['resolved', 'closed'].includes(ticket.status)"
                        type="button"
                        :disabled="requesterActionProcessing"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 disabled:opacity-60 rounded-md transition-colors"
                        @click="showReopenInput = !showReopenInput"
                    >
                        Reopen ticket
                    </button>

                    <button
                        v-if="['open', 'pending_approval'].includes(ticket.status) && permissions.can_cancel"
                        type="button"
                        :disabled="requesterActionProcessing"
                        class="px-3 py-1.5 text-xs font-medium text-rose-700 bg-white hover:bg-rose-50 border border-rose-200 disabled:opacity-60 rounded-md transition-colors"
                        @click="submitRequesterAction('cancel')"
                    >
                        Cancel request
                    </button>
                </div>
            </div>

            <div v-if="showReopenInput" class="pt-3 border-t border-blue-200/80 space-y-2">
                <label for="reopen_reason" class="block text-xs font-medium text-slate-700">
                    Reason for reopening (optional)
                </label>
                <div class="flex flex-col sm:flex-row gap-2">
                    <input
                        id="reopen_reason"
                        v-model="reopenReason"
                        type="text"
                        maxlength="5000"
                        placeholder="Explain what is still failing or missing..."
                        class="flex-1 px-3 py-1.5 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                    />
                    <button
                        type="button"
                        :disabled="requesterActionProcessing"
                        class="px-3.5 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60 rounded-md transition-colors"
                        @click="submitRequesterAction('reopen')"
                    >
                        Submit reopen
                    </button>
                </div>
            </div>

            <div
                v-if="pageErrors.action || pageErrors.reopen_reason"
                class="flex items-start gap-2 px-3 py-2 rounded-md bg-rose-50/90 border border-rose-200 text-rose-800 text-xs"
            >
                <span class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0 mt-1" />
                <div class="space-y-0.5">
                    <p v-if="pageErrors.action">{{ pageErrors.action }}</p>
                    <p v-if="pageErrors.reopen_reason">{{ pageErrors.reopen_reason }}</p>
                </div>
            </div>
        </div>

        <!-- Two-Column Split Workspace -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Left Main Stream -->
            <div class="lg:col-span-8 space-y-4">
                <!-- 1. Description & Diagnostics Sheet -->
                <section class="bg-white border border-slate-200 rounded-lg p-5 space-y-5">
                    <div class="space-y-2">
                        <h2 class="text-sm font-semibold text-slate-900">
                            Description
                        </h2>
                        <p class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed">
                            {{ ticket.description }}
                        </p>
                    </div>

                    <div
                        v-if="diagnosticRows.length > 0"
                        class="border border-slate-200 rounded-md divide-y divide-slate-200 text-xs"
                    >
                        <div
                            v-for="row in diagnosticRows"
                            :key="row.key"
                            class="grid grid-cols-1 sm:grid-cols-12"
                        >
                            <div
                                class="sm:col-span-4 px-3.5 py-2.5 bg-slate-50/70 sm:border-r border-b sm:border-b-0 border-slate-200"
                                :class="row.labelClass || 'font-medium text-slate-600'"
                            >
                                {{ row.label }}
                            </div>
                            <div
                                class="sm:col-span-8 px-3.5 py-2.5 text-slate-800 whitespace-pre-wrap leading-relaxed"
                                :class="[
                                    row.mono ? 'font-mono' : '',
                                    row.tabular ? 'tabular-nums' : '',
                                ]"
                            >
                                {{ row.value }}
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 2. Subtasks Checklist Card -->
                <section
                    v-if="(ticket.subtasks && ticket.subtasks.length > 0) || permissions.can_manage_subtasks"
                    class="bg-white border border-slate-200 rounded-lg p-5 space-y-4"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-semibold text-slate-900">
                                Subtasks
                            </h2>
                            <span class="font-mono text-xs text-slate-500 tabular-nums">
                                ({{ subtasksCompleted }}/{{ subtasksTotal }})
                            </span>
                        </div>
                        <span class="text-xs text-slate-500 tabular-nums">
                            {{ subtasksProgress }}% complete
                        </span>
                    </div>

                    <div class="w-full h-1.5 bg-slate-100 rounded-full overflow-hidden">
                        <div
                            class="h-full bg-blue-600 transition-all duration-300"
                            :style="{ width: `${subtasksProgress}%` }"
                        />
                    </div>

                    <div
                        v-if="subtasksTotal === 0"
                        class="text-xs text-slate-500 py-1"
                    >
                        No subtasks have been added yet.
                    </div>

                    <div v-else class="border border-slate-200 rounded-md divide-y divide-slate-200">
                        <div
                            v-for="subtask in ticket.subtasks"
                            :key="subtask.id"
                            class="group px-3.5 py-2.5 flex items-start justify-between gap-3 hover:bg-slate-50/70 transition-colors"
                        >
                            <label class="flex items-start gap-3 min-w-0 flex-1 cursor-pointer">
                                <input
                                    type="checkbox"
                                    :checked="subtask.is_completed"
                                    :disabled="!permissions.can_manage_subtasks"
                                    class="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-60"
                                    @change="toggleSubtask(subtask)"
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="text-sm leading-snug"
                                        :class="subtask.is_completed ? 'line-through text-slate-400' : 'text-slate-900'"
                                    >
                                        {{ subtask.title }}
                                    </div>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-slate-500">
                                        <span>
                                            {{ subtask.assigned_engineer?.name || 'Unassigned' }}
                                        </span>
                                        <span
                                            v-if="subtask.is_completed && subtask.completed_at"
                                            class="tabular-nums text-slate-400"
                                        >
                                            · Completed {{ formatDateTime(subtask.completed_at) }}
                                        </span>
                                    </div>
                                </div>
                            </label>

                            <button
                                v-if="permissions.can_manage_subtasks"
                                type="button"
                                class="opacity-0 group-hover:opacity-100 focus:opacity-100 text-xs text-slate-400 hover:text-rose-600 transition-opacity shrink-0"
                                @click="deleteSubtask(subtask)"
                            >
                                Remove
                            </button>
                        </div>
                    </div>

                    <!-- Inline Add Subtask Form -->
                    <form
                        v-if="permissions.can_manage_subtasks"
                        novalidate
                        class="flex flex-col sm:flex-row gap-2"
                        @submit.prevent="addSubtask"
                    >
                        <label for="subtask-title" class="sr-only">Subtask title</label>
                        <input
                            id="subtask-title"
                            v-model="subtaskForm.title"
                            type="text"
                            maxlength="255"
                            required
                            placeholder="Add subtask or verification step..."
                            class="flex-1 px-3 py-1.5 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                        />
                        <label for="subtask-assigned-engineer" class="sr-only">Assigned engineer</label>
                        <select
                            id="subtask-assigned-engineer"
                            v-model="subtaskForm.assigned_engineer_id"
                            class="sm:w-44 px-2.5 py-1.5 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                        >
                            <option value="">Unassigned</option>
                            <option v-for="eng in engineers" :key="eng.id" :value="eng.id">
                                {{ eng.name }}
                            </option>
                        </select>
                        <button
                            type="submit"
                            :disabled="subtaskForm.processing"
                            class="px-3.5 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60 rounded-md transition-colors shrink-0"
                        >
                            Add
                        </button>
                    </form>
                    <div v-if="subtaskForm.hasErrors" class="space-y-1">
                        <p class="flex items-center gap-1.5 text-xs font-medium text-rose-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0" />
                            <span>Failed to add subtask. Please review the errors below.</span>
                        </p>
                        <p v-if="subtaskForm.errors.title" class="text-xs text-rose-600">
                            {{ subtaskForm.errors.title }}
                        </p>
                        <p v-if="subtaskForm.errors.assigned_engineer_id" class="text-xs text-rose-600">
                            {{ subtaskForm.errors.assigned_engineer_id }}
                        </p>
                    </div>
                </section>

                <!-- 3. Attachments Card -->
                <section
                    v-if="initialAttachments.length > 0"
                    class="bg-white border border-slate-200 rounded-lg p-5 space-y-3"
                >
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-semibold text-slate-900">
                            Attachments
                        </h2>
                        <span class="font-mono text-xs text-slate-500 tabular-nums">
                            ({{ initialAttachments.length }})
                        </span>
                    </div>

                    <div class="border border-slate-200 rounded-md divide-y divide-slate-200">
                        <div
                            v-for="attachment in initialAttachments"
                            :key="attachment.id"
                            class="px-3.5 py-2.5 flex items-center justify-between gap-3 text-xs hover:bg-slate-50/70 transition-colors"
                        >
                            <div class="min-w-0 flex items-center gap-3">
                                <span class="font-medium text-slate-800 truncate">
                                    {{ attachment.original_name }}
                                </span>
                                <span class="font-mono text-[11px] text-slate-500 tabular-nums shrink-0">
                                    {{ formatFileSize(attachment.file_size) }}
                                </span>
                            </div>
                            <a
                                :href="`/attachments/${attachment.id}/download`"
                                class="font-medium text-blue-600 hover:text-blue-700 shrink-0"
                            >
                                Download
                            </a>
                        </div>
                    </div>
                </section>

                <!-- 4. Discussion & Audit Log Card -->
                <section class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                    <div role="tablist" class="px-5 border-b border-slate-200 flex items-center gap-6">
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="activeActivityTab === 'comments'"
                            class="py-3 text-xs border-b-2 transition-colors"
                            :class="
                                activeActivityTab === 'comments'
                                    ? 'border-blue-600 text-slate-900 font-medium'
                                    : 'border-transparent text-slate-500 hover:text-slate-700'
                            "
                            @click="activeActivityTab = 'comments'"
                        >
                            Comments ({{ (ticket.comments || []).length }})
                        </button>
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="activeActivityTab === 'audit'"
                            class="py-3 text-xs border-b-2 transition-colors"
                            :class="
                                activeActivityTab === 'audit'
                                    ? 'border-blue-600 text-slate-900 font-medium'
                                    : 'border-transparent text-slate-500 hover:text-slate-700'
                            "
                            @click="activeActivityTab = 'audit'"
                        >
                            Audit log ({{ (ticket.activities || []).length }})
                        </button>
                    </div>

                    <!-- Comments Tab -->
                    <div v-if="activeActivityTab === 'comments'" class="p-5 space-y-4">
                        <div
                            v-if="!ticket.comments || ticket.comments.length === 0"
                            class="text-xs text-slate-500 py-4 text-center border border-dashed border-slate-200 rounded-md"
                        >
                            No comments or engineering updates posted yet.
                        </div>

                        <div v-else class="space-y-3">
                            <article
                                v-for="comment in ticket.comments"
                                :key="comment.id"
                                class="rounded-lg border p-4 space-y-2"
                                :class="
                                    comment.is_internal
                                        ? 'bg-amber-50/60 border-amber-200'
                                        : 'bg-white border-slate-200'
                                "
                            >
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-medium text-slate-900">
                                            {{ comment.user?.name || 'Unknown User' }}
                                        </span>
                                        <RoleBadge v-if="comment.user?.role" :role="comment.user.role" />
                                        <span
                                            v-if="comment.is_internal"
                                            class="inline-flex items-center px-2 py-0.5 rounded border border-amber-200 bg-amber-100 text-[11px] font-medium text-amber-800"
                                        >
                                            Internal note
                                        </span>
                                    </div>
                                    <span class="font-mono text-[11px] text-slate-500 tabular-nums">
                                        {{ formatDateTime(comment.created_at) }}
                                    </span>
                                </div>

                                <p class="text-sm text-slate-700 whitespace-pre-wrap leading-relaxed">
                                    {{ comment.body }}
                                </p>

                                <div
                                    v-if="comment.attachments && comment.attachments.length > 0"
                                    class="pt-2 border-t border-slate-200/80 flex flex-wrap gap-2"
                                >
                                    <a
                                        v-for="att in comment.attachments"
                                        :key="att.id"
                                        :href="`/attachments/${att.id}/download`"
                                        class="inline-flex items-center gap-2 px-2.5 py-1 rounded bg-white border border-slate-200 hover:border-blue-300 text-xs text-blue-600 hover:text-blue-700 transition-colors"
                                    >
                                        <span class="font-medium">{{ att.original_name }}</span>
                                        <span class="font-mono text-[11px] text-slate-500 tabular-nums">
                                            {{ formatFileSize(att.file_size) }}
                                        </span>
                                    </a>
                                </div>
                            </article>
                        </div>

                        <!-- Reply Composer -->
                        <form novalidate class="pt-4 border-t border-slate-200 space-y-3" @submit.prevent="submitComment">
                            <div
                                v-if="commentForm.hasErrors"
                                class="flex items-center gap-2 px-3 py-2 rounded-md bg-rose-50/80 border border-rose-200 text-rose-800 text-xs"
                            >
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0" />
                                <span>Failed to post message. Please review the errors below.</span>
                            </div>

                            <div>
                                <label for="comment-body" class="sr-only">Reply</label>
                                <textarea
                                    id="comment-body"
                                    v-model="commentForm.body"
                                    rows="3"
                                    maxlength="10000"
                                    required
                                    :placeholder="
                                        commentForm.is_internal
                                            ? 'Write an internal note visible to engineering staff only...'
                                            : 'Write a reply or update...'
                                    "
                                    class="w-full px-3 py-2 text-sm border rounded-md focus:outline-none"
                                    :class="
                                        commentForm.is_internal
                                            ? 'border-amber-300 bg-amber-50/40 focus:border-amber-500'
                                            : 'border-slate-200 bg-white focus:border-blue-500'
                                    "
                                />
                                <p v-if="commentForm.errors.body" class="mt-1 text-xs text-rose-600">
                                    {{ commentForm.errors.body }}
                                </p>
                            </div>

                            <div v-if="commentForm.attachments.length > 0" class="flex flex-wrap gap-2">
                                <div
                                    v-for="(file, index) in commentForm.attachments"
                                    :key="`${file.name}-${index}`"
                                    class="inline-flex items-center gap-2 px-2.5 py-1 rounded bg-slate-50 border border-slate-200 text-xs text-slate-700"
                                >
                                    <span>{{ file.name }}</span>
                                    <span class="font-mono text-[11px] text-slate-500 tabular-nums">
                                        {{ formatFileSize(file.size) }}
                                    </span>
                                    <button
                                        type="button"
                                        class="text-xs font-medium text-rose-600 hover:text-rose-800"
                                        @click="removeCommentAttachment(index)"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>

                            <div v-if="commentAttachmentErrors.length > 0" class="space-y-1">
                                <p
                                    v-for="(errorMsg, idx) in commentAttachmentErrors"
                                    :key="idx"
                                    class="text-xs text-rose-600"
                                >
                                    {{ errorMsg }}
                                </p>
                            </div>

                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div class="flex flex-wrap items-center gap-4">
                                    <label for="comment-attachments" class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md cursor-pointer transition-colors">
                                        <span>Attach files</span>
                                        <input
                                            id="comment-attachments"
                                            type="file"
                                            multiple
                                            class="hidden"
                                            @change="handleCommentFiles"
                                        />
                                    </label>

                                    <label
                                        v-if="permissions.can_post_internal_note"
                                        for="comment-is-internal"
                                        class="inline-flex items-center gap-2 text-xs text-slate-700 cursor-pointer"
                                    >
                                        <input
                                            id="comment-is-internal"
                                            v-model="commentForm.is_internal"
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                                        />
                                        <span :class="commentForm.is_internal ? 'font-medium text-amber-800' : ''">
                                            Internal note (staff only)
                                        </span>
                                    </label>
                                </div>

                                <button
                                    type="submit"
                                    :disabled="commentForm.processing"
                                    class="px-3.5 py-1.5 text-xs font-medium text-white rounded-md disabled:opacity-60 transition-colors"
                                    :class="
                                        commentForm.is_internal
                                            ? 'bg-amber-700 hover:bg-amber-800'
                                            : 'bg-blue-600 hover:bg-blue-700'
                                    "
                                >
                                    {{
                                        commentForm.processing
                                            ? 'Posting...'
                                            : commentForm.is_internal
                                              ? 'Post internal note'
                                              : 'Post reply'
                                    }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Audit Log Tab -->
                    <div v-else>
                        <div
                            v-if="!ticket.activities || ticket.activities.length === 0"
                            class="p-5 text-xs text-slate-500"
                        >
                            No activity events logged.
                        </div>

                        <div v-else class="divide-y divide-slate-200">
                            <div
                                v-for="activity in ticket.activities"
                                :key="activity.id"
                                class="px-5 py-3 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 text-xs"
                            >
                                <div class="space-y-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-slate-900">
                                            {{ activity.user?.name || 'System' }}
                                        </span>
                                        <span class="font-mono text-[11px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 border border-slate-200">
                                            {{ activity.action_type }}
                                        </span>
                                    </div>
                                    <p class="text-slate-700">
                                        {{ activity.description }}
                                    </p>
                                </div>
                                <span class="font-mono text-[11px] text-slate-400 tabular-nums shrink-0">
                                    {{ formatDateTime(activity.created_at) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Right Properties Rail -->
            <aside class="lg:col-span-4 bg-white border border-slate-200 rounded-lg divide-y divide-slate-200">
                <!-- Section 1: Workflow & Triage (Staff only) -->
                <section v-if="permissions.can_manage_workflow" class="p-5 space-y-3.5">
                    <h2 class="text-xs font-semibold text-slate-900">
                        Workflow &amp; triage
                    </h2>

                    <form novalidate class="space-y-3" @submit.prevent="saveWorkflow">
                        <div
                            v-if="workflowForm.hasErrors"
                            class="flex items-center gap-2 px-3 py-2 rounded-md bg-rose-50/80 border border-rose-200 text-rose-800 text-xs"
                        >
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0" />
                            <span>Failed to update ticket workflow. Please review the errors below.</span>
                        </div>

                        <div>
                            <label for="wf-status" class="block text-xs font-medium text-slate-600 mb-1">
                                Status
                            </label>
                            <select
                                id="wf-status"
                                v-model="workflowForm.status"
                                class="w-full px-2.5 py-1.5 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                            >
                                <option v-for="statusKey in workflowStatuses" :key="statusKey" :value="statusKey">
                                    {{ STATUS_LABELS[statusKey] }}
                                </option>
                            </select>
                            <p v-if="workflowForm.errors.status" class="mt-1 text-xs text-rose-600">
                                {{ workflowForm.errors.status }}
                            </p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="wf-priority" class="block text-xs font-medium text-slate-600 mb-1">
                                    Priority
                                </label>
                                <select
                                    id="wf-priority"
                                    v-model="workflowForm.priority"
                                    class="w-full px-2.5 py-1.5 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                                >
                                    <option v-for="priorityKey in workflowPriorities" :key="priorityKey" :value="priorityKey">
                                        {{ PRIORITY_LABELS[priorityKey] }}
                                    </option>
                                </select>
                                <p v-if="workflowForm.errors.priority" class="mt-1 text-xs text-rose-600">
                                    {{ workflowForm.errors.priority }}
                                </p>
                            </div>

                            <div>
                                <label for="wf-due" class="block text-xs font-medium text-slate-600 mb-1">
                                    Target due date
                                </label>
                                <input
                                    id="wf-due"
                                    v-model="workflowForm.target_due_date"
                                    type="date"
                                    class="w-full px-2.5 py-1.5 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500 tabular-nums"
                                />
                                <p v-if="workflowForm.errors.target_due_date" class="mt-1 text-xs text-rose-600">
                                    {{ workflowForm.errors.target_due_date }}
                                </p>
                            </div>
                        </div>

                        <div>
                            <label for="wf-review" class="block text-xs font-medium text-slate-600 mb-1">
                                Review / Decision notes
                            </label>
                            <textarea
                                id="wf-review"
                                v-model="workflowForm.review_decision_notes"
                                rows="2"
                                maxlength="10000"
                                placeholder="Approval rationale, feasibility notes, or rejection reason..."
                                class="w-full px-2.5 py-1.5 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                            />
                            <p v-if="workflowForm.errors.review_decision_notes" class="mt-1 text-xs text-rose-600">
                                {{ workflowForm.errors.review_decision_notes }}
                            </p>
                        </div>

                        <div>
                            <label for="wf-resolution" class="block text-xs font-medium text-slate-600 mb-1">
                                Resolution summary
                            </label>
                            <textarea
                                id="wf-resolution"
                                v-model="workflowForm.resolution_summary"
                                rows="3"
                                maxlength="10000"
                                placeholder="Document root cause, fix, and verification outcome..."
                                class="w-full px-2.5 py-1.5 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                            />
                            <p v-if="workflowForm.errors.resolution_summary" class="mt-1 text-xs text-rose-600">
                                {{ workflowForm.errors.resolution_summary }}
                            </p>
                        </div>

                        <button
                            type="submit"
                            :disabled="workflowForm.processing"
                            class="w-full px-3.5 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-60 rounded-md transition-colors"
                        >
                            {{ workflowForm.processing ? 'Saving...' : 'Save workflow' }}
                        </button>
                    </form>
                </section>

                <!-- Section 2: Assignment -->
                <section class="p-5 space-y-3.5">
                    <h2 class="text-xs font-semibold text-slate-900">
                        Assignment
                    </h2>

                    <div
                        v-if="ticket.status === 'pending_approval'"
                        class="rounded-md border border-indigo-200 bg-indigo-50/50 px-3 py-2.5 text-xs text-indigo-900"
                    >
                        Assignment is locked until a director approves this engineering request.
                    </div>

                    <form v-else-if="permissions.can_assign" novalidate class="space-y-3" @submit.prevent="saveAssignment">
                        <div
                            v-if="assignmentForm.hasErrors"
                            class="flex items-center gap-2 px-3 py-2 rounded-md bg-rose-50/80 border border-rose-200 text-rose-800 text-xs"
                        >
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0" />
                            <span>Failed to update ticket assignment. Please review the errors below.</span>
                        </div>

                        <div>
                            <label for="lead_engineer_id" class="block text-xs font-medium text-slate-600 mb-1">
                                Lead engineer
                            </label>
                            <select
                                id="lead_engineer_id"
                                v-model="assignmentForm.lead_engineer_id"
                                class="w-full px-2.5 py-1.5 text-sm bg-white border border-slate-200 rounded-md focus:outline-none focus:border-blue-500"
                            >
                                <option value="">Unassigned</option>
                                <option v-for="eng in engineers" :key="eng.id" :value="String(eng.id)">
                                    {{ eng.name }}
                                </option>
                            </select>
                            <p v-if="assignmentForm.errors.lead_engineer_id" class="mt-1 text-xs text-rose-600">
                                {{ assignmentForm.errors.lead_engineer_id }}
                            </p>
                        </div>

                        <div>
                            <div class="text-xs font-medium text-slate-600 mb-1.5">
                                Collaborators
                            </div>
                            <div class="max-h-36 overflow-y-auto space-y-1.5 border border-slate-200 rounded-md p-2.5 bg-slate-50/60">
                                <label
                                    v-for="eng in engineers"
                                    :key="eng.id"
                                    class="flex items-center gap-2 text-xs text-slate-700 cursor-pointer"
                                >
                                    <input
                                        v-model="assignmentForm.collaborator_ids"
                                        type="checkbox"
                                        :value="eng.id"
                                        :disabled="String(eng.id) === assignmentForm.lead_engineer_id"
                                        class="h-3.5 w-3.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                    />
                                    <span :class="String(eng.id) === assignmentForm.lead_engineer_id ? 'text-slate-400' : ''">
                                        {{ eng.name }}{{ String(eng.id) === assignmentForm.lead_engineer_id ? ' (Lead)' : '' }}
                                    </span>
                                </label>
                            </div>
                            <div v-if="collaboratorErrors.length > 0" class="mt-1 space-y-1">
                                <p
                                    v-for="(errorMsg, idx) in collaboratorErrors"
                                    :key="idx"
                                    class="text-xs text-rose-600"
                                >
                                    {{ errorMsg }}
                                </p>
                            </div>
                        </div>

                        <button
                            type="submit"
                            :disabled="assignmentForm.processing"
                            class="w-full px-3.5 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 disabled:opacity-60 rounded-md transition-colors"
                        >
                            {{ assignmentForm.processing ? 'Saving...' : 'Save assignment' }}
                        </button>
                    </form>

                    <dl v-else class="space-y-2.5 text-xs">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 shrink-0">Lead engineer</dt>
                            <dd class="font-medium text-slate-900 text-right">
                                {{ ticket.lead_engineer?.name || 'Unassigned' }}
                            </dd>
                        </div>
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 shrink-0">Collaborators</dt>
                            <dd class="text-right">
                                <span
                                    v-if="!ticket.collaborators || ticket.collaborators.length === 0"
                                    class="text-slate-400"
                                >
                                    None
                                </span>
                                <div v-else class="flex flex-wrap justify-end gap-1">
                                    <span
                                        v-for="collab in ticket.collaborators"
                                        :key="collab.id"
                                        class="px-2 py-0.5 text-[11px] font-medium rounded border border-slate-200 bg-slate-50 text-slate-700"
                                    >
                                        {{ collab.name }}
                                    </span>
                                </div>
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Section 3: Details -->
                <section class="p-5 space-y-3">
                    <h2 class="text-xs font-semibold text-slate-900">
                        Details
                    </h2>

                    <dl class="space-y-2.5 text-xs">
                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 shrink-0">System</dt>
                            <dd class="text-right text-slate-800">
                                <span v-if="ticket.internal_system" class="font-medium text-slate-900">{{ ticket.internal_system.name }}</span>
                                <span v-else class="text-slate-500">General / New system</span>
                            </dd>
                        </div>

                        <div v-if="ticket.type === 'engineering_request'" class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 shrink-0">Approval</dt>
                            <dd class="text-right">
                                <span v-if="ticket.status === 'pending_approval'" class="font-medium text-indigo-700">
                                    Pending director approval
                                </span>
                                <template v-else-if="ticket.approval_status === 'approved'">
                                    <div class="font-medium text-emerald-700">
                                        Approved{{ isAutoApproved ? ' (Auto-approved)' : '' }}
                                    </div>
                                    <div v-if="ticket.approval_decided_by" class="text-[11px] text-slate-500">
                                        by {{ ticket.approval_decided_by.name }}
                                    </div>
                                    <div v-if="ticket.approval_decided_at" class="text-[11px] text-slate-400 tabular-nums">
                                        {{ formatDateTime(ticket.approval_decided_at) }}
                                    </div>
                                </template>
                                <template v-else-if="ticket.approval_status === 'rejected'">
                                    <div class="font-medium text-rose-700">
                                        Rejected
                                    </div>
                                    <div v-if="ticket.approval_decided_by" class="text-[11px] text-slate-500">
                                        by {{ ticket.approval_decided_by.name }}
                                    </div>
                                    <div v-if="ticket.approval_decided_at" class="text-[11px] text-slate-400 tabular-nums">
                                        {{ formatDateTime(ticket.approval_decided_at) }}
                                    </div>
                                </template>
                                <span v-else class="text-slate-400">—</span>
                            </dd>
                        </div>

                        <div class="flex items-start justify-between gap-3">
                            <dt class="text-slate-500 shrink-0">Requester</dt>
                            <dd class="text-right">
                                <div class="font-medium text-slate-900">
                                    {{ ticket.requester?.name || 'Unknown' }}
                                </div>
                                <div v-if="ticket.requester?.department || ticket.requester?.job_title" class="text-[11px] text-slate-500">
                                    {{ [ticket.requester?.department, ticket.requester?.job_title].filter(Boolean).join(' · ') }}
                                </div>
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500 shrink-0">Created</dt>
                            <dd class="text-slate-800 tabular-nums text-right">
                                {{ formatDateTime(ticket.created_at) }}
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500 shrink-0">First response</dt>
                            <dd class="text-slate-800 tabular-nums text-right">
                                {{ formatDateTime(ticket.first_responded_at, 'Pending') }}
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500 shrink-0">Resolved</dt>
                            <dd class="text-slate-800 tabular-nums text-right">
                                {{ formatDateTime(ticket.resolved_at, '—') }}
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500 shrink-0">Updated</dt>
                            <dd class="text-slate-800 tabular-nums text-right">
                                {{ formatDateTime(ticket.updated_at) }}
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500 shrink-0">Ticket ID</dt>
                            <dd class="font-mono text-slate-700 text-right">
                                {{ ticket.ticket_number }}
                            </dd>
                        </div>
                    </dl>
                </section>
            </aside>
        </div>
    </div>
</template>
