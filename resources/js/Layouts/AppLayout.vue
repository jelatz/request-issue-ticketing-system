<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import NewTicketModal from '../Components/NewTicketModal.vue';
import RoleBadge from '../Components/RoleBadge.vue';
import { useFeedback } from '../composables/useFeedback';
import { useNewTicketModal } from '../composables/useNewTicketModal';
import { useWebPush } from '../composables/useWebPush';
import { formatDateTime } from '../utils/formatters';

defineProps({
    title: {
        type: String,
        default: '',
    },
});

const page = usePage();
const { isNewTicketModalOpen, closeNewTicketModal } = useNewTicketModal();
const { toasts, notifySuccess, notifyError, dismissToast, markFlashProcessed } = useFeedback();
const {
    isSupported,
    permission,
    isSubscribed,
    isBusy: webPushBusy,
    liveUnreadCount,
    liveRecentNotifications,
    syncFromPageProps,
    init: initWebPush,
    startLiveSync,
    stopLiveSync,
    enableNotifications,
    disableNotifications,
    unsubscribeBeforeLogout,
} = useWebPush();

const user = computed(() => page.props.auth?.user || null);
const pendingApprovalsCount = computed(() => page.props.auth?.pending_approvals_count ?? 0);
const vapidPublicKey = computed(() => page.props.webpush?.vapid_public_key || null);
const unreadCount = computed(
    () => liveUnreadCount.value ?? page.props.auth?.unread_notifications_count ?? 0
);
const recentNotifications = computed(
    () => liveRecentNotifications.value ?? page.props.auth?.recent_notifications ?? []
);
const flashSuccess = computed(() => page.props.flash?.success || null);
const flashError = computed(() => page.props.flash?.error || null);
const pageErrors = computed(() => page.props.errors || {});
const validationErrorList = computed(() =>
    Object.values(pageErrors.value).filter((v) => typeof v === 'string' && v.length > 0)
);
const firstValidationError = computed(() => validationErrorList.value[0] || null);
const validationErrorCount = computed(() => validationErrorList.value.length);

const notificationsOpen = ref(false);
const mobileMenuOpen = ref(false);
const notificationDropdownRef = ref(null);
const searchInputRef = ref(null);
const searchQuery = ref(page.props.filters?.search || '');

let removeHttpExceptionListener = null;
let removeNetworkErrorListener = null;
let isMounted = false;
let liveSyncStarted = false;

watch(
    () => [
        page.props.auth?.unread_notifications_count,
        page.props.auth?.recent_notifications,
    ],
    ([unread, recent]) => {
        syncFromPageProps(unread, recent);
    },
    { immediate: true }
);

function syncWebPushState() {
    if (user.value) {
        initWebPush(vapidPublicKey.value);
        if (!liveSyncStarted) {
            startLiveSync();
            liveSyncStarted = true;
        }
    } else if (liveSyncStarted) {
        stopLiveSync();
        liveSyncStarted = false;
    }
}

watch(
    () => [user.value?.id, vapidPublicKey.value],
    () => {
        if (isMounted) {
            syncWebPushState();
        }
    }
);

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

watch(
    () => page.props.filters?.search,
    (newSearch) => {
        searchQuery.value = newSearch || '';
    }
);

const currentPath = computed(() => {
    const url = page.url || '/';
    return url.split('?')[0];
});

const isApprovalsView = computed(
    () => currentPath.value === '/tickets' && page.props.filters?.status === 'pending_approval'
);

const navigationItems = computed(() => {
    const items = [
        {
            label: 'Dashboard',
            href: '/dashboard',
            group: 'Workspace',
            isActive: currentPath.value === '/dashboard',
        },
        {
            label: 'Tickets',
            href: '/tickets',
            group: 'Workspace',
            isActive:
                (currentPath.value === '/tickets' && !isApprovalsView.value) ||
                (currentPath.value.startsWith('/tickets/') &&
                    currentPath.value !== '/tickets/kanban'),
        },
    ];

    if (user.value?.can_approve_requests) {
        items.push({
            label: 'Approvals',
            href: '/tickets?status=pending_approval',
            group: 'Workspace',
            isActive: isApprovalsView.value,
            badge: pendingApprovalsCount.value,
        });
    }

    if (user.value?.is_staff) {
        items.push(
            {
                label: 'Kanban Board',
                href: '/tickets/kanban',
                group: 'Engineering',
                isActive: currentPath.value === '/tickets/kanban',
            },
            {
                label: 'Reports',
                href: '/reports',
                group: 'Engineering',
                isActive: currentPath.value.startsWith('/reports'),
            }
        );
    }

    if (user.value?.is_super_admin) {
        items.push(
            {
                label: 'Systems',
                href: '/admin/systems',
                group: 'Administration',
                isActive: currentPath.value.startsWith('/admin/systems'),
            },
            {
                label: 'Users',
                href: '/admin/users',
                group: 'Administration',
                isActive: currentPath.value.startsWith('/admin/users'),
            }
        );
    }

    return items;
});

const groupedNavigation = computed(() => {
    const groups = [];
    const map = {};

    for (const item of navigationItems.value) {
        if (!map[item.group]) {
            map[item.group] = { name: item.group, items: [] };
            groups.push(map[item.group]);
        }
        map[item.group].items.push(item);
    }

    return groups;
});

function handleQuickSearch() {
    const trimmed = searchQuery.value.trim();
    router.get('/tickets', trimmed ? { search: trimmed } : {});
}

function handleMarkAllRead() {
    router.post(
        '/notifications/read-all',
        {},
        {
            preserveScroll: true,
        }
    );
}

function handleNotificationClick(notification) {
    notificationsOpen.value = false;
    router.patch(
        `/notifications/${notification.id}/read?redirect=1`,
        {},
        {
            preserveScroll: true,
        }
    );
}

async function handleLogout() {
    await unsubscribeBeforeLogout();
    if (liveSyncStarted) {
        stopLiveSync();
        liveSyncStarted = false;
    }
    router.post('/logout');
}

async function handleToggleDesktopAlerts() {
    const result = isSubscribed.value
        ? await disableNotifications()
        : await enableNotifications(vapidPublicKey.value);

    if (result.ok) {
        notifySuccess(result.message);
    } else {
        notifyError(result.message);
    }
}

function handleOutsideClick(event) {
    if (
        notificationsOpen.value &&
        notificationDropdownRef.value &&
        !notificationDropdownRef.value.contains(event.target)
    ) {
        notificationsOpen.value = false;
    }
}

function handleGlobalKeydown(event) {
    if (isNewTicketModalOpen.value) {
        return;
    }

    if (event.key === 'Escape') {
        notificationsOpen.value = false;
        mobileMenuOpen.value = false;
        return;
    }

    if (
        event.key === '/' &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.altKey
    ) {
        const active = document.activeElement;
        const tagName = active?.tagName?.toUpperCase();
        if (
            tagName === 'INPUT' ||
            tagName === 'TEXTAREA' ||
            tagName === 'SELECT' ||
            active?.isContentEditable
        ) {
            return;
        }
        event.preventDefault();
        searchInputRef.value?.focus();
    }
}

onMounted(() => {
    isMounted = true;
    document.addEventListener('click', handleOutsideClick);
    window.addEventListener('keydown', handleGlobalKeydown);

    if (user.value) {
        syncWebPushState();
    }

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
    isMounted = false;
    document.removeEventListener('click', handleOutsideClick);
    window.removeEventListener('keydown', handleGlobalKeydown);
    removeHttpExceptionListener?.();
    removeNetworkErrorListener?.();
    if (liveSyncStarted) {
        stopLiveSync();
        liveSyncStarted = false;
    }
});
</script>

<template>
    <div class="min-h-screen bg-[#F8FAFC] text-slate-900 flex flex-col lg:flex-row">
        <!-- Left Sidebar (Desktop) -->
        <aside class="hidden lg:flex lg:w-56 lg:flex-col lg:fixed lg:inset-y-0 bg-[#F8FAFC] border-r border-slate-200 z-30">
            <div class="h-12 border-b border-slate-200 px-4 flex items-center justify-between shrink-0">
                <Link href="/dashboard" class="min-w-0 block">
                    <div class="text-[13px] font-semibold tracking-tight text-slate-900 truncate leading-tight">
                        CallTek Desk
                    </div>
                    <div class="text-[11px] text-slate-500 truncate leading-tight">
                        Engineering Workspace
                    </div>
                </Link>
                <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0" title="Workspace online" />
            </div>

            <nav class="flex-1 px-2.5 py-4 space-y-5 overflow-y-auto">
                <div v-for="group in groupedNavigation" :key="group.name" class="space-y-0.5">
                    <div class="px-2.5 pb-1 text-[11px] font-medium text-slate-400">
                        {{ group.name }}
                    </div>
                    <Link
                        v-for="item in group.items"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-md text-[13px] transition-colors"
                        :class="
                            item.isActive
                                ? 'bg-blue-50/80 text-blue-700 font-medium'
                                : 'text-slate-600 hover:bg-slate-200/50 hover:text-slate-900'
                        "
                    >
                        <span>{{ item.label }}</span>
                        <span
                            v-if="item.badge > 0"
                            class="px-1.5 py-0.5 text-[10px] font-semibold font-mono rounded-full bg-indigo-100 text-indigo-700 tabular-nums"
                        >
                            {{ item.badge > 99 ? '99+' : item.badge }}
                        </span>
                    </Link>
                </div>
            </nav>

            <div v-if="user" class="border-t border-slate-200 px-4 py-3 text-[11px] text-slate-500 space-y-1.5 shrink-0">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-slate-900 truncate">
                        {{ user.name }}
                    </span>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-1.5 min-w-0">
                        <RoleBadge :role="user.role" />
                        <span
                            v-if="user.is_director"
                            class="text-[11px] px-1.5 py-0.5 rounded border border-indigo-200 bg-indigo-50/80 text-indigo-700 font-medium"
                        >
                            Director
                        </span>
                    </div>
                    <span v-if="user.department" class="truncate text-slate-400">
                        {{ user.department }}
                    </span>
                </div>
            </div>
        </aside>

        <!-- Mobile Slide-over Drawer -->
        <div v-if="mobileMenuOpen" class="fixed inset-0 z-50 lg:hidden">
            <div
                class="fixed inset-0 bg-slate-900/30 backdrop-blur-[1px]"
                @click="mobileMenuOpen = false"
            />
            <aside class="fixed inset-y-0 left-0 w-64 bg-[#F8FAFC] border-r border-slate-200 flex flex-col z-10 shadow-lg">
                <div class="h-12 border-b border-slate-200 px-4 flex items-center justify-between shrink-0">
                    <Link href="/dashboard" class="min-w-0 block" @click="mobileMenuOpen = false">
                        <div class="text-[13px] font-semibold tracking-tight text-slate-900 truncate leading-tight">
                            CallTek Desk
                        </div>
                        <div class="text-[11px] text-slate-500 truncate leading-tight">
                            Engineering Workspace
                        </div>
                    </Link>
                    <button
                        type="button"
                        class="px-2 py-1 text-xs font-medium text-slate-500 hover:text-slate-900 rounded hover:bg-slate-200/60"
                        @click="mobileMenuOpen = false"
                    >
                        Close
                    </button>
                </div>

                <nav class="flex-1 px-2.5 py-4 space-y-5 overflow-y-auto">
                    <div v-for="group in groupedNavigation" :key="group.name" class="space-y-0.5">
                        <div class="px-2.5 pb-1 text-[11px] font-medium text-slate-400">
                            {{ group.name }}
                        </div>
                        <Link
                            v-for="item in group.items"
                            :key="item.href"
                            :href="item.href"
                            class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-md text-[13px] transition-colors"
                            :class="
                                item.isActive
                                    ? 'bg-blue-50/80 text-blue-700 font-medium'
                                    : 'text-slate-600 hover:bg-slate-200/50 hover:text-slate-900'
                            "
                            @click="mobileMenuOpen = false"
                        >
                            <span>{{ item.label }}</span>
                            <span
                                v-if="item.badge > 0"
                                class="px-1.5 py-0.5 text-[10px] font-semibold font-mono rounded-full bg-indigo-100 text-indigo-700 tabular-nums"
                            >
                                {{ item.badge > 99 ? '99+' : item.badge }}
                            </span>
                        </Link>
                    </div>
                </nav>

                <div v-if="user" class="border-t border-slate-200 px-4 py-3 space-y-2.5 shrink-0">
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-slate-900 truncate">{{ user.name }}</div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <RoleBadge :role="user.role" />
                            <span
                                v-if="user.is_director"
                                class="text-[11px] px-1.5 py-0.5 rounded border border-indigo-200 bg-indigo-50/80 text-indigo-700 font-medium"
                            >
                                Director
                            </span>
                            <span v-if="user.department" class="text-[11px] text-slate-400 truncate">
                                {{ user.department }}
                            </span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="w-full px-3 py-1.5 text-xs font-medium text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 rounded-md transition-colors text-center"
                        @click="handleLogout"
                    >
                        Sign out
                    </button>
                </div>
            </aside>
        </div>

        <!-- Main Column -->
        <div class="flex-1 flex flex-col lg:pl-56 min-w-0">
            <!-- Top Utility Bar -->
            <header class="sticky top-0 z-20 h-12 bg-white border-b border-slate-200 px-4 lg:px-6 flex items-center justify-between gap-3 shrink-0">
                <div class="flex items-center gap-3 min-w-0 flex-1">
                    <button
                        type="button"
                        class="lg:hidden px-2.5 py-1 text-xs font-medium bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-md shrink-0"
                        @click="mobileMenuOpen = true"
                    >
                        Menu
                    </button>

                    <!-- Global Quick Search -->
                    <form class="relative flex items-center w-full max-w-md" @submit.prevent="handleQuickSearch">
                        <input
                            ref="searchInputRef"
                            v-model="searchQuery"
                            type="search"
                            aria-label="Search tickets"
                            placeholder="Search tickets by key, title, or system..."
                            class="w-full h-8 pl-3 pr-8 text-xs bg-slate-50 border border-slate-200 rounded-md text-slate-900 placeholder:text-slate-400 focus:outline-none focus:bg-white focus:border-blue-500 transition-colors"
                        >
                        <span
                            class="hidden md:inline-flex items-center font-mono text-[10px] text-slate-400 border border-slate-200 bg-slate-50 px-1.5 py-0.5 rounded pointer-events-none absolute right-2"
                        >
                            /
                        </span>
                    </form>
                </div>

                <div class="flex items-center gap-3 shrink-0">
                    <!-- Notification Center -->
                    <div ref="notificationDropdownRef" class="relative">
                        <button
                            type="button"
                            aria-label="Notifications"
                            title="Notifications"
                            :aria-expanded="notificationsOpen"
                            class="relative inline-flex items-center justify-center h-8 w-8 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 rounded-md transition-colors"
                            @click.stop="notificationsOpen = !notificationsOpen"
                        >
                            <svg
                                class="w-4 h-4"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.75"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                                <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                            </svg>
                            <span
                                v-if="unreadCount > 0"
                                class="absolute -top-1.5 -right-1.5 inline-flex items-center justify-center min-w-[1.125rem] h-[1.125rem] px-1 text-[10px] font-semibold font-mono rounded-full bg-blue-600 text-white tabular-nums ring-2 ring-white"
                            >
                                {{ unreadCount > 99 ? '99+' : unreadCount }}
                            </span>
                        </button>

                        <div
                            v-if="notificationsOpen"
                            class="absolute right-0 mt-1.5 w-80 sm:w-96 bg-white text-slate-900 border border-slate-200 rounded-lg shadow-lg overflow-hidden z-40"
                        >
                            <div class="px-4 py-3 border-b border-slate-200 bg-slate-50/70 flex items-center justify-between gap-2">
                                <div>
                                    <div class="text-xs font-semibold text-slate-900">
                                        Notifications
                                    </div>
                                    <div class="text-[11px] text-slate-500 tabular-nums">
                                        {{ unreadCount }} unread
                                    </div>
                                </div>
                                <button
                                    v-if="unreadCount > 0"
                                    type="button"
                                    class="text-xs font-medium text-blue-600 hover:text-blue-700"
                                    @click="handleMarkAllRead"
                                >
                                    Mark all read
                                </button>
                            </div>

                            <div class="max-h-80 overflow-y-auto divide-y divide-slate-100">
                                <div
                                    v-if="recentNotifications.length === 0"
                                    class="px-4 py-6 text-center text-xs text-slate-500"
                                >
                                    No recent notifications.
                                </div>

                                <button
                                    v-for="notification in recentNotifications"
                                    :key="notification.id"
                                    type="button"
                                    class="w-full text-left px-4 py-3 hover:bg-slate-50 transition-colors block"
                                    :class="!notification.is_read ? 'bg-blue-50/40' : 'bg-white'"
                                    @click="handleNotificationClick(notification)"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="text-xs font-medium text-slate-900">
                                            {{ notification.title }}
                                        </span>
                                        <span
                                            v-if="!notification.is_read"
                                            class="shrink-0 inline-flex items-center gap-1 text-[11px] font-medium text-blue-700"
                                        >
                                            <span class="w-1.5 h-1.5 rounded-full bg-blue-600" />
                                            New
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-600 line-clamp-2">
                                        {{ notification.message }}
                                    </p>
                                    <div class="mt-1 text-[11px] text-slate-400 tabular-nums">
                                        {{ formatDateTime(notification.created_at) }}
                                    </div>
                                </button>
                            </div>

                            <div class="px-4 py-2.5 border-t border-slate-200 bg-slate-50/70 flex items-center justify-between gap-2">
                                <div v-if="isSupported && vapidPublicKey" class="min-w-0">
                                    <span
                                        v-if="permission === 'denied'"
                                        class="text-[11px] text-slate-400"
                                    >
                                        Desktop alerts blocked in browser
                                    </span>
                                    <button
                                        v-else-if="isSubscribed"
                                        type="button"
                                        :disabled="webPushBusy"
                                        class="text-[11px] font-medium text-slate-600 hover:text-slate-900 disabled:opacity-50"
                                        @click="handleToggleDesktopAlerts"
                                    >
                                        Disable desktop alerts
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        :disabled="webPushBusy"
                                        class="text-[11px] font-medium text-blue-600 hover:text-blue-700 disabled:opacity-50"
                                        @click="handleToggleDesktopAlerts"
                                    >
                                        Enable desktop alerts
                                    </button>
                                </div>
                                <Link
                                    href="/notifications"
                                    class="text-xs font-medium text-blue-600 hover:text-blue-700 ml-auto"
                                    @click="notificationsOpen = false"
                                >
                                    View all
                                </Link>
                            </div>
                        </div>
                    </div>

                    <div v-if="user" class="hidden sm:block h-4 w-px bg-slate-200" />

                    <div v-if="user" class="hidden md:flex items-center gap-2">
                        <span class="text-xs font-medium text-slate-700 truncate max-w-[140px]">
                            {{ user.name }}
                        </span>
                        <RoleBadge :role="user.role" />
                        <span
                            v-if="user.is_director"
                            class="text-[11px] px-1.5 py-0.5 rounded border border-indigo-200 bg-indigo-50/80 text-indigo-700 font-medium"
                        >
                            Director
                        </span>
                    </div>

                    <button
                        v-if="user"
                        type="button"
                        class="hidden sm:inline-flex items-center px-2.5 py-1 text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-md transition-colors"
                        @click="handleLogout"
                    >
                        Sign out
                    </button>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6">
                <slot />
            </main>
        </div>

        <NewTicketModal :show="isNewTicketModalOpen" @close="closeNewTicketModal" />

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
