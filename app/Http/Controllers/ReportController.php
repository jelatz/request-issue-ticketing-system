<?php

namespace App\Http\Controllers;

use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\TicketSubtask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * @var list<string>
     */
    private const ACTIVE_STATUSES = [
        Ticket::STATUS_OPEN,
        Ticket::STATUS_UNDER_REVIEW,
        Ticket::STATUS_IN_PROGRESS,
        Ticket::STATUS_WAITING_ON_USER,
    ];

    /**
     * @var list<string>
     */
    private const RESOLVED_OR_CLOSED = [
        Ticket::STATUS_RESOLVED,
        Ticket::STATUS_CLOSED,
    ];

    public function index(Request $request): Response
    {
        $reportData = $this->buildReportData($request);

        return Inertia::render('Reports/Index', [
            'summary' => $reportData['summary'],
            'by_system' => $reportData['by_system'],
            'by_status' => $reportData['by_status'],
            'by_priority' => $reportData['by_priority'],
            'by_engineer' => $reportData['by_engineer'],
            'by_department' => $reportData['by_department'],
            'filters' => $request->only(['date_from', 'date_to', 'type', 'internal_system_id']),
            'systems' => InternalSystem::query()->orderByRaw('LOWER(name) ASC')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $section = $request->input('section', 'tickets');
        if (!in_array($section, ['tickets', 'systems', 'engineers', 'departments'], true)) {
            $section = 'tickets';
        }

        $reportData = $this->buildReportData($request);
        $filename = 'calltek-report-' . $section . '-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($section, $reportData): void {
            $handle = fopen('php://output', 'w');

            if ($section === 'systems') {
                fputcsv($handle, ['System Name', 'Total', 'Issues', 'Engineering Requests', 'Active', 'Resolved/Closed', 'Critical']);
                foreach ($reportData['by_system'] as $row) {
                    fputcsv($handle, [
                        $row['system_name'],
                        $row['total'],
                        $row['issues'],
                        $row['requests'],
                        $row['active'],
                        $row['resolved_closed'],
                        $row['critical'],
                    ]);
                }
            } elseif ($section === 'engineers') {
                fputcsv($handle, ['Engineer ID', 'Name', 'Department', 'Led Total', 'Collaborating Total', 'Active Led', 'Resolved/Closed Led', 'Avg Resolution Hours', 'Subtasks Completed']);
                foreach ($reportData['by_engineer'] as $row) {
                    fputcsv($handle, [
                        $row['id'],
                        $row['name'],
                        $row['department'] ?? '',
                        $row['led_total'],
                        $row['collaborating_total'],
                        $row['active_led'],
                        $row['resolved_closed_led'],
                        $row['avg_resolution_hours'] ?? 'N/A',
                        $row['subtasks_completed'],
                    ]);
                }
            } elseif ($section === 'departments') {
                fputcsv($handle, ['Department', 'Total', 'Issues', 'Engineering Requests', 'Resolved/Closed']);
                foreach ($reportData['by_department'] as $row) {
                    fputcsv($handle, [
                        $row['department'],
                        $row['total'],
                        $row['issues'],
                        $row['requests'],
                        $row['resolved_closed'],
                    ]);
                }
            } else {
                fputcsv($handle, [
                    'Ticket Number',
                    'Type',
                    'Title',
                    'System',
                    'Priority',
                    'Status',
                    'Requester',
                    'Department',
                    'Lead Engineer',
                    'Target Due Date',
                    'Created At',
                    'Resolved At',
                    'Closed At',
                ]);

                /** @var Collection<int, Ticket> $tickets */
                $tickets = $reportData['tickets'];
                foreach ($tickets as $ticket) {
                    fputcsv($handle, [
                        $ticket->ticket_number,
                        $ticket->type,
                        $ticket->title,
                        $ticket->internalSystem?->name ?? 'Unassigned / General',
                        $ticket->priority,
                        $ticket->status,
                        $ticket->requester?->name ?? '',
                        $ticket->requester?->department ?? '',
                        $ticket->leadEngineer?->name ?? 'Unassigned',
                        $ticket->target_due_date?->toDateString() ?? '',
                        $ticket->created_at?->toDateTimeString() ?? '',
                        $ticket->resolved_at?->toDateTimeString() ?? '',
                        $ticket->closed_at?->toDateTimeString() ?? '',
                    ]);
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReportData(Request $request): array
    {
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_values(array_filter([
                'nullable',
                'date_format:Y-m-d',
                $request->filled('date_from') ? 'after_or_equal:date_from' : null,
            ])),
            'type' => ['nullable', 'in:issue,engineering_request'],
            'internal_system_id' => ['nullable', 'integer', 'exists:internal_systems,id'],
        ]);

        $query = Ticket::visibleTo($request->user())
            ->with(['internalSystem', 'requester', 'leadEngineer', 'collaborators']);

        if ($dateFrom = $request->input('date_from')) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($systemId = $request->input('internal_system_id')) {
            $query->where('internal_system_id', $systemId);
        }

        $tickets = $query->orderByDesc('created_at')->get();

        $totalTickets = $tickets->count();
        $issuesCount = $tickets->where('type', Ticket::TYPE_ISSUE)->count();
        $requestsCount = $tickets->where('type', Ticket::TYPE_ENGINEERING_REQUEST)->count();
        $openActiveCount = $tickets->whereIn('status', self::ACTIVE_STATUSES)->count();
        $resolvedClosedCount = $tickets->whereIn('status', self::RESOLVED_OR_CLOSED)->count();

        $resolutionRate = $totalTickets > 0
            ? round(($resolvedClosedCount / $totalTickets) * 100, 1)
            : 0.0;

        $firstResponseHours = $tickets
            ->filter(fn (Ticket $t) => $t->first_responded_at !== null && $t->created_at !== null)
            ->map(fn (Ticket $t) => max(0, $t->created_at->diffInMinutes($t->first_responded_at)) / 60);

        $avgFirstResponseHours = $firstResponseHours->isNotEmpty()
            ? round($firstResponseHours->avg(), 1)
            : null;

        $resolutionHours = $tickets
            ->filter(fn (Ticket $t) => $t->resolved_at !== null && $t->created_at !== null)
            ->map(fn (Ticket $t) => max(0, $t->created_at->diffInMinutes($t->resolved_at)) / 60);

        $avgResolutionHours = $resolutionHours->isNotEmpty()
            ? round($resolutionHours->avg(), 1)
            : null;

        $overdueCount = $tickets
            ->filter(fn (Ticket $t) => in_array($t->status, self::ACTIVE_STATUSES, true)
                && $t->target_due_date !== null
                && $t->target_due_date->lt(today()))
            ->count();

        $summary = [
            'total_tickets' => $totalTickets,
            'issues_count' => $issuesCount,
            'engineering_requests_count' => $requestsCount,
            'open_active_count' => $openActiveCount,
            'resolved_closed_count' => $resolvedClosedCount,
            'resolution_rate_percent' => $resolutionRate,
            'avg_first_response_hours' => $avgFirstResponseHours,
            'avg_resolution_hours' => $avgResolutionHours,
            'overdue_count' => $overdueCount,
        ];

        $systemsQuery = InternalSystem::query()->orderByRaw('LOWER(name) ASC')->orderBy('name');
        if ($systemId) {
            $systemsQuery->whereKey($systemId);
        }
        $systems = $systemsQuery->get();

        $bySystem = [];
        foreach ($systems as $system) {
            $group = $tickets->where('internal_system_id', $system->id);
            $bySystem[] = $this->formatSystemRow($system->name, $group);
        }

        $unassignedGroup = $tickets->whereNull('internal_system_id');
        if (!$systemId && $unassignedGroup->isNotEmpty()) {
            $bySystem[] = $this->formatSystemRow('Unassigned / General', $unassignedGroup);
        }

        $statuses = [
            Ticket::STATUS_OPEN,
            Ticket::STATUS_UNDER_REVIEW,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_WAITING_ON_USER,
            Ticket::STATUS_RESOLVED,
            Ticket::STATUS_CLOSED,
            Ticket::STATUS_REJECTED,
            Ticket::STATUS_CANCELLED,
        ];
        $byStatus = [];
        foreach ($statuses as $status) {
            $byStatus[$status] = $tickets->where('status', $status)->count();
        }

        $priorities = [
            Ticket::PRIORITY_CRITICAL,
            Ticket::PRIORITY_HIGH,
            Ticket::PRIORITY_MEDIUM,
            Ticket::PRIORITY_LOW,
        ];
        $byPriority = [];
        foreach ($priorities as $priority) {
            $byPriority[$priority] = $tickets->where('priority', $priority)->count();
        }

        $filteredTicketIds = $tickets->pluck('id')->all();
        $completedSubtasksByEngineer = empty($filteredTicketIds)
            ? collect()
            : TicketSubtask::query()
                ->whereIn('ticket_id', $filteredTicketIds)
                ->where('is_completed', true)
                ->whereNotNull('assigned_engineer_id')
                ->selectRaw('assigned_engineer_id, COUNT(*) as aggregate')
                ->groupBy('assigned_engineer_id')
                ->pluck('aggregate', 'assigned_engineer_id');

        $staffUsers = User::query()
            ->where('role', User::ROLE_ENGINEER)
            ->orderBy('name')
            ->get();

        $byEngineer = $staffUsers->map(function (User $engineer) use ($tickets, $completedSubtasksByEngineer): array {
            $ledTickets = $tickets->where('lead_engineer_id', $engineer->id);
            $collaboratingTotal = $tickets
                ->filter(fn (Ticket $t) => $t->collaborators->contains('id', $engineer->id))
                ->count();

            $ledResolutionHours = $ledTickets
                ->filter(fn (Ticket $t) => $t->resolved_at !== null && $t->created_at !== null)
                ->map(fn (Ticket $t) => max(0, $t->created_at->diffInMinutes($t->resolved_at)) / 60);

            return [
                'id' => $engineer->id,
                'name' => $engineer->name,
                'department' => $engineer->department,
                'led_total' => $ledTickets->count(),
                'collaborating_total' => $collaboratingTotal,
                'active_led' => $ledTickets->whereIn('status', self::ACTIVE_STATUSES)->count(),
                'resolved_closed_led' => $ledTickets->whereIn('status', self::RESOLVED_OR_CLOSED)->count(),
                'avg_resolution_hours' => $ledResolutionHours->isNotEmpty()
                    ? round($ledResolutionHours->avg(), 1)
                    : null,
                'subtasks_completed' => (int) ($completedSubtasksByEngineer[$engineer->id] ?? 0),
            ];
        })->values()->all();

        $byDepartment = $tickets
            ->groupBy(fn (Ticket $t) => $t->requester?->department ?: 'Unassigned')
            ->map(function (Collection $group, string $department): array {
                return [
                    'department' => $department,
                    'total' => $group->count(),
                    'issues' => $group->where('type', Ticket::TYPE_ISSUE)->count(),
                    'requests' => $group->where('type', Ticket::TYPE_ENGINEERING_REQUEST)->count(),
                    'resolved_closed' => $group->whereIn('status', self::RESOLVED_OR_CLOSED)->count(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        return [
            'tickets' => $tickets,
            'summary' => $summary,
            'by_system' => $bySystem,
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'by_engineer' => $byEngineer,
            'by_department' => $byDepartment,
        ];
    }

    /**
     * @param Collection<int, Ticket> $group
     * @return array<string, mixed>
     */
    private function formatSystemRow(string $name, Collection $group): array
    {
        return [
            'system_name' => $name,
            'total' => $group->count(),
            'issues' => $group->where('type', Ticket::TYPE_ISSUE)->count(),
            'requests' => $group->where('type', Ticket::TYPE_ENGINEERING_REQUEST)->count(),
            'active' => $group->whereIn('status', self::ACTIVE_STATUSES)->count(),
            'resolved_closed' => $group->whereIn('status', self::RESOLVED_OR_CLOSED)->count(),
            'critical' => $group->where('priority', Ticket::PRIORITY_CRITICAL)->count(),
        ];
    }
}
