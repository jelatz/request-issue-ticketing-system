<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketSubtask;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TicketSubtaskController extends Controller
{
    public function store(
        Request $request,
        Ticket $ticket,
        TicketService $ticketService
    ): RedirectResponse {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assigned_engineer_id' => ['nullable', 'exists:users,id'],
        ]);

        $user = $request->user();

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
        if ($subtask->ticket_id !== $ticket->id) {
            abort(404);
        }

        $user = $request->user();
        $nextCompleted = !$subtask->is_completed;

        $subtask->update([
            'is_completed' => $nextCompleted,
            'completed_at' => $nextCompleted ? now() : null,
        ]);

        $ticketService->markFirstResponseIfNeeded($ticket, $user);
        $ticketService->recordActivity(
            $ticket,
            $user,
            'subtask_toggled',
            ($nextCompleted ? 'Completed' : 'Reopened') . " subtask: {$subtask->title}",
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
        if ($subtask->ticket_id !== $ticket->id) {
            abort(404);
        }

        $title = $subtask->title;
        $subtask->delete();

        $ticketService->recordActivity(
            $ticket,
            $request->user(),
            'subtask_removed',
            "Removed subtask: {$title}",
            $title,
            null
        );

        return back()->with('success', 'Subtask removed.');
    }
}
