import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

function checkSupport() {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window &&
        window.isSecureContext
    );
}

const isSupported = ref(checkSupport());
const permission = ref(
    typeof Notification !== 'undefined' ? Notification.permission : 'unsupported'
);
const isSubscribed = ref(false);
const isBusy = ref(false);
const isTesting = ref(false);
const liveUnreadCount = ref(null);
const liveRecentNotifications = ref(null);

let registrationPromise = null;
let swListenerAttached = false;
let syncedEndpoint = null;
let isFetchingSummary = false;
let activeMountCount = 0;
let syncIntervalId = null;
let visibilityListenerAttached = false;
let activeInertiaVisits = 0;
let pendingSummaryRefresh = false;
let removeRouterStartListener = null;
let removeRouterFinishListener = null;

function getCsrfHeaders() {
    if (typeof document === 'undefined') {
        return {};
    }

    const cookies = document.cookie ? document.cookie.split('; ') : [];
    for (const cookie of cookies) {
        if (cookie.startsWith('XSRF-TOKEN=')) {
            const rawToken = cookie.slice('XSRF-TOKEN='.length);
            try {
                const decoded = decodeURIComponent(rawToken);
                if (decoded) {
                    return { 'X-XSRF-TOKEN': decoded };
                }
            } catch {
                // Ignore malformed cookie encoding and fall back to meta token.
            }
        }
    }

    const metaToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content');
    if (metaToken) {
        return { 'X-CSRF-TOKEN': metaToken };
    }

    return {};
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; i += 1) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
}

async function requestJson(url, method, payload = null, timeoutMs = 10_000) {
    let response;
    try {
        response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(payload !== null ? { 'Content-Type': 'application/json' } : {}),
                ...getCsrfHeaders(),
            },
            ...(payload !== null ? { body: JSON.stringify(payload) } : {}),
            signal: AbortSignal.timeout(timeoutMs),
        });
    } catch (err) {
        if (err?.name === 'TimeoutError' || err?.name === 'AbortError') {
            throw new Error('The push subscription request timed out.', { cause: err });
        }
        throw new Error('Network error while communicating with the server.', { cause: err });
    }

    let data = null;
    try {
        data = await response.json();
    } catch {
        data = null;
    }

    if (!response.ok) {
        throw new Error(data?.message || `Server returned HTTP ${response.status}.`);
    }

    return data;
}

async function getRegistration() {
    if (!isSupported.value) {
        return null;
    }

    if (!registrationPromise) {
        registrationPromise = navigator.serviceWorker.register('/sw.js').catch((err) => {
            registrationPromise = null;
            throw new Error('Unable to register notification service worker.', { cause: err });
        });
    }

    return registrationPromise;
}

function buildSubscriptionPayload(subscription) {
    const subJson = subscription.toJSON();
    const endpoint = subJson?.endpoint || subscription.endpoint;
    const p256dh = subJson?.keys?.p256dh;
    const auth = subJson?.keys?.auth;

    if (!endpoint || !p256dh || !auth) {
        throw new Error('Browser push subscription did not provide required encryption keys.');
    }

    const supportedEncodings =
        typeof PushManager !== 'undefined' && Array.isArray(PushManager.supportedContentEncodings)
            ? PushManager.supportedContentEncodings
            : [];
    const preferredEncoding = supportedEncodings[0] ?? 'aes128gcm';
    const contentEncoding =
        preferredEncoding === 'aesgcm' || preferredEncoding === 'aes128gcm'
            ? preferredEncoding
            : 'aes128gcm';

    return {
        endpoint,
        keys: {
            p256dh,
            auth,
        },
        contentEncoding,
    };
}

async function syncSubscriptionWithServer(subscription) {
    const payload = buildSubscriptionPayload(subscription);
    await requestJson('/push-subscriptions', 'POST', payload);
    syncedEndpoint = payload.endpoint;
}

function attachServiceWorkerListener() {
    if (
        swListenerAttached ||
        typeof navigator === 'undefined' ||
        !('serviceWorker' in navigator)
    ) {
        return;
    }

    swListenerAttached = true;
    navigator.serviceWorker.addEventListener('message', (event) => {
        const messageType = event.data?.type;
        if (messageType === 'PUSH_NOTIFICATION_RECEIVED') {
            refreshSummary({ deferIfNavigating: true });
            return;
        }

        if (messageType === 'PUSH_NOTIFICATION_NAVIGATE') {
            const targetUrl = event.data?.url;
            if (
                typeof targetUrl === 'string' &&
                targetUrl.startsWith('/') &&
                !targetUrl.startsWith('//')
            ) {
                router.visit(targetUrl);
            }
        }
    });
}

function syncFromPageProps(unread, recent) {
    liveUnreadCount.value = typeof unread === 'number' ? unread : 0;
    liveRecentNotifications.value = Array.isArray(recent) ? recent : [];
}

async function refreshSummary({ deferIfNavigating = false } = {}) {
    if (typeof window === 'undefined') {
        return;
    }

    if (activeInertiaVisits > 0) {
        if (deferIfNavigating) {
            pendingSummaryRefresh = true;
        }
        return;
    }

    if (isFetchingSummary) {
        return;
    }

    isFetchingSummary = true;
    try {
        const response = await fetch('/notifications/summary', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: AbortSignal.timeout(10_000),
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json();
        if (typeof data?.unread_count === 'number') {
            liveUnreadCount.value = data.unread_count;
        }
        if (Array.isArray(data?.recent_notifications)) {
            liveRecentNotifications.value = data.recent_notifications;
        }
    } catch (err) {
        if (err?.name === 'TimeoutError' || err?.name === 'AbortError') {
            return;
        }
        // Silently ignore background polling network failures.
    } finally {
        isFetchingSummary = false;
    }
}

async function init(vapidPublicKey) {
    attachServiceWorkerListener();
    isSupported.value = checkSupport();
    permission.value =
        typeof Notification !== 'undefined' ? Notification.permission : 'unsupported';

    if (!isSupported.value || !vapidPublicKey) {
        isSubscribed.value = false;
        return;
    }

    try {
        const registration = await getRegistration();
        if (!registration) {
            isSubscribed.value = false;
            return;
        }

        const subscription = await registration.pushManager.getSubscription();
        if (subscription && Notification.permission === 'granted') {
            isSubscribed.value = true;
            if (syncedEndpoint !== subscription.endpoint) {
                await syncSubscriptionWithServer(subscription);
            }
        } else {
            isSubscribed.value = false;
        }
    } catch {
        // Keep initialization non-blocking if browser or server sync is unavailable.
    }
}

function handleVisibilityChange() {
    if (document.visibilityState === 'visible') {
        refreshSummary();
    }
}

function startLiveSync() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    if (activeMountCount === 0) {
        syncIntervalId = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                refreshSummary();
            }
        }, 30_000);

        if (!visibilityListenerAttached) {
            document.addEventListener('visibilitychange', handleVisibilityChange);
            visibilityListenerAttached = true;
        }

        removeRouterStartListener = router.on('start', (event) => {
            if (!event.detail?.visit?.prefetch) {
                activeInertiaVisits += 1;
            }
        });

        removeRouterFinishListener = router.on('finish', (event) => {
            if (!event.detail?.visit?.prefetch) {
                activeInertiaVisits = Math.max(0, activeInertiaVisits - 1);
                if (activeInertiaVisits === 0 && pendingSummaryRefresh) {
                    pendingSummaryRefresh = false;
                    refreshSummary();
                }
            }
        });
    }

    activeMountCount += 1;
}

function stopLiveSync() {
    if (activeMountCount > 0) {
        activeMountCount -= 1;
    }

    if (activeMountCount === 0) {
        if (syncIntervalId !== null) {
            clearInterval(syncIntervalId);
            syncIntervalId = null;
        }
        if (visibilityListenerAttached && typeof document !== 'undefined') {
            document.removeEventListener('visibilitychange', handleVisibilityChange);
            visibilityListenerAttached = false;
        }
        if (removeRouterStartListener) {
            removeRouterStartListener();
            removeRouterStartListener = null;
        }
        if (removeRouterFinishListener) {
            removeRouterFinishListener();
            removeRouterFinishListener = null;
        }
        activeInertiaVisits = 0;
        pendingSummaryRefresh = false;
    }
}

async function enableNotifications(vapidPublicKey) {
    if (isBusy.value) {
        return { ok: false, message: 'Desktop notification update is already in progress.' };
    }

    isSupported.value = checkSupport();
    if (!isSupported.value) {
        return {
            ok: false,
            message: 'Desktop notifications are not supported in this browser context.',
        };
    }

    if (!vapidPublicKey) {
        return {
            ok: false,
            message: 'Desktop notifications are not configured on the server.',
        };
    }

    isBusy.value = true;
    try {
        const result = await Notification.requestPermission();
        permission.value = result;

        if (result !== 'granted') {
            isSubscribed.value = false;
            return {
                ok: false,
                message:
                    result === 'denied'
                        ? 'Browser permission was denied for desktop notifications.'
                        : 'Desktop notification permission was dismissed.',
            };
        }

        const registration = await getRegistration();
        if (!registration) {
            return {
                ok: false,
                message: 'Notification service worker could not be registered.',
            };
        }

        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });
        }

        await syncSubscriptionWithServer(subscription);
        isSubscribed.value = true;

        return { ok: true, message: 'Desktop notifications enabled.' };
    } catch (err) {
        if (err?.name === 'TimeoutError' || err?.name === 'AbortError') {
            return {
                ok: false,
                message: 'Timed out while enabling desktop notifications. Please try again.',
            };
        }

        return {
            ok: false,
            message: err?.message || 'Unable to enable desktop notifications.',
        };
    } finally {
        isBusy.value = false;
    }
}

async function disableNotifications() {
    if (isBusy.value) {
        return { ok: false, message: 'Desktop notification update is already in progress.' };
    }

    if (!isSupported.value) {
        isSubscribed.value = false;
        syncedEndpoint = null;
        return { ok: true, message: 'Desktop notifications disabled.' };
    }

    isBusy.value = true;
    try {
        const registration = registrationPromise
            ? await registrationPromise
            : await navigator.serviceWorker.getRegistration('/sw.js');
        const subscription = registration
            ? await registration.pushManager.getSubscription()
            : null;

        if (subscription) {
            await requestJson('/push-subscriptions', 'DELETE', {
                endpoint: subscription.endpoint,
            });
            await subscription.unsubscribe();
        }

        isSubscribed.value = false;
        syncedEndpoint = null;

        return { ok: true, message: 'Desktop notifications disabled.' };
    } catch (err) {
        if (err?.name === 'TimeoutError' || err?.name === 'AbortError') {
            return {
                ok: false,
                message: 'Timed out while disabling desktop notifications. Please try again.',
            };
        }

        return {
            ok: false,
            message: err?.message || 'Unable to disable desktop notifications.',
        };
    } finally {
        isBusy.value = false;
    }
}

async function unsubscribeBeforeLogout() {
    if (!isSupported.value) {
        isSubscribed.value = false;
        syncedEndpoint = null;
        return;
    }

    try {
        const registration = registrationPromise
            ? await registrationPromise
            : await navigator.serviceWorker.getRegistration('/sw.js');
        const subscription = registration
            ? await registration.pushManager.getSubscription()
            : null;

        if (subscription) {
            try {
                await requestJson(
                    '/push-subscriptions',
                    'DELETE',
                    { endpoint: subscription.endpoint },
                    3_000
                );
            } catch {
                // Best-effort server cleanup; never block logout.
            }

            try {
                await subscription.unsubscribe();
            } catch {
                // Best-effort browser cleanup; never block logout.
            }
        }
    } catch {
        // Never block logout on push cleanup failure.
    } finally {
        isSubscribed.value = false;
        syncedEndpoint = null;
    }
}

async function sendTestNotification() {
    if (isTesting.value) {
        return { ok: false, message: 'A test notification is already being sent.' };
    }

    if (!isSubscribed.value) {
        return { ok: false, message: 'Enable desktop alerts on this browser first.' };
    }

    isTesting.value = true;
    try {
        const data = await requestJson('/push-subscriptions/test', 'POST', {});

        return {
            ok: true,
            message: data?.message || 'Test notification sent. Check your desktop alerts.',
        };
    } catch (err) {
        if (err?.name === 'TimeoutError' || err?.name === 'AbortError') {
            return {
                ok: false,
                message: 'Timed out while sending test notification. Please try again.',
            };
        }

        return {
            ok: false,
            message: err?.message || 'Unable to send test notification.',
        };
    } finally {
        isTesting.value = false;
    }
}

export function useWebPush() {
    return {
        isSupported,
        permission,
        isSubscribed,
        isBusy,
        isTesting,
        liveUnreadCount,
        liveRecentNotifications,
        syncFromPageProps,
        refreshSummary,
        init,
        startLiveSync,
        stopLiveSync,
        enableNotifications,
        disableNotifications,
        sendTestNotification,
        unsubscribeBeforeLogout,
    };
}
