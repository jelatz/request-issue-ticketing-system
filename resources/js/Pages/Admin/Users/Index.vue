<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import RoleBadge from '../../../Components/RoleBadge.vue';
import Pagination from '../../../Components/Pagination.vue';
import Modal from '../../../Components/Modal.vue';

const props = defineProps({
    users: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user || null);
const totalCount = computed(() => props.users?.total ?? props.users?.data?.length ?? 0);

const filterForm = reactive({
    search: props.filters.search || '',
    role: props.filters.role || '',
    is_active:
        props.filters.is_active !== undefined && props.filters.is_active !== null
            ? String(props.filters.is_active)
            : '',
});

const hasActiveFilters = computed(() => {
    return Boolean(filterForm.search || filterForm.role || filterForm.is_active !== '');
});

let searchDebounceTimer = null;

function applyFilters() {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
    }

    const params = {};
    if (filterForm.search) {
        params.search = filterForm.search;
    }
    if (filterForm.role) {
        params.role = filterForm.role;
    }
    if (filterForm.is_active !== '') {
        params.is_active = filterForm.is_active;
    }

    router.get('/admin/users', params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function onSearchInput() {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }
    searchDebounceTimer = setTimeout(() => {
        applyFilters();
    }, 300);
}

function resetFilters() {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = null;
    }

    filterForm.search = '';
    filterForm.role = '';
    filterForm.is_active = '';

    router.get('/admin/users', {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

onBeforeUnmount(() => {
    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }
});

// Create Modal
const showCreateModal = ref(false);
const createForm = useForm({
    name: '',
    username: '',
    email: '',
    password: '',
    role: 'user',
    department: '',
    job_title: '',
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
    createForm.post('/admin/users', {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset();
        },
    });
}

// Edit Modal
const showEditModal = ref(false);
const editingUser = ref(null);
const editForm = useForm({
    name: '',
    username: '',
    email: '',
    password: '',
    role: 'user',
    department: '',
    job_title: '',
    is_active: true,
});

const isEditingSelf = computed(
    () => Boolean(editingUser.value && currentUser.value && editingUser.value.id === currentUser.value.id)
);

function openEditModal(userRow) {
    editingUser.value = userRow;
    editForm.clearErrors();
    editForm.name = userRow.name || '';
    editForm.username = userRow.username || '';
    editForm.email = userRow.email || '';
    editForm.password = '';
    editForm.role = userRow.role || 'user';
    editForm.department = userRow.department || '';
    editForm.job_title = userRow.job_title || '';
    editForm.is_active = Boolean(userRow.is_active);
    showEditModal.value = true;
}

function closeEditModal() {
    showEditModal.value = false;
    editingUser.value = null;
}

function submitEdit() {
    if (!editingUser.value) {
        return;
    }

    editForm.put(`/admin/users/${editingUser.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            editingUser.value = null;
            editForm.reset();
        },
    });
}

// Quick Activate / Deactivate
const togglingUserId = ref(null);

function toggleUserStatus(userRow) {
    if (currentUser.value && userRow.id === currentUser.value.id) {
        return;
    }

    togglingUserId.value = userRow.id;
    router.put(
        `/admin/users/${userRow.id}`,
        {
            name: userRow.name,
            username: userRow.username,
            email: userRow.email,
            role: userRow.role,
            department: userRow.department || '',
            job_title: userRow.job_title || '',
            is_active: !userRow.is_active,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingUserId.value = null;
            },
        }
    );
}
</script>

<template>
    <Head title="Users" />

    <AppLayout title="Users">
        <div class="space-y-4">
            <!-- Compact Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                        Users
                    </h1>
                    <p class="mt-0.5 text-xs text-slate-500">
                        <span class="font-medium text-slate-700 tabular-nums">{{ totalCount }}</span> accounts across requesters, engineers, and administrators.
                    </p>
                </div>

                <div class="shrink-0">
                    <button
                        type="button"
                        class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-md transition-colors"
                        @click="openCreateModal"
                    >
                        + Create user
                    </button>
                </div>
            </div>

            <!-- Unified Directory Sheet -->
            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                <!-- Inline Filter Bar -->
                <form
                    class="bg-slate-50/60 border-b border-slate-200 px-4 py-2.5"
                    @submit.prevent="applyFilters"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <input
                            v-model="filterForm.search"
                            type="text"
                            aria-label="Search user accounts"
                            placeholder="Search name, username, email, or department..."
                            class="w-full sm:w-64 lg:w-72 px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-md text-slate-800 placeholder:text-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            @input="onSearchInput"
                        />

                        <select
                            v-model="filterForm.role"
                            aria-label="Filter by role"
                            class="px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-md text-slate-700 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            @change="applyFilters"
                        >
                            <option value="">All roles</option>
                            <option value="user">Requester / User</option>
                            <option value="engineer">Engineer</option>
                            <option value="superadmin">Superadmin</option>
                        </select>

                        <select
                            v-model="filterForm.is_active"
                            aria-label="Filter by status"
                            class="px-2.5 py-1.5 text-xs bg-white border border-slate-200 rounded-md text-slate-700 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                            @change="applyFilters"
                        >
                            <option value="">All statuses</option>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>

                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            class="ml-auto px-2 py-1 text-xs font-medium text-slate-500 hover:text-slate-900 transition-colors"
                            @click="resetFilters"
                        >
                            Reset
                        </button>
                    </div>
                </form>

                <!-- Directory Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50/60 text-slate-500 font-medium border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-medium">Name &amp; Username</th>
                                <th class="px-4 py-2.5 text-left font-medium">Email</th>
                                <th class="px-4 py-2.5 text-left font-medium">Role</th>
                                <th class="px-4 py-2.5 text-left font-medium">Department &amp; Title</th>
                                <th class="px-4 py-2.5 text-left font-medium">Tickets</th>
                                <th class="px-4 py-2.5 text-left font-medium">Status</th>
                                <th class="px-4 py-2.5 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 bg-white">
                            <tr v-if="!users.data || users.data.length === 0">
                                <td colspan="7" class="px-4 py-12 text-center text-xs text-slate-500">
                                    No user accounts match the current filters.
                                </td>
                            </tr>
                            <tr
                                v-for="userRow in users.data"
                                :key="userRow.id"
                                class="hover:bg-slate-50/70 transition-colors"
                            >
                                <td class="px-4 py-3 align-top">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-sm font-medium text-slate-900">
                                            {{ userRow.name }}
                                        </span>
                                        <span
                                            v-if="currentUser && userRow.id === currentUser.id"
                                            class="text-[11px] font-medium px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200"
                                        >
                                            You
                                        </span>
                                    </div>
                                    <div class="font-mono text-xs text-slate-500 mt-0.5">
                                        @{{ userRow.username }}
                                    </div>
                                </td>

                                <td class="px-4 py-3 align-top text-slate-600">
                                    {{ userRow.email }}
                                </td>

                                <td class="px-4 py-3 align-top whitespace-nowrap">
                                    <RoleBadge :role="userRow.role" :bordered="false" />
                                </td>

                                <td class="px-4 py-3 align-top">
                                    <div class="text-slate-800 font-medium">
                                        {{ userRow.department || 'Unassigned' }}
                                    </div>
                                    <div class="text-slate-500 mt-0.5">
                                        {{ userRow.job_title || 'No job title' }}
                                    </div>
                                </td>

                                <td class="px-4 py-3 align-top text-slate-600 tabular-nums whitespace-nowrap">
                                    <span class="font-medium text-slate-800">{{ userRow.submitted_tickets_count ?? 0 }}</span> submitted
                                    <span class="mx-1 text-slate-300">/</span>
                                    <span class="font-medium text-slate-800">{{ userRow.led_tickets_count ?? 0 }}</span> led
                                </td>

                                <td class="px-4 py-3 align-top whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-700">
                                        <span
                                            class="w-1.5 h-1.5 rounded-full"
                                            :class="userRow.is_active ? 'bg-emerald-500' : 'bg-slate-400'"
                                        />
                                        <span :class="userRow.is_active ? 'text-slate-800' : 'text-slate-500'">
                                            {{ userRow.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </span>
                                </td>

                                <td class="px-4 py-3 align-top whitespace-nowrap text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            type="button"
                                            class="px-2 py-1 text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded transition-colors"
                                            @click="openEditModal(userRow)"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="(currentUser && userRow.id === currentUser.id) || togglingUserId === userRow.id"
                                            class="px-2 py-1 text-xs font-medium rounded transition-colors"
                                            :class="
                                                currentUser && userRow.id === currentUser.id
                                                    ? 'text-slate-300 cursor-not-allowed'
                                                    : userRow.is_active
                                                      ? 'text-rose-600 hover:text-rose-700 hover:bg-rose-50'
                                                      : 'text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50'
                                            "
                                            @click="toggleUserStatus(userRow)"
                                        >
                                            {{ userRow.is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer -->
                <Pagination
                    :links="users.links || []"
                    :from="users.from"
                    :to="users.to"
                    :total="users.total"
                />
            </div>
        </div>

        <!-- Create Account Modal -->
        <Modal
            :show="showCreateModal"
            title="Create user account"
            max-width="max-w-xl"
            @close="closeCreateModal"
        >
            <form class="space-y-4" @submit.prevent="submitCreate">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Full name
                        </label>
                        <input
                            v-model="createForm.name"
                            type="text"
                            required
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="createForm.errors.name" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.name }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Username
                        </label>
                        <input
                            v-model="createForm.username"
                            type="text"
                            required
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="createForm.errors.username" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.username }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Email address
                        </label>
                        <input
                            v-model="createForm.email"
                            type="email"
                            required
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="createForm.errors.email" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.email }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Initial password
                        </label>
                        <input
                            v-model="createForm.password"
                            type="password"
                            required
                            minlength="6"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="createForm.errors.password" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.password }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Role
                        </label>
                        <select
                            v-model="createForm.role"
                            required
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        >
                            <option value="user">Requester / User</option>
                            <option value="engineer">Engineer</option>
                            <option value="superadmin">Superadmin</option>
                        </select>
                        <p v-if="createForm.errors.role" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.role }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Department
                        </label>
                        <input
                            v-model="createForm.department"
                            type="text"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="createForm.errors.department" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.department }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Job title
                        </label>
                        <input
                            v-model="createForm.job_title"
                            type="text"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="createForm.errors.job_title" class="mt-1 text-xs text-rose-600">
                            {{ createForm.errors.job_title }}
                        </p>
                    </div>
                </div>

                <div class="pt-1">
                    <label class="inline-flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                        <input
                            v-model="createForm.is_active"
                            type="checkbox"
                            class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        <span>Account is active and permitted to sign in</span>
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
                        {{ createForm.processing ? 'Creating...' : 'Create user' }}
                    </button>
                </div>
            </form>
        </Modal>

        <!-- Edit Account Modal -->
        <Modal
            :show="showEditModal"
            title="Edit user account"
            max-width="max-w-xl"
            @close="closeEditModal"
        >
            <form class="space-y-4" @submit.prevent="submitEdit">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Full name
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
                            Username
                        </label>
                        <input
                            v-model="editForm.username"
                            type="text"
                            required
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="editForm.errors.username" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.username }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Email address
                        </label>
                        <input
                            v-model="editForm.email"
                            type="email"
                            required
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="editForm.errors.email" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.email }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            New password (optional)
                        </label>
                        <input
                            v-model="editForm.password"
                            type="password"
                            minlength="6"
                            placeholder="Leave blank to keep current"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="editForm.errors.password" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.password }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Role
                        </label>
                        <select
                            v-model="editForm.role"
                            required
                            :disabled="isEditingSelf"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-slate-100 disabled:text-slate-500"
                        >
                            <option value="user">Requester / User</option>
                            <option value="engineer">Engineer</option>
                            <option value="superadmin">Superadmin</option>
                        </select>
                        <p v-if="editForm.errors.role" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.role }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Department
                        </label>
                        <input
                            v-model="editForm.department"
                            type="text"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="editForm.errors.department" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.department }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">
                            Job title
                        </label>
                        <input
                            v-model="editForm.job_title"
                            type="text"
                            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                        />
                        <p v-if="editForm.errors.job_title" class="mt-1 text-xs text-rose-600">
                            {{ editForm.errors.job_title }}
                        </p>
                    </div>
                </div>

                <div class="pt-1 space-y-1">
                    <label class="inline-flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                        <input
                            v-model="editForm.is_active"
                            type="checkbox"
                            :disabled="isEditingSelf"
                            class="rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50"
                        />
                        <span>Account is active and permitted to sign in</span>
                    </label>
                    <p v-if="isEditingSelf" class="text-xs text-slate-500">
                        You cannot change the role or deactivate your own Superadmin account.
                    </p>
                    <p v-if="editForm.errors.is_active" class="text-xs text-rose-600">
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
