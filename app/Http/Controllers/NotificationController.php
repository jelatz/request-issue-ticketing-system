<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->with('ticket:id,ticket_number,title')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, UserNotification $notification): RedirectResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to modify this notification.');
        }

        if (!$notification->is_read) {
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        if ($request->boolean('redirect')) {
            $actionUrl = $notification->action_url;
            if (is_string($actionUrl) && $actionUrl !== '') {
                $appUrl = rtrim((string) config('app.url'), '/');
                $isInternalPath = str_starts_with($actionUrl, '/') && !str_starts_with($actionUrl, '//');
                $isSameOriginUrl = $appUrl !== '' && str_starts_with($actionUrl, $appUrl . '/');

                if ($isInternalPath || $isSameOriginUrl) {
                    return redirect($actionUrl);
                }
            }

            if ($notification->ticket_id) {
                return redirect()->route('tickets.show', $notification->ticket_id);
            }
        }

        return back()->with('success', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
