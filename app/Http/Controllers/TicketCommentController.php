<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TicketCommentController extends Controller
{
    public function store(
        Request $request,
        Ticket $ticket,
        TicketService $ticketService,
        NotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();

        if (!Ticket::visibleTo($user)->whereKey($ticket->id)->exists()) {
            abort(403, 'You do not have permission to comment on this ticket.');
        }

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'is_internal' => ['sometimes', 'boolean'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $isInternal = $user->isStaff() ? $request->boolean('is_internal') : false;

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $validated['body'],
            'is_internal' => $isInternal,
        ]);

        foreach ($request->file('attachments', []) as $file) {
            $storedPath = $file->store("tickets/{$ticket->id}/comments/{$comment->id}", 'public');

            TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'ticket_comment_id' => $comment->id,
                'uploaded_by_id' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $storedPath,
                'mime_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize() ?? 0,
                'is_internal' => $isInternal,
            ]);
        }

        if ($user->isStaff()) {
            $ticketService->markFirstResponseIfNeeded($ticket, $user);
        }

        $ticket->loadMissing(['requester', 'leadEngineer', 'collaborators']);
        $actionUrl = route('tickets.show', $ticket, false);

        if ($isInternal) {
            $staffRecipients = collect([$ticket->leadEngineer])
                ->merge($ticket->collaborators)
                ->filter(fn ($recipient) => $recipient instanceof User && $recipient->isStaff());

            $notificationService->notifyUsers(
                $staffRecipients,
                $ticket,
                "Internal Note on {$ticket->ticket_number}",
                "{$user->name} added an internal engineering note on \"{$ticket->title}\".",
                $actionUrl,
                $user->id
            );
        } else {
            $ticketService->recordActivity(
                $ticket,
                $user,
                'comment_added',
                "{$user->name} added a comment."
            );

            $recipients = collect([$ticket->leadEngineer])
                ->merge($ticket->collaborators);

            if ($ticket->requester) {
                $recipients->push($ticket->requester);
            }

            $notificationService->notifyUsers(
                $recipients,
                $ticket,
                "New Comment on {$ticket->ticket_number}",
                "{$user->name} commented on \"{$ticket->title}\".",
                $actionUrl,
                $user->id
            );
        }

        return back()->with('success', $isInternal ? 'Internal engineering note posted.' : 'Comment posted.');
    }
}
