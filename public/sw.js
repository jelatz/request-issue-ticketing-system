self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let payload = {};
    if (event.data) {
        try {
            payload = event.data.json() ?? {};
        } catch {
            payload = { body: event.data.text() };
        }
    }

    const title = payload.title || 'CallTek Desk';
    const body = payload.body || '';
    const tag = payload.tag || undefined;
    const data = payload.data && typeof payload.data === 'object' ? payload.data : {};

    event.waitUntil(
        (async () => {
            await self.registration.showNotification(title, {
                body,
                tag,
                data,
            });

            const clients = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });

            for (const client of clients) {
                client.postMessage({
                    type: 'PUSH_NOTIFICATION_RECEIVED',
                    notificationId: data.notification_id ?? null,
                    title,
                    body,
                    url: data.url ?? '/notifications',
                });
            }
        })()
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const rawUrl = event.notification.data?.url || '/notifications';
    let targetUrl;
    try {
        const parsed = new URL(rawUrl, self.location.origin);
        targetUrl =
            parsed.origin === self.location.origin
                ? parsed
                : new URL('/notifications', self.location.origin);
    } catch {
        targetUrl = new URL('/notifications', self.location.origin);
    }

    event.waitUntil(
        (async () => {
            const clients = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });

            let existingClient = null;
            for (const client of clients) {
                try {
                    if (new URL(client.url).origin === self.location.origin) {
                        existingClient = client;
                        break;
                    }
                } catch {
                    // Ignore malformed client URLs.
                }
            }

            if (existingClient) {
                await existingClient.focus();
                existingClient.postMessage({
                    type: 'PUSH_NOTIFICATION_NAVIGATE',
                    url: `${targetUrl.pathname}${targetUrl.search}`,
                });
                return;
            }

            await self.clients.openWindow(targetUrl.href);
        })()
    );
});
