<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketSequence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketService
{
    /**
     * Generate the next sequential ticket number for the given ticket type and current year.
     */
    public function generateTicketNumber(string $type): string
    {
        $prefix = $type === Ticket::TYPE_ISSUE ? 'ISS' : 'REQ';
        $year = (int) now()->format('Y');

        return DB::transaction(function () use ($prefix, $year): string {
            $sequence = TicketSequence::query()
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                $initialMax = $this->findMaxTicketSequence($prefix, $year);

                TicketSequence::query()->insertOrIgnore([
                    'prefix' => $prefix,
                    'year' => $year,
                    'last_number' => $initialMax,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $sequence = TicketSequence::query()
                    ->where('prefix', $prefix)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            TicketSequence::query()
                ->whereKey($sequence->id)
                ->increment('last_number');

            $nextNumber = (int) TicketSequence::query()
                ->whereKey($sequence->id)
                ->value('last_number');

            return sprintf('%s-%04d-%04d', $prefix, $year, $nextNumber);
        }, 3);
    }

    /**
     * Find the highest existing numeric ticket suffix for a prefix and year.
     */
    private function findMaxTicketSequence(string $prefix, int $year): int
    {
        $basePrefix = sprintf('%s-%04d-', $prefix, $year);

        $existingNumbers = Ticket::query()
            ->where('ticket_number', 'like', "{$basePrefix}%")
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

        return $maxSequence;
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
