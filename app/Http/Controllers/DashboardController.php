<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $todayDate = today()->toDateString();

        $metricsRow = (clone $baseQuery)
            ->toBase()
            ->selectRaw(
                'COUNT(*) as total_visible,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_approval_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as open_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as under_review_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as in_progress_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as waiting_on_user_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as resolved_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as closed_count,
                 SUM(CASE WHEN status IN (?, ?, ?, ?) AND priority IN (?, ?) THEN 1 ELSE 0 END) as critical_high_active_count,
                 SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as issues_count,
                 SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as engineering_requests_count,
                 SUM(CASE WHEN status IN (?, ?, ?, ?) AND target_due_date IS NOT NULL AND target_due_date < ? THEN 1 ELSE 0 END) as overdue_count',
                [
                    Ticket::STATUS_PENDING_APPROVAL,
                    Ticket::STATUS_OPEN,
                    Ticket::STATUS_UNDER_REVIEW,
                    Ticket::STATUS_IN_PROGRESS,
                    Ticket::STATUS_WAITING_ON_USER,
                    Ticket::STATUS_RESOLVED,
                    Ticket::STATUS_CLOSED,
                    ...$activeStatuses,
                    Ticket::PRIORITY_CRITICAL,
                    Ticket::PRIORITY_HIGH,
                    Ticket::TYPE_ISSUE,
                    Ticket::TYPE_ENGINEERING_REQUEST,
                    ...$activeStatuses,
                    $todayDate,
                ]
            )
            ->first();

        $metrics = [
            'total_visible' => (int) ($metricsRow->total_visible ?? 0),
            'pending_approval_count' => (int) ($metricsRow->pending_approval_count ?? 0),
            'open_count' => (int) ($metricsRow->open_count ?? 0),
            'under_review_count' => (int) ($metricsRow->under_review_count ?? 0),
            'in_progress_count' => (int) ($metricsRow->in_progress_count ?? 0),
            'waiting_on_user_count' => (int) ($metricsRow->waiting_on_user_count ?? 0),
            'resolved_count' => (int) ($metricsRow->resolved_count ?? 0),
            'closed_count' => (int) ($metricsRow->closed_count ?? 0),
            'critical_high_active_count' => (int) ($metricsRow->critical_high_active_count ?? 0),
            'issues_count' => (int) ($metricsRow->issues_count ?? 0),
            'engineering_requests_count' => (int) ($metricsRow->engineering_requests_count ?? 0),
            'overdue_count' => (int) ($metricsRow->overdue_count ?? 0),
        ];

        if ($user->canApproveEngineeringRequests()) {
            Cache::forever(Ticket::PENDING_APPROVALS_CACHE_KEY, $metrics['pending_approval_count']);
        }

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

                    if ($user->canApproveEngineeringRequests()) {
                        $query->orWhere('status', Ticket::STATUS_PENDING_APPROVAL);
                    }
                })
                ->orderByRaw($priorityOrderSql)
                ->orderBy('created_at')
                ->limit(10)
                ->get();
        } else {
            $statusOrderSql = $user->isDirector()
                ? "CASE status WHEN 'pending_approval' THEN 1 WHEN 'waiting_on_user' THEN 2 WHEN 'resolved' THEN 3 WHEN 'open' THEN 4 ELSE 5 END ASC"
                : "CASE status WHEN 'waiting_on_user' THEN 1 WHEN 'resolved' THEN 2 WHEN 'open' THEN 3 WHEN 'pending_approval' THEN 4 ELSE 5 END ASC";

            $attentionTickets = (clone $baseQuery)
                ->with(['internalSystem', 'requester', 'leadEngineer'])
                ->whereIn('status', [
                    Ticket::STATUS_PENDING_APPROVAL,
                    Ticket::STATUS_WAITING_ON_USER,
                    Ticket::STATUS_RESOLVED,
                    Ticket::STATUS_OPEN,
                ])
                ->orderByRaw($statusOrderSql)
                ->orderByRaw($priorityOrderSql)
                ->orderBy('created_at')
                ->limit(10)
                ->get();
        }

        $recentActivitiesQuery = TicketActivity::query()
            ->with(['user', 'ticket:id,ticket_number,title'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10);

        if (! $user->isSuperAdmin()) {
            $recentActivitiesQuery->whereIn(
                'ticket_id',
                Ticket::visibleTo($user)->select('tickets.id')
            );
        }

        $recentActivities = $recentActivitiesQuery->get();

        return Inertia::render('Dashboard/Index', [
            'metrics' => $metrics,
            'recent_tickets' => $recentTickets,
            'attention_tickets' => $attentionTickets,
            'recent_activities' => $recentActivities,
        ]);
    }
}
