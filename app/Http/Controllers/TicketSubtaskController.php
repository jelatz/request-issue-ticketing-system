<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketSubtask;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketSubtaskController extends Controller
{
    public function store(
        Request $request,
        Ticket $ticket,
        TicketService $ticketService
    ): RedirectResponse {
        $user = $request->user();
        $this->authorizeSubtaskAccess($ticket, $user);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assigned_engineer_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->where('role', User::ROLE_ENGINEER)
                ),
            ],
        ]);

        $subtask = TicketSubtask::create([
            'ticket_id' => $ticket->id,
            'title' => $validated['title'],
            'assigned_engineer_id' => $validated['assigned_engineer_id'] ?? null,
            'is_completed' => false,
            'created_by_id' => $user->id,
        ]);

        $ticketService->markFirstResponseIfNeeded($ticket, $user);
        $ticketService->recordActivity(
            $ticket,
            $user,
            'subtask_added',
            "Added subtask: {$subtask->title}",
            null,
            $subtask->title
        );

        return back()->with('success', 'Subtask added.');
    }

    public function toggle(
        Request $request,
        Ticket $ticket,
        TicketSubtask $subtask,
        TicketService $ticketService
    ): RedirectResponse {
        $user = $request->user();
        $this->authorizeSubtaskAccess($ticket, $user);

        if ($subtask->ticket_id !== $ticket->id) {
            abort(404);
        }

        $nextCompleted = ! $subtask->is_completed;

        $subtask->update([
            'is_completed' => $nextCompleted,
            'completed_at' => $nextCompleted ? now() : null,
        ]);

        $ticketService->markFirstResponseIfNeeded($ticket, $user);
        $ticketService->recordActivity(
            $ticket,
            $user,
            'subtask_toggled',
            ($nextCompleted ? 'Completed' : 'Reopened')." subtask: {$subtask->title}",
            $nextCompleted ? 'pending' : 'completed',
            $nextCompleted ? 'completed' : 'pending'
        );

        return back()->with('success', 'Subtask status updated.');
    }

    public function destroy(
        Request $request,
        Ticket $ticket,
        TicketSubtask $subtask,
        TicketService $ticketService
    ): RedirectResponse {
        $user = $request->user();
        $this->authorizeSubtaskAccess($ticket, $user);

        if ($subtask->ticket_id !== $ticket->id) {
            abort(404);
        }

        $title = $subtask->title;
        $subtask->delete();

        $ticketService->markFirstResponseIfNeeded($ticket, $user);
        $ticketService->recordActivity(
            $ticket,
            $user,
            'subtask_removed',
            "Removed subtask: {$title}",
            $title,
            null
        );

        return back()->with('success', 'Subtask removed.');
    }

    private function authorizeSubtaskAccess(Ticket $ticket, User $user): void
    {
        abort_unless(
            $ticket->canBeWorkedOnBy($user),
            403,
            'You do not have permission to manage subtasks on this ticket.'
        );

        abort_if(
            $ticket->isPendingApproval(),
            403,
            'Subtasks cannot be managed until this engineering request is approved.'
        );
    }
}
