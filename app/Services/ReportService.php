<?php

namespace App\Services;

use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\TicketSubtask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class ReportService
{
    /**
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        Ticket::STATUS_OPEN,
        Ticket::STATUS_UNDER_REVIEW,
        Ticket::STATUS_IN_PROGRESS,
        Ticket::STATUS_WAITING_ON_USER,
    ];

    /**
     * @var list<string>
     */
    public const RESOLVED_OR_CLOSED = [
        Ticket::STATUS_RESOLVED,
        Ticket::STATUS_CLOSED,
    ];

    /**
     * @var list<string>
     */
    public const ALL_STATUSES = [
        Ticket::STATUS_PENDING_APPROVAL,
        Ticket::STATUS_OPEN,
        Ticket::STATUS_UNDER_REVIEW,
        Ticket::STATUS_IN_PROGRESS,
        Ticket::STATUS_WAITING_ON_USER,
        Ticket::STATUS_RESOLVED,
        Ticket::STATUS_CLOSED,
        Ticket::STATUS_REJECTED,
        Ticket::STATUS_CANCELLED,
    ];

    /**
     * @var list<string>
     */
    public const ALL_PRIORITIES = [
        Ticket::PRIORITY_CRITICAL,
        Ticket::PRIORITY_HIGH,
        Ticket::PRIORITY_MEDIUM,
        Ticket::PRIORITY_LOW,
    ];

    /**
     * Build the base filtered ticket query scoped to the user's visibility.
     *
     * @param array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     type?: string|null,
     *     internal_system_id?: int|string|null,
     * } $filters
     * @return Builder<Ticket>
     */
    public function filteredQuery(User $user, array $filters): Builder
    {
        $query = Ticket::query()->visibleTo($user);

        $dateFrom = $filters['date_from'] ?? null;
        if ($dateFrom !== null && $dateFrom !== '') {
            $query->where('tickets.created_at', '>=', Carbon::parse((string) $dateFrom)->startOfDay());
        }

        $dateTo = $filters['date_to'] ?? null;
        if ($dateTo !== null && $dateTo !== '') {
            $query->where('tickets.created_at', '<=', Carbon::parse((string) $dateTo)->endOfDay());
        }

        $type = $filters['type'] ?? null;
        if ($type !== null && $type !== '') {
            $query->where('tickets.type', (string) $type);
        }

        $systemId = $filters['internal_system_id'] ?? null;
        if ($systemId !== null && $systemId !== '') {
            $query->where('tickets.internal_system_id', (int) $systemId);
        }

        return $query;
    }

    /**
     * Return a driver-compatible SQL expression for elapsed hours between two timestamp columns.
     */
    public function hoursBetween(string $fromColumn, string $toColumn, ?string $driver = null): string
    {
        $resolvedDriver = $driver ?? DB::connection()->getDriverName();

        return match ($resolvedDriver) {
            'sqlite' => "(julianday({$toColumn}) - julianday({$fromColumn})) * 24.0",
            'pgsql' => "EXTRACT(EPOCH FROM ({$toColumn} - {$fromColumn})) / 3600.0",
            default => "TIMESTAMPDIFF(SECOND, {$fromColumn}, {$toColumn}) / 3600.0",
        };
    }

    /**
     * Compute high-level KPI summary metrics via a single SQL aggregate query.
     *
     * @param array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     type?: string|null,
     *     internal_system_id?: int|string|null,
     * } $filters
     * @return array{
     *     total_tickets: int,
     *     issues_count: int,
     *     engineering_requests_count: int,
     *     open_active_count: int,
     *     resolved_closed_count: int,
     *     resolution_rate_percent: float,
     *     avg_first_response_hours: float|null,
     *     avg_resolution_hours: float|null,
     *     overdue_count: int,
     * }
     */
    public function buildSummary(User $user, array $filters): array
    {
        $activePlaceholders = implode(', ', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $resolvedPlaceholders = implode(', ', array_fill(0, count(self::RESOLVED_OR_CLOSED), '?'));
        $firstResponseExpr = $this->hoursBetween('tickets.created_at', 'tickets.first_responded_at');
        $resolutionExpr = $this->hoursBetween('tickets.created_at', 'tickets.resolved_at');
        $todayDate = today()->toDateString();

        $selectSql = implode(', ', [
            'COUNT(*) as total_tickets',
            'COALESCE(SUM(CASE WHEN tickets.type = ? THEN 1 ELSE 0 END), 0) as issues_count',
            'COALESCE(SUM(CASE WHEN tickets.type = ? THEN 1 ELSE 0 END), 0) as engineering_requests_count',
            "COALESCE(SUM(CASE WHEN tickets.status IN ({$activePlaceholders}) THEN 1 ELSE 0 END), 0) as open_active_count",
            "COALESCE(SUM(CASE WHEN tickets.status IN ({$resolvedPlaceholders}) THEN 1 ELSE 0 END), 0) as resolved_closed_count",
            "AVG(CASE WHEN tickets.first_responded_at IS NOT NULL AND tickets.created_at IS NOT NULL THEN CASE WHEN tickets.first_responded_at >= tickets.created_at THEN {$firstResponseExpr} ELSE 0.0 END END) as avg_first_response_hours",
            "AVG(CASE WHEN tickets.resolved_at IS NOT NULL AND tickets.created_at IS NOT NULL THEN CASE WHEN tickets.resolved_at >= tickets.created_at THEN {$resolutionExpr} ELSE 0.0 END END) as avg_resolution_hours",
            "COALESCE(SUM(CASE WHEN tickets.status IN ({$activePlaceholders}) AND tickets.target_due_date IS NOT NULL AND tickets.target_due_date < ? THEN 1 ELSE 0 END), 0) as overdue_count",
        ]);

        $bindings = [
            Ticket::TYPE_ISSUE,
            Ticket::TYPE_ENGINEERING_REQUEST,
            ...self::ACTIVE_STATUSES,
            ...self::RESOLVED_OR_CLOSED,
            ...self::ACTIVE_STATUSES,
            $todayDate,
        ];

        $row = $this->filteredQuery($user, $filters)
            ->toBase()
            ->selectRaw($selectSql, $bindings)
            ->first();

        $totalTickets = (int) ($row->total_tickets ?? 0);
        $resolvedClosedCount = (int) ($row->resolved_closed_count ?? 0);

        return [
            'total_tickets' => $totalTickets,
            'issues_count' => (int) ($row->issues_count ?? 0),
            'engineering_requests_count' => (int) ($row->engineering_requests_count ?? 0),
            'open_active_count' => (int) ($row->open_active_count ?? 0),
            'resolved_closed_count' => $resolvedClosedCount,
            'resolution_rate_percent' => $totalTickets > 0
                ? round(($resolvedClosedCount / $totalTickets) * 100, 1)
                : 0.0,
            'avg_first_response_hours' => $row?->avg_first_response_hours !== null
                ? round((float) $row->avg_first_response_hours, 1)
                : null,
            'avg_resolution_hours' => $row?->avg_resolution_hours !== null
                ? round((float) $row->avg_resolution_hours, 1)
                : null,
            'overdue_count' => (int) ($row->overdue_count ?? 0),
        ];
    }

    /**
     * Aggregate ticket counts grouped by workflow status, preserving all 8 status keys.
     *
     * @param array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     type?: string|null,
     *     internal_system_id?: int|string|null,
     * } $filters
     * @return array<string, int>
     */
    public function buildByStatus(User $user, array $filters): array
    {
        $counts = $this->filteredQuery($user, $filters)
            ->toBase()
            ->selectRaw('tickets.status as status, COUNT(*) as aggregate')
            ->groupBy('tickets.status')
            ->pluck('aggregate', 'status');

        $byStatus = [];
        foreach (self::ALL_STATUSES as $status) {
            $byStatus[$status] = (int) ($counts[$status] ?? 0);
        }

        return $byStatus;
    }

    /**
     * Aggregate ticket counts grouped by priority, preserving all 4 priority keys.
     *
     * @param array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     type?: string|null,
     *     internal_system_id?: int|string|null,
     * } $filters
     * @return array<string, int>
     */
    public function buildByPriority(User $user, array $filters): array
    {
        $counts = $this->filteredQuery($user, $filters)
            ->toBase()
            ->selectRaw('tickets.priority as priority, COUNT(*) as aggregate')
            ->groupBy('tickets.priority')
            ->pluck('aggregate', 'priority');

        $byPriority = [];
        foreach (self::ALL_PRIORITIES as $priority) {
            $byPriority[$priority] = (int) ($counts[$priority] ?? 0);
        }

        return $byPriority;
    }

    /**
     * Aggregate ticket breakdown grouped by internal system, including zero-ticket systems.
     *
     * @param array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     type?: string|null,
     *     internal_system_id?: int|string|null,
     * } $filters
     * @return list<array{
     *     system_name: string,
     *     total: int,
     *     issues: int,
     *     requests: int,
     *     active: int,
     *     resolved_closed: int,
     *     critical: int,
     * }>
     */
    public function buildBySystem(User $user, array $filters): array
    {
        $activePlaceholders = implode(', ', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $resolvedPlaceholders = implode(', ', array_fill(0, count(self::RESOLVED_OR_CLOSED), '?'));

        $selectSql = implode(', ', [
            'tickets.internal_system_id as internal_system_id',
            'COUNT(*) as total',
            'COALESCE(SUM(CASE WHEN tickets.type = ? THEN 1 ELSE 0 END), 0) as issues',
            'COALESCE(SUM(CASE WHEN tickets.type = ? THEN 1 ELSE 0 END), 0) as requests',
            "COALESCE(SUM(CASE WHEN tickets.status IN ({$activePlaceholders}) THEN 1 ELSE 0 END), 0) as active",
            "COALESCE(SUM(CASE WHEN tickets.status IN ({$resolvedPlaceholders}) THEN 1 ELSE 0 END), 0) as resolved_closed",
            'COALESCE(SUM(CASE WHEN tickets.priority = ? THEN 1 ELSE 0 END), 0) as critical',
        ]);

        $bindings = [
            Ticket::TYPE_ISSUE,
            Ticket::TYPE_ENGINEERING_REQUEST,
            ...self::ACTIVE_STATUSES,
            ...self::RESOLVED_OR_CLOSED,
            Ticket::PRIORITY_CRITICAL,
        ];

        $rows = $this->filteredQuery($user, $filters)
            ->toBase()
            ->selectRaw($selectSql, $bindings)
            ->groupBy('tickets.internal_system_id')
            ->get();

        $grouped = [];
        $unassignedRow = null;

        foreach ($rows as $row) {
            if ($row->internal_system_id === null) {
                $unassignedRow = $row;
            } else {
                $grouped[(int) $row->internal_system_id] = $row;
            }
        }

        $systemId = $filters['internal_system_id'] ?? null;
        $hasSystemFilter = $systemId !== null && $systemId !== '';

        $systemsQuery = InternalSystem::query()
            ->orderByRaw('LOWER(name) ASC')
            ->orderBy('name');

        if ($hasSystemFilter) {
            $systemsQuery->whereKey((int) $systemId);
        }

        $systems = $systemsQuery->get(['id', 'name']);

        $bySystem = [];
        foreach ($systems as $system) {
            $bySystem[] = $this->formatSystemRow($system->name, $grouped[$system->id] ?? null);
        }

        if (! $hasSystemFilter && $unassignedRow !== null && (int) $unassignedRow->total > 0) {
            $bySystem[] = $this->formatSystemRow('Unassigned / General', $unassignedRow);
        }

        return $bySystem;
    }

    /**
     * Aggregate workload, resolution turnaround, and completed subtasks per engineer.
     *
     * @param array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     type?: string|null,
     *     internal_system_id?: int|string|null,
     * } $filters
     * @return list<array{
     *     id: int,
     *     name: string,
     *     department: string|null,
     *     led_total: int,
     *     collaborating_total: int,
     *     active_led: int,
     *     resolved_closed_led: int,
     *     avg_resolution_hours: float|null,
     *     subtasks_completed: int,
     * }>
     */
    public function buildByEngineer(User $user, array $filters): array
    {
        $activePlaceholders = implode(', ', array_fill(0, count(self::ACTIVE_STATUSES), '?'));
        $resolvedPlaceholders = implode(', ', array_fill(0, count(self::RESOLVED_OR_CLOSED), '?'));
        $resolutionExpr = $this->hoursBetween('tickets.created_at', 'tickets.resolved_at');

        $ledSelectSql = implode(', ', [
            'tickets.lead_engineer_id as lead_engineer_id',
            'COUNT(*) as led_total',
            "COALESCE(SUM(CASE WHEN tickets.status IN ({$activePlaceholders}) THEN 1 ELSE 0 END), 0) as active_led",
            "COALESCE(SUM(CASE WHEN tickets.status IN ({$resolvedPlaceholders}) THEN 1 ELSE 0 END), 0) as resolved_closed_led",
            "AVG(CASE WHEN tickets.resolved_at IS NOT NULL AND tickets.created_at IS NOT NULL THEN CASE WHEN tickets.resolved_at >= tickets.created_at THEN {$resolutionExpr} ELSE 0.0 END END) as avg_resolution_hours",
        ]);

        $ledBindings = [
            ...self::ACTIVE_STATUSES,
            ...self::RESOLVED_OR_CLOSED,
        ];

        $ledByEngineer = $this->filteredQuery($user, $filters)
            ->toBase()
            ->whereNotNull('tickets.lead_engineer_id')
            ->selectRaw($ledSelectSql, $ledBindings)
            ->groupBy('tickets.lead_engineer_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->lead_engineer_id);

        $collaboratingByEngineer = DB::table('ticket_collaborators')
            ->whereIn('ticket_id', $this->filteredQuery($user, $filters)->select('tickets.id'))
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id');

        $completedSubtasksByEngineer = TicketSubtask::query()
            ->whereIn('ticket_id', $this->filteredQuery($user, $filters)->select('tickets.id'))
            ->where('is_completed', true)
            ->whereNotNull('assigned_engineer_id')
            ->selectRaw('assigned_engineer_id, COUNT(*) as aggregate')
            ->groupBy('assigned_engineer_id')
            ->pluck('aggregate', 'assigned_engineer_id');

        $engineers = User::query()
            ->where('role', User::ROLE_ENGINEER)
            ->orderBy('name')
            ->get(['id', 'name', 'department']);

        return $engineers->map(function (User $engineer) use ($ledByEngineer, $collaboratingByEngineer, $completedSubtasksByEngineer): array {
            $led = $ledByEngineer->get($engineer->id);

            return [
                'id' => $engineer->id,
                'name' => $engineer->name,
                'department' => $engineer->department,
                'led_total' => (int) ($led->led_total ?? 0),
                'collaborating_total' => (int) ($collaboratingByEngineer[$engineer->id] ?? 0),
                'active_led' => (int) ($led->active_led ?? 0),
                'resolved_closed_led' => (int) ($led->resolved_closed_led ?? 0),
                'avg_resolution_hours' => $led !== null && $led->avg_resolution_hours !== null
                    ? round((float) $led->avg_resolution_hours, 1)
                    : null,
                'subtasks_completed' => (int) ($completedSubtasksByEngineer[$engineer->id] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * Aggregate ticket breakdown grouped by requester department, sorted by total descending.
     *
     * @param array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     type?: string|null,
     *     internal_system_id?: int|string|null,
     * } $filters
     * @return list<array{
     *     department: string,
     *     total: int,
     *     issues: int,
     *     requests: int,
     *     resolved_closed: int,
     * }>
     */
    public function buildByDepartment(User $user, array $filters): array
    {
        $resolvedPlaceholders = implode(', ', array_fill(0, count(self::RESOLVED_OR_CLOSED), '?'));

        $selectSql = implode(', ', [
            "COALESCE(NULLIF(users.department, ''), 'Unassigned') as department",
            'COUNT(*) as total',
            'COALESCE(SUM(CASE WHEN tickets.type = ? THEN 1 ELSE 0 END), 0) as issues',
            'COALESCE(SUM(CASE WHEN tickets.type = ? THEN 1 ELSE 0 END), 0) as requests',
            "COALESCE(SUM(CASE WHEN tickets.status IN ({$resolvedPlaceholders}) THEN 1 ELSE 0 END), 0) as resolved_closed",
        ]);

        $bindings = [
            Ticket::TYPE_ISSUE,
            Ticket::TYPE_ENGINEERING_REQUEST,
            ...self::RESOLVED_OR_CLOSED,
        ];

        $rows = $this->filteredQuery($user, $filters)
            ->toBase()
            ->leftJoin('users', 'users.id', '=', 'tickets.requester_id')
            ->selectRaw($selectSql, $bindings)
            ->groupByRaw("COALESCE(NULLIF(users.department, ''), 'Unassigned')")
            ->orderByDesc('total')
            ->orderBy('department')
            ->get();

        return $rows->map(fn (object $row): array => [
            'department' => (string) $row->department,
            'total' => (int) $row->total,
            'issues' => (int) $row->issues,
            'requests' => (int) $row->requests,
            'resolved_closed' => (int) $row->resolved_closed,
        ])->values()->all();
    }

    /**
     * Build all aggregated sections required for the reports overview page.
     *
     * @param array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     type?: string|null,
     *     internal_system_id?: int|string|null,
     * } $filters
     * @return array{
     *     summary: array<string, int|float|null>,
     *     by_system: list<array<string, string|int>>,
     *     by_status: array<string, int>,
     *     by_priority: array<string, int>,
     *     by_engineer: list<array<string, string|int|float|null>>,
     *     by_department: list<array<string, string|int>>,
     * }
     */
    public function buildOverview(User $user, array $filters): array
    {
        return [
            'summary' => $this->buildSummary($user, $filters),
            'by_system' => $this->buildBySystem($user, $filters),
            'by_status' => $this->buildByStatus($user, $filters),
            'by_priority' => $this->buildByPriority($user, $filters),
            'by_engineer' => $this->buildByEngineer($user, $filters),
            'by_department' => $this->buildByDepartment($user, $filters),
        ];
    }

    /**
     * Stream filtered tickets in chunks for CSV export without loading the entire dataset at once.
     *
     * @param array{
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     type?: string|null,
     *     internal_system_id?: int|string|null,
     * } $filters
     * @return LazyCollection<int, Ticket>
     */
    public function streamTickets(User $user, array $filters): LazyCollection
    {
        return $this->filteredQuery($user, $filters)
            ->with(['internalSystem:id,name', 'requester:id,name,department', 'leadEngineer:id,name'])
            ->lazyByIdDesc(500, 'tickets.id', 'id');
    }

    /**
     * Format a single system aggregate row with zero defaults.
     *
     * @return array{
     *     system_name: string,
     *     total: int,
     *     issues: int,
     *     requests: int,
     *     active: int,
     *     resolved_closed: int,
     *     critical: int,
     * }
     */
    private function formatSystemRow(string $name, ?object $row): array
    {
        return [
            'system_name' => $name,
            'total' => (int) ($row->total ?? 0),
            'issues' => (int) ($row->issues ?? 0),
            'requests' => (int) ($row->requests ?? 0),
            'active' => (int) ($row->active ?? 0),
            'resolved_closed' => (int) ($row->resolved_closed ?? 0),
            'critical' => (int) ($row->critical ?? 0),
        ];
    }
}
