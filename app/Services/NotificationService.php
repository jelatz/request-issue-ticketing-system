<?php

namespace App\Services;

use App\Mail\TicketNotificationMail;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Create an in-app notification for a user and attempt email delivery safely.
     */
    public function notifyUser(
        User $recipient,
        ?Ticket $ticket,
        string $title,
        string $message,
        ?string $actionUrl = null
    ): UserNotification {
        $notification = UserNotification::create([
            'user_id' => $recipient->id,
            'ticket_id' => $ticket?->id,
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'is_read' => false,
        ]);

        if (!empty($recipient->email)) {
            try {
                Mail::to($recipient->email)->send(new TicketNotificationMail($notification, $ticket));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send ticket notification email.', [
                    'notification_id' => $notification->id,
                    'recipient_id' => $recipient->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $notification;
    }

    /**
     * Notify a deduplicated list of active recipients, optionally excluding one user.
     *
     * @param iterable<int, User|null> $recipients
     */
    public function notifyUsers(
        iterable $recipients,
        ?Ticket $ticket,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?int $excludeUserId = null
    ): void {
        $seenUserIds = [];

        foreach ($recipients as $recipient) {
            if (!$recipient instanceof User) {
                continue;
            }

            if ($excludeUserId !== null && $recipient->id === $excludeUserId) {
                continue;
            }

            if (!$recipient->is_active) {
                continue;
            }

            if (isset($seenUserIds[$recipient->id])) {
                continue;
            }

            $seenUserIds[$recipient->id] = true;

            $this->notifyUser($recipient, $ticket, $title, $message, $actionUrl);
        }
    }
}
