<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\UserNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TicketWebPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public UserNotification $userNotification,
        public ?Ticket $ticket = null,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * Get the WebPush representation of the notification.
     */
    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->userNotification->title)
            ->body($this->userNotification->message)
            ->tag("ticket-notif-{$this->userNotification->id}")
            ->data([
                'url' => $this->resolveActionUrl(),
                'notification_id' => $this->userNotification->id,
                'ticket_id' => $this->userNotification->ticket_id,
            ]);
    }

    /**
     * Resolve a safe internal action URL for the notification.
     */
    public function resolveActionUrl(): string
    {
        if (
            is_string($this->userNotification->action_url)
            && str_starts_with($this->userNotification->action_url, '/')
            && ! str_starts_with($this->userNotification->action_url, '//')
        ) {
            return $this->userNotification->action_url;
        }

        if ($this->userNotification->ticket_id !== null) {
            return "/tickets/{$this->userNotification->ticket_id}";
        }

        return '/notifications';
    }
}
