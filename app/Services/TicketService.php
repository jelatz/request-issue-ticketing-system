<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Support\Str;

class TicketService
{
    /**
     * Generate the next sequential ticket number for the given ticket type and current year.
     */
    public function generateTicketNumber(string $type): string
    {
        $prefix = $type === Ticket::TYPE_ISSUE ? 'ISS' : 'REQ';
        $year = now()->format('Y');
        $basePrefix = "{$prefix}-{$year}-";

        $existingNumbers = Ticket::query()
            ->where('ticket_number', 'like', $basePrefix . '%')
            ->pluck('ticket_number');

        $maxSequence = 0;

        foreach ($existingNumbers as $number) {
            $suffix = substr($number, strlen($basePrefix));
            if (ctype_digit($suffix)) {
                $seq = (int) $suffix;
                if ($seq > $maxSequence) {
                    $maxSequence = $seq;
                }
            }
        }

        return sprintf('%s%04d', $basePrefix, $maxSequence + 1);
    }

    /**
     * Record an audit activity entry on a ticket.
     */
    public function recordActivity(
        Ticket $ticket,
        User $actor,
        string $actionType,
        string $description,
        ?string $oldValue = null,
        ?string $newValue = null
    ): TicketActivity {
        return TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'action_type' => $actionType,
            'old_value' => $oldValue !== null ? Str::limit($oldValue, 250, '') : null,
            'new_value' => $newValue !== null ? Str::limit($newValue, 250, '') : null,
            'description' => Str::limit($description, 250, '...'),
        ]);
    }

    /**
     * Stamp first_responded_at when a staff member (other than the requester) first acts on the ticket.
     */
    public function markFirstResponseIfNeeded(Ticket $ticket, User $actor): void
    {
        if (
            $actor->isStaff()
            && $ticket->requester_id !== $actor->id
            && $ticket->first_responded_at === null
        ) {
            $ticket->first_responded_at = now();
            $ticket->save();
        }
    }
}
