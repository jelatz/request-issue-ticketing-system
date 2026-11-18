<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketActivity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $activeStatuses = [
            Ticket::STATUS_OPEN,
            Ticket::STATUS_UNDER_REVIEW,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_WAITING_ON_USER,
        ];

        $baseQuery = Ticket::visibleTo($user);

        $metrics = [
            'total_visible' => (clone $baseQuery)->count(),
            'open_count' => (clone $baseQuery)->where('status', Ticket::STATUS_OPEN)->count(),
            'under_review_count' => (clone $baseQuery)->where('status', Ticket::STATUS_UNDER_REVIEW)->count(),
            'in_progress_count' => (clone $baseQuery)->where('status', Ticket::STATUS_IN_PROGRESS)->count(),
            'waiting_on_user_count' => (clone $baseQuery)->where('status', Ticket::STATUS_WAITING_ON_USER)->count(),
            'resolved_count' => (clone $baseQuery)->where('status', Ticket::STATUS_RESOLVED)->count(),
            'closed_count' => (clone $baseQuery)->where('status', Ticket::STATUS_CLOSED)->count(),
            'critical_high_active_count' => (clone $baseQuery)
                ->whereIn('status', $activeStatuses)
                ->whereIn('priority', [Ticket::PRIORITY_CRITICAL, Ticket::PRIORITY_HIGH])
                ->count(),
            'issues_count' => (clone $baseQuery)->where('type', Ticket::TYPE_ISSUE)->count(),
            'engineering_requests_count' => (clone $baseQuery)->where('type', Ticket::TYPE_ENGINEERING_REQUEST)->count(),
            'overdue_count' => (clone $baseQuery)
                ->whereIn('status', $activeStatuses)
                ->whereNotNull('target_due_date')
                ->whereDate('target_due_date', '<', today())
                ->count(),
        ];

        $recentTickets = (clone $baseQuery)
            ->with(['internalSystem', 'requester', 'leadEngineer'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $priorityOrderSql = "CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END ASC";

        if ($user->isStaff()) {
            $attentionTickets = (clone $baseQuery)
                ->with(['internalSystem', 'requester', 'leadEngineer'])
                ->where(function ($query) use ($user, $activeStatuses): void {
                    $query->where(function ($assigned) use ($user, $activeStatuses): void {
                        $assigned->whereIn('status', $activeStatuses)
                            ->where(function ($roleQuery) use ($user): void {
                                $roleQuery->where('lead_engineer_id', $user->id)
                                    ->orWhereHas('collaborators', function ($collabQuery) use ($user): void {
                                        $collabQuery->where('users.id', $user->id);
                                    });
                            });
                    });

                    if ($user->isSuperAdmin()) {
                        $query->orWhere(function ($unassigned): void {
                            $unassigned->where('status', Ticket::STATUS_OPEN)
                                ->whereNull('lead_engineer_id');
                        });
                    }
                })
                ->orderByRaw($priorityOrderSql)
                ->orderBy('created_at')
                ->limit(10)
                ->get();
        } else {
            $attentionTickets = (clone $baseQuery)
                ->with(['internalSystem', 'requester', 'leadEngineer'])
                ->whereIn('status', [
                    Ticket::STATUS_WAITING_ON_USER,
                    Ticket::STATUS_RESOLVED,
                    Ticket::STATUS_OPEN,
                ])
                ->orderByRaw("CASE status WHEN 'waiting_on_user' THEN 1 WHEN 'resolved' THEN 2 WHEN 'open' THEN 3 ELSE 4 END ASC")
                ->orderByRaw($priorityOrderSql)
                ->orderBy('created_at')
                ->limit(10)
                ->get();
        }

        $recentActivities = TicketActivity::query()
            ->whereHas('ticket', function ($query) use ($user): void {
                $query->visibleTo($user);
            })
            ->with(['user', 'ticket:id,ticket_number,title'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return Inertia::render('Dashboard/Index', [
            'metrics' => $metrics,
            'recent_tickets' => $recentTickets,
            'attention_tickets' => $attentionTickets,
            'recent_activities' => $recentActivities,
        ]);
    }
}
