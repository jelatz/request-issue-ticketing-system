<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import Modal from '../../../Components/Modal.vue';

defineProps({
    systems: {
        type: Array,
        default: () => [],
    },
    engineers: {
        type: Array,
        default: () => [],
    },
});

// Create Modal
const showCreateModal = ref(false);
const createForm = useForm({
    name: '',
    code: '',
    category: '',
    description: '',
    default_lead_engineer_id: '',
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
    createForm.post('/admin/systems', {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
        },
    });
}

// Edit Modal
const showEditModal = ref(false);
const editingSystem = ref(null);
const editForm = useForm({
    name: '',
    code: '',
    category: '',
    description: '',
    default_lead_engineer_id: '',
    is_active: true,
});

function openEditModal(system) {
    editingSystem.value = system;
    editForm.clearErrors();
    editForm.name = system.name || '';
    editForm.code = system.code || '';
    editForm.category = system.category || '';
    editForm.description = system.description || '';
    editForm.default_lead_engineer_id = system.default_lead_engineer_id
        ? String(system.default_lead_engineer_id)
        : '';
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

    editForm.put(`/admin/systems/${editingSystem.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            editingSystem.value = null;
            editForm.reset();
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
            code: system.code,
            category: system.category || '',
            description: system.description || '',
            default_lead_engineer_id: system.default_lead_engineer_id || null,
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
                        <span class="font-medium text-slate-700 tabular-nums">{{ systems.length }}</span> configured systems for ticket categorization and default lead routing.
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
                                <th class="px-4 py-2.5 text-left font-medium">Code</th>
                                <th class="px-4 py-2.5 text-left font-medium">Category</th>
                                <th class="px-4 py-2.5 text-left font-medium">Default lead engineer</th>
                                <th class="px-4 py-2.5 text-right font-medium">Tickets</th>
                                <th class="px-4 py-2.5 text-left font-medium">Status</th>
                                <th class="px-4 py-2.5 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            <tr v-if="systems.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-xs text-slate-500">
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

                                <td class="px-4 py-3 whitespace-nowrap align-top">
                                    <span class="font-mono text-xs font-medium text-slate-700 bg-slate-100 px-2 py-0.5 rounded border border-slate-200">
                                        {{ system.code }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap align-top text-slate-600">
                                    {{ system.category || 'Uncategorized' }}
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap align-top">
                                    <div v-if="system.default_lead_engineer">
                                        <div class="font-medium text-slate-800">
                                            {{ system.default_lead_engineer.name }}
                                        </div>
                                        <div class="font-mono text-xs text-slate-500">
                                            @{{ system.default_lead_engineer.username }}
                                        </div>
                                    </div>
                                    <span v-else class="text-xs text-slate-500">
                                        Unassigned (Triage queue)
                                    </span>
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
            <form class="space-y-4" @submit.prevent="submitCreate">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            System name
                        </label>
                        <input
                            v-model="createForm.name"
                            type="text"
                            required
                            placeholder="e.g. CallTek Core CRM"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="createForm.errors.name" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.name }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Short code
                        </label>
                        <input
                            v-model="createForm.code"
                            type="text"
                            required
                            maxlength="50"
                            placeholder="e.g. CRM"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="createForm.errors.code" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.code }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Category
                        </label>
                        <input
                            v-model="createForm.category"
                            type="text"
                            placeholder="e.g. Operations Platform"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="createForm.errors.category" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.category }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Default lead engineer
                        </label>
                        <select
                            v-model="createForm.default_lead_engineer_id"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        >
                            <option value="">Unassigned (Triage queue)</option>
                            <option
                                v-for="engineer in engineers"
                                :key="engineer.id"
                                :value="String(engineer.id)"
                            >
                                {{ engineer.name }} (@{{ engineer.username }})
                            </option>
                        </select>
                        <p v-if="createForm.errors.default_lead_engineer_id" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.default_lead_engineer_id }}
                        </p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">
                        Description
                    </label>
                    <textarea
                        v-model="createForm.description"
                        rows="3"
                        placeholder="Scope of this internal system, key workflows, and routing notes..."
                        class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    />
                    <p v-if="createForm.errors.description" class="mt-1 text-xs text-rose-600">
                        {{ createForm.errors.description }}
                    </p>
                </div>

                <div class="pt-1">
                    <label class="inline-flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                        <input
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
            <form class="space-y-4" @submit.prevent="submitEdit">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            System name
                        </label>
                        <input
                            v-model="editForm.name"
                            type="text"
                            required
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="editForm.errors.name" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.name }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Short code
                        </label>
                        <input
                            v-model="editForm.code"
                            type="text"
                            required
                            maxlength="50"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="editForm.errors.code" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.code }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Category
                        </label>
                        <input
                            v-model="editForm.category"
                            type="text"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="editForm.errors.category" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.category }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Default lead engineer
                        </label>
                        <select
                            v-model="editForm.default_lead_engineer_id"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        >
                            <option value="">Unassigned (Triage queue)</option>
                            <option
                                v-for="engineer in engineers"
                                :key="engineer.id"
                                :value="String(engineer.id)"
                            >
                                {{ engineer.name }} (@{{ engineer.username }})
                            </option>
                        </select>
                        <p v-if="editForm.errors.default_lead_engineer_id" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.default_lead_engineer_id }}
                        </p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">
                        Description
                    </label>
                    <textarea
                        v-model="editForm.description"
                        rows="3"
                        class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                    />
                    <p v-if="editForm.errors.description" class="mt-1 text-xs text-rose-600">
                        {{ editForm.errors.description }}
                    </p>
                </div>

                <div class="pt-1">
                    <label class="inline-flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                        <input
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
