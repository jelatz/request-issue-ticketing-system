<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Pagination from '../../Components/Pagination.vue';
import { formatDateTime } from '../../utils/formatters';

const props = defineProps({
    notifications: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const unreadCount = computed(() => page.props.auth?.unread_notifications_count ?? 0);
const totalCount = computed(() => props.notifications?.total ?? props.notifications?.data?.length ?? 0);

const markAllProcessing = ref(false);
const busyNotificationId = ref(null);

function handleMarkAllRead() {
    if (unreadCount.value === 0 || markAllProcessing.value) {
        return;
    }

    markAllProcessing.value = true;
    router.post(
        '/notifications/read-all',
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                markAllProcessing.value = false;
            },
        }
    );
}

function handleMarkRead(notification) {
    if (notification.is_read || busyNotificationId.value === notification.id) {
        return;
    }

    busyNotificationId.value = notification.id;
    router.patch(
        `/notifications/${notification.id}/read`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busyNotificationId.value = null;
            },
        }
    );
}

function openNotification(notification) {
    if (busyNotificationId.value === notification.id) {
        return;
    }

    if (notification.action_url) {
        busyNotificationId.value = notification.id;
        router.patch(
            `/notifications/${notification.id}/read?redirect=1`,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    busyNotificationId.value = null;
                },
            }
        );
        return;
    }

    if (notification.ticket_id) {
        if (!notification.is_read) {
            busyNotificationId.value = notification.id;
            router.patch(
                `/notifications/${notification.id}/read`,
                {},
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        router.visit(`/tickets/${notification.ticket_id}`);
                    },
                    onFinish: () => {
                        busyNotificationId.value = null;
                    },
                }
            );
        } else {
            router.visit(`/tickets/${notification.ticket_id}`);
        }
    }
}
</script>

<template>
    <Head title="Notifications" />

    <AppLayout title="Notifications">
        <div class="space-y-4">
            <!-- Compact Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                        Notifications
                    </h1>
                    <p class="mt-0.5 text-xs text-slate-500">
                        <span class="font-medium text-slate-700 tabular-nums">{{ unreadCount }}</span> unread
                        <span class="mx-1 text-slate-300">/</span>
                        <span class="tabular-nums">{{ totalCount }}</span> total
                    </p>
                </div>

                <div v-if="unreadCount > 0" class="shrink-0">
                    <button
                        type="button"
                        :disabled="markAllProcessing"
                        class="px-3 py-1.5 text-xs font-medium text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 hover:border-slate-300 rounded-md transition-colors disabled:opacity-50"
                        @click="handleMarkAllRead"
                    >
                        {{ markAllProcessing ? 'Marking all...' : 'Mark all as read' }}
                    </button>
                </div>
            </div>

            <!-- Unified White Inbox List -->
            <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
                <div
                    v-if="!notifications.data || notifications.data.length === 0"
                    class="px-6 py-12 text-center"
                >
                    <div class="text-sm font-medium text-slate-800">
                        No notifications
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Ticket assignments, comments, and status updates directed to you will appear here.
                    </p>
                </div>

                <ul v-else class="divide-y divide-slate-200">
                    <li
                        v-for="notification in notifications.data"
                        :key="notification.id"
                        class="px-4 py-3 transition-colors hover:bg-slate-50/70"
                        :class="!notification.is_read ? 'bg-blue-50/20' : 'bg-white'"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex items-start gap-3 min-w-0 flex-1">
                                <span
                                    class="w-1.5 h-1.5 rounded-full mt-1.5 shrink-0"
                                    :class="
                                        !notification.is_read
                                            ? 'bg-blue-600'
                                            : 'bg-transparent border border-slate-300'
                                    "
                                />

                                <div
                                    class="min-w-0 flex-1 space-y-1"
                                    :class="
                                        notification.action_url || notification.ticket_id
                                            ? 'cursor-pointer'
                                            : ''
                                    "
                                    @click="
                                        (notification.action_url || notification.ticket_id) &&
                                            openNotification(notification)
                                    "
                                >
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <span
                                            class="text-sm"
                                            :class="
                                                !notification.is_read
                                                    ? 'font-semibold text-slate-900'
                                                    : 'font-medium text-slate-700'
                                            "
                                        >
                                            {{ notification.title }}
                                        </span>

                                        <span
                                            v-if="notification.ticket?.ticket_number"
                                            class="font-mono text-xs text-slate-500"
                                        >
                                            {{ notification.ticket.ticket_number }}
                                        </span>
                                        <span
                                            v-else-if="notification.ticket_id"
                                            class="font-mono text-xs text-slate-500"
                                        >
                                            #{{ notification.ticket_id }}
                                        </span>
                                    </div>

                                    <p class="text-xs text-slate-600 leading-relaxed">
                                        {{ notification.message }}
                                    </p>

                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] font-mono text-slate-400 tabular-nums">
                                        <span>{{ formatDateTime(notification.created_at) }}</span>
                                        <span v-if="notification.is_read && notification.read_at">
                                            Read {{ formatDateTime(notification.read_at) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-1 shrink-0 pt-0.5">
                                <button
                                    v-if="notification.action_url || notification.ticket_id"
                                    type="button"
                                    :disabled="busyNotificationId === notification.id"
                                    class="px-2 py-1 text-xs font-medium text-blue-600 hover:text-blue-700 hover:bg-blue-50/70 rounded transition-colors disabled:opacity-50"
                                    @click="openNotification(notification)"
                                >
                                    Open
                                </button>

                                <button
                                    v-if="!notification.is_read"
                                    type="button"
                                    :disabled="busyNotificationId === notification.id"
                                    class="px-2 py-1 text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded transition-colors disabled:opacity-50"
                                    @click="handleMarkRead(notification)"
                                >
                                    Mark read
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>

                <Pagination
                    :links="notifications.links || []"
                    :from="notifications.from"
                    :to="notifications.to"
                    :total="notifications.total"
                />
            </div>
        </div>
    </AppLayout>
</template>
