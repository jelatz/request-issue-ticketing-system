<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import Modal from '../../../Components/Modal.vue';
import { useFeedback } from '../../../composables/useFeedback';

const { notifyError } = useFeedback();

function formatValidationErrorMessage(errors) {
    const messages = Object.values(errors || {}).filter(
        (v) => typeof v === 'string' && v.length > 0
    );
    if (messages.length === 0) {
        return 'Submission failed. Please review the highlighted fields.';
    }
    return messages.length > 1
        ? `Submission failed: ${messages[0]} (+${messages.length - 1} more)`
        : `Submission failed: ${messages[0]}`;
}

defineProps({
    systems: {
        type: Array,
        default: () => [],
    },
});

// Create Modal
const showCreateModal = ref(false);
const createForm = useForm({
    name: '',
    category: '',
    description: '',
    is_active: true,
});

function openCreateModal() {
    createForm.reset();
    createForm.clearErrors();
    showCreateModal.value = true;
}

function closeCreateModal() {
    showCreateModal.value = false;
}

function submitCreate() {
    createForm.clearErrors();

    if (!String(createForm.name || '').trim()) {
        createForm.setError('name', 'Please enter a system name.');
    }

    if (Object.keys(createForm.errors).length > 0) {
        notifyError(formatValidationErrorMessage(createForm.errors));
        return;
    }

    createForm.post('/admin/systems', {
        preserveScroll: true,
        onSuccess: (responsePage) => {
            if (responsePage?.props?.flash?.error) {
                return;
            }
            showCreateModal.value = false;
            createForm.reset();
        },
        onError: (errors) => {
            notifyError(formatValidationErrorMessage(errors));
        },
    });
}

// Edit Modal
const showEditModal = ref(false);
const editingSystem = ref(null);
const editForm = useForm({
    name: '',
    category: '',
    description: '',
    is_active: true,
});

function openEditModal(system) {
    editingSystem.value = system;
    editForm.clearErrors();
    editForm.name = system.name || '';
    editForm.category = system.category || '';
    editForm.description = system.description || '';
    editForm.is_active = Boolean(system.is_active);
    showEditModal.value = true;
}

function closeEditModal() {
    showEditModal.value = false;
    editingSystem.value = null;
}

function submitEdit() {
    if (!editingSystem.value) {
        return;
    }

    editForm.clearErrors();

    if (!String(editForm.name || '').trim()) {
        editForm.setError('name', 'Please enter a system name.');
    }

    if (Object.keys(editForm.errors).length > 0) {
        notifyError(formatValidationErrorMessage(editForm.errors));
        return;
    }

    editForm.put(`/admin/systems/${editingSystem.value.id}`, {
        preserveScroll: true,
        onSuccess: (responsePage) => {
            if (responsePage?.props?.flash?.error) {
                return;
            }
            showEditModal.value = false;
            editingSystem.value = null;
            editForm.reset();
        },
        onError: (errors) => {
            notifyError(formatValidationErrorMessage(errors));
        },
    });
}

// Quick Toggle Active
const togglingSystemId = ref(null);

function toggleSystemActive(system) {
    togglingSystemId.value = system.id;
    router.put(
        `/admin/systems/${system.id}`,
        {
            name: system.name,
            category: system.category || '',
            description: system.description || '',
            is_active: !system.is_active,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingSystemId.value = null;
            },
        }
    );
}
</script>

<template>
    <Head title="Internal systems" />

    <AppLayout title="Internal systems">
        <div class="space-y-4">
            <!-- Compact Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                        Internal systems
                    </h1>
                    <p class="mt-0.5 text-xs text-slate-500">
                        <span class="font-medium text-slate-700 tabular-nums">{{ systems.length }}</span> configured systems for ticket categorization.
                    </p>
                </div>

                <div class="shrink-0">
                    <button
                        type="button"
                        class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors"
                        @click="openCreateModal"
                    >
                        + Add system
                    </button>
                </div>
            </div>

            <!-- Unified White Table Container -->
            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50/60 text-slate-500 font-medium border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-medium">System</th>
                                <th class="px-4 py-2.5 text-left font-medium">Category</th>
                                <th class="px-4 py-2.5 text-right font-medium">Tickets</th>
                                <th class="px-4 py-2.5 text-left font-medium">Status</th>
                                <th class="px-4 py-2.5 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            <tr v-if="systems.length === 0">
                                <td colspan="5" class="px-4 py-12 text-center text-xs text-slate-500">
                                    No internal systems configured yet. Add an internal system to enable structured routing.
                                </td>
                            </tr>
                            <tr
                                v-for="system in systems"
                                :key="system.id"
                                class="hover:bg-slate-50/70 transition-colors"
                            >
                                <td class="px-4 py-3 align-top max-w-md">
                                    <div class="text-sm font-medium text-slate-900">
                                        {{ system.name }}
                                    </div>
                                    <div v-if="system.description" class="mt-0.5 text-xs text-slate-500 leading-relaxed">
                                        {{ system.description }}
                                    </div>
                                    <div v-else class="mt-0.5 text-xs text-slate-400">
                                        No description provided
                                    </div>
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap align-top text-slate-600">
                                    {{ system.category || 'Uncategorized' }}
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap text-right align-top font-medium text-slate-800 tabular-nums">
                                    {{ system.tickets_count ?? 0 }}
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap align-top">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium">
                                        <span
                                            class="w-1.5 h-1.5 rounded-full"
                                            :class="system.is_active ? 'bg-emerald-500' : 'bg-slate-400'"
                                        />
                                        <span :class="system.is_active ? 'text-slate-800' : 'text-slate-500'">
                                            {{ system.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </span>
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap text-right align-top">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            type="button"
                                            class="px-2 py-1 text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded transition-colors"
                                            @click="openEditModal(system)"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="togglingSystemId === system.id"
                                            class="px-2 py-1 text-xs font-medium rounded transition-colors disabled:opacity-50"
                                            :class="
                                                system.is_active
                                                    ? 'text-rose-600 hover:text-rose-700 hover:bg-rose-50'
                                                    : 'text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50'
                                            "
                                            @click="toggleSystemActive(system)"
                                        >
                                            {{ system.is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Create System Modal -->
        <Modal
            :show="showCreateModal"
            title="Add internal system"
            max-width="max-w-xl"
            @close="closeCreateModal"
        >
            <form novalidate class="space-y-4" @submit.prevent="submitCreate">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="create-system-name" class="block text-xs font-medium text-slate-700 mb-1">
                            System name
                        </label>
                        <input
                            id="create-system-name"
                            v-model="createForm.name"
                            type="text"
                            maxlength="255"
                            required
                            placeholder="e.g. CallTek Core CRM"
                            class="w-full rounded-md border px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:ring-1"
                            :class="
                                createForm.errors.name
                                    ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                    : 'border-slate-300 focus:border-blue-500 focus:ring-blue-500'
                            "
                            @input="createForm.clearErrors('name')"
                        />
                        <p v-if="createForm.errors.name" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.name }}
                        </p>
                    </div>

                    <div>
                        <label for="create-system-category" class="block text-xs font-medium text-slate-700 mb-1">
                            Category
                        </label>
                        <input
                            id="create-system-category"
                            v-model="createForm.category"
                            type="text"
                            maxlength="255"
                            placeholder="e.g. Operations Platform"
                            class="w-full rounded-md border px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:ring-1"
                            :class="
                                createForm.errors.category
                                    ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                    : 'border-slate-300 focus:border-blue-500 focus:ring-blue-500'
                            "
                            @input="createForm.clearErrors('category')"
                        />
                        <p v-if="createForm.errors.category" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.category }}
                        </p>
                    </div>
                </div>

                <div>
                    <label for="create-system-description" class="block text-xs font-medium text-slate-700 mb-1">
                        Description
                    </label>
                    <textarea
                        id="create-system-description"
                        v-model="createForm.description"
                        rows="3"
                        placeholder="Scope of this internal system, key workflows, and routing notes..."
                        class="w-full rounded-md border px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:ring-1"
                        :class="
                            createForm.errors.description
                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                : 'border-slate-300 focus:border-blue-500 focus:ring-blue-500'
                        "
                        @input="createForm.clearErrors('description')"
                    />
                    <p v-if="createForm.errors.description" class="mt-1 text-xs text-rose-600">
                        {{ createForm.errors.description }}
                    </p>
                </div>

                <div class="pt-1">
                    <label for="create-system-active" class="inline-flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                        <input
                            id="create-system-active"
                            v-model="createForm.is_active"
                            type="checkbox"
                            class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        <span>System is active for new ticket intake</span>
                    </label>
                    <p v-if="createForm.errors.is_active" class="mt-1 text-xs text-rose-600">
                        {{ createForm.errors.is_active }}
                    </p>
                </div>

                <div class="pt-4 border-t border-slate-200 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                        @click="closeCreateModal"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="createForm.processing"
                        class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors disabled:opacity-50"
                    >
                        {{ createForm.processing ? 'Adding...' : 'Add system' }}
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Edit System Modal -->
        <Modal
            :show="showEditModal"
            title="Edit internal system"
            max-width="max-w-xl"
            @close="closeEditModal"
        >
            <form novalidate class="space-y-4" @submit.prevent="submitEdit">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="edit-system-name" class="block text-xs font-medium text-slate-700 mb-1">
                            System name
                        </label>
                        <input
                            id="edit-system-name"
                            v-model="editForm.name"
                            type="text"
                            maxlength="255"
                            required
                            class="w-full rounded-md border px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:ring-1"
                            :class="
                                editForm.errors.name
                                    ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                    : 'border-slate-300 focus:border-blue-500 focus:ring-blue-500'
                            "
                            @input="editForm.clearErrors('name')"
                        />
                        <p v-if="editForm.errors.name" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.name }}
                        </p>
                    </div>

                    <div>
                        <label for="edit-system-category" class="block text-xs font-medium text-slate-700 mb-1">
                            Category
                        </label>
                        <input
                            id="edit-system-category"
                            v-model="editForm.category"
                            type="text"
                            maxlength="255"
                            class="w-full rounded-md border px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:ring-1"
                            :class="
                                editForm.errors.category
                                    ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                    : 'border-slate-300 focus:border-blue-500 focus:ring-blue-500'
                            "
                            @input="editForm.clearErrors('category')"
                        />
                        <p v-if="editForm.errors.category" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.category }}
                        </p>
                    </div>
                </div>

                <div>
                    <label for="edit-system-description" class="block text-xs font-medium text-slate-700 mb-1">
                        Description
                    </label>
                    <textarea
                        id="edit-system-description"
                        v-model="editForm.description"
                        rows="3"
                        class="w-full rounded-md border px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:ring-1"
                        :class="
                            editForm.errors.description
                                ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500'
                                : 'border-slate-300 focus:border-blue-500 focus:ring-blue-500'
                        "
                        @input="editForm.clearErrors('description')"
                    />
                    <p v-if="editForm.errors.description" class="mt-1 text-xs text-rose-600">
                        {{ editForm.errors.description }}
                    </p>
                </div>

                <div class="pt-1">
                    <label for="edit-system-active" class="inline-flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                        <input
                            id="edit-system-active"
                            v-model="editForm.is_active"
                            type="checkbox"
                            class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        <span>System is active for new ticket intake</span>
                    </label>
                    <p v-if="editForm.errors.is_active" class="mt-1 text-xs text-rose-600">
                        {{ editForm.errors.is_active }}
                    </p>
                </div>

                <div class="pt-4 border-t border-slate-200 flex items-center justify-end gap-2">
                    <button
                        type="button"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 rounded-md transition-colors"
                        @click="closeEditModal"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="editForm.processing"
                        class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors disabled:opacity-50"
                    >
                        {{ editForm.processing ? 'Saving...' : 'Save changes' }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
