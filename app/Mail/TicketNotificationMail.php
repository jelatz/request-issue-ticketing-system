<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\UserNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public UserNotification $notification,
        public ?Ticket $ticket = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->notification->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-notification',
            with: [
                'notification' => $this->notification,
                'ticket' => $this->ticket,
                'actionUrl' => $this->notification->action_url
                    ? url($this->notification->action_url)
                    : null,
            ],
        );
    }
}
