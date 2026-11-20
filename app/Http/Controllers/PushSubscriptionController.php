<?php

namespace App\Http\Controllers;

use App\Notifications\TestWebPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use Throwable;

class PushSubscriptionController extends Controller
{
    /**
     * Store or update the authenticated user's push subscription.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding?: string|null} $validated */
        $validated = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:1024'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? 'aes128gcm',
        );

        return response()->json(['subscribed' => true]);
    }

    /**
     * Remove the authenticated user's push subscription for the given endpoint.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var array{endpoint: string} $validated */
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
        ]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['subscribed' => false]);
    }

    /**
     * Send an immediate test web push notification to the authenticated user's subscriptions.
     */
    public function sendTest(Request $request): JsonResponse
    {
        if (! filled(config('webpush.vapid.public_key')) || ! filled(config('webpush.vapid.private_key'))) {
            return response()->json([
                'message' => 'Desktop push notifications are not configured on the server.',
            ], 422);
        }

        $user = $request->user();

        if (! $user->pushSubscriptions()->exists()) {
            return response()->json([
                'message' => 'Enable desktop alerts on this browser first.',
            ], 422);
        }

        $sentCount = 0;
        /** @var list<string> $failedReasons */
        $failedReasons = [];
        $listening = true;

        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$listening, &$sentCount, $user): void {
            if (! $listening) {
                return;
            }

            if ((int) $event->subscription->subscribable_id !== $user->id) {
                return;
            }

            $sentCount++;
        });

        Event::listen(NotificationFailed::class, function (NotificationFailed $event) use (&$listening, &$failedReasons, $user): void {
            if (! $listening) {
                return;
            }

            if ((int) $event->subscription->subscribable_id !== $user->id) {
                return;
            }

            $failedReasons[] = $event->report->getReason();
        });

        try {
            $user->notifyNow(new TestWebPushNotification);
        } catch (Throwable $exception) {
            Log::warning('Failed to send test web push notification.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send test notification. Check server logs and VAPID/OpenSSL configuration.',
            ], 500);
        } finally {
            $listening = false;
        }

        if ($failedReasons !== [] && $sentCount === 0) {
            Log::warning('Test web push notification delivery failed.', [
                'user_id' => $user->id,
                'reasons' => $failedReasons,
            ]);

            return response()->json([
                'message' => 'Push service rejected or failed to deliver the test alert. Check server logs and SSL configuration.',
            ], 502);
        }

        return response()->json([
            'sent' => true,
            'message' => 'Test notification sent. Check your desktop alerts.',
        ]);
    }
}
