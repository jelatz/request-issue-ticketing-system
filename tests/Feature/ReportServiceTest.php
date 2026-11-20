<?php

namespace Tests\Feature;

use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\TicketSubtask;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reportService = app(ReportService::class);
    }

    public function test_hours_between_returns_driver_compatible_sql_expressions(): void
    {
        $this->assertSame(
            '(julianday(tickets.resolved_at) - julianday(tickets.created_at)) * 24.0',
            $this->reportService->hoursBetween('tickets.created_at', 'tickets.resolved_at', 'sqlite')
        );

        $this->assertSame(
            'TIMESTAMPDIFF(SECOND, tickets.created_at, tickets.resolved_at) / 3600.0',
            $this->reportService->hoursBetween('tickets.created_at', 'tickets.resolved_at', 'mysql')
        );

        $this->assertSame(
            'EXTRACT(EPOCH FROM (tickets.resolved_at - tickets.created_at)) / 3600.0',
            $this->reportService->hoursBetween('tickets.created_at', 'tickets.resolved_at', 'pgsql')
        );
    }

    public function test_empty_results_return_zero_and_null_baselines(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'name' => 'Solo Engineer',
            'department' => 'Engineering',
        ]);
        $inactiveSystem = InternalSystem::create(['name' => 'Legacy App', 'is_active' => false]);

        $overview = $this->reportService->buildOverview($superadmin, []);

        $this->assertSame([
            'total_tickets' => 0,
            'issues_count' => 0,
            'engineering_requests_count' => 0,
            'open_active_count' => 0,
            'resolved_closed_count' => 0,
            'resolution_rate_percent' => 0.0,
            'avg_first_response_hours' => null,
            'avg_resolution_hours' => null,
            'overdue_count' => 0,
        ], $overview['summary']);

        $this->assertSame([
            Ticket::STATUS_PENDING_APPROVAL => 0,
            Ticket::STATUS_OPEN => 0,
            Ticket::STATUS_UNDER_REVIEW => 0,
            Ticket::STATUS_IN_PROGRESS => 0,
            Ticket::STATUS_WAITING_ON_USER => 0,
            Ticket::STATUS_RESOLVED => 0,
            Ticket::STATUS_CLOSED => 0,
            Ticket::STATUS_REJECTED => 0,
            Ticket::STATUS_CANCELLED => 0,
        ], $overview['by_status']);

        $this->assertSame([
            Ticket::PRIORITY_CRITICAL => 0,
            Ticket::PRIORITY_HIGH => 0,
            Ticket::PRIORITY_MEDIUM => 0,
            Ticket::PRIORITY_LOW => 0,
        ], $overview['by_priority']);

        $this->assertSame([
            [
                'system_name' => $inactiveSystem->name,
                'total' => 0,
                'issues' => 0,
                'requests' => 0,
                'active' => 0,
                'resolved_closed' => 0,
                'critical' => 0,
            ],
        ], $overview['by_system']);

        $this->assertSame([
            [
                'id' => $engineer->id,
                'name' => 'Solo Engineer',
                'department' => 'Engineering',
                'led_total' => 0,
                'collaborating_total' => 0,
                'active_led' => 0,
                'resolved_closed_led' => 0,
                'avg_resolution_hours' => null,
                'subtasks_completed' => 0,
            ],
        ], $overview['by_engineer']);

        $this->assertSame([], $overview['by_department']);
    }

    public function test_role_visibility_negative_skew_clamping_filters_and_department_fallbacks(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engLead = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'name' => 'Alpha Lead',
            'department' => 'Platform',
        ]);
        $engCollab = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'name' => 'Beta Collab',
            'department' => 'Support',
        ]);

        $reqNullDept = User::factory()->create(['role' => User::ROLE_USER, 'department' => null]);
        $reqEmptyDept = User::factory()->create(['role' => User::ROLE_USER, 'department' => '']);
        $reqFinance = User::factory()->create(['role' => User::ROLE_USER, 'department' => 'Finance']);

        $sysAlpha = InternalSystem::create(['name' => 'Alpha System', 'is_active' => true]);
        $sysInactive = InternalSystem::create(['name' => 'Retired System', 'is_active' => false]);

        $base = Carbon::create(2026, 8, 10, 9, 0, 0);

        $t1 = Ticket::create([
            'ticket_number' => 'ISS-2026-9001',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $reqNullDept->id,
            'lead_engineer_id' => $engLead->id,
            'title' => 'Negative Skew Ticket',
            'description' => 'Timestamps before created_at clamp to 0.0.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_RESOLVED,
            'first_responded_at' => $base->copy()->subMinutes(20),
            'resolved_at' => $base->copy()->subMinutes(10),
        ]);
        DB::table('tickets')->where('id', $t1->id)->update([
            'created_at' => $base->toDateTimeString(),
        ]);

        $t2 = Ticket::create([
            'ticket_number' => 'REQ-2026-9002',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $sysInactive->id,
            'requester_id' => $reqEmptyDept->id,
            'lead_engineer_id' => $engLead->id,
            'title' => 'Positive Turnaround Ticket',
            'description' => '3 hours resolution.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_CLOSED,
            'first_responded_at' => $base->copy()->addMinutes(90),
            'resolved_at' => $base->copy()->addMinutes(180),
        ]);
        DB::table('tickets')->where('id', $t2->id)->update([
            'created_at' => $base->toDateTimeString(),
        ]);
        $t2->collaborators()->attach($engCollab->id, ['added_by_id' => $superadmin->id]);

        $t3 = Ticket::create([
            'ticket_number' => 'ISS-2026-9003',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => null,
            'requester_id' => $reqFinance->id,
            'lead_engineer_id' => $engCollab->id,
            'title' => 'Overdue Unassigned System Ticket',
            'description' => 'Past due open issue.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
            'target_due_date' => today()->subDay(),
        ]);
        DB::table('tickets')->where('id', $t3->id)->update([
            'created_at' => $base->copy()->addDays(2)->toDateTimeString(),
        ]);

        TicketSubtask::create([
            'ticket_id' => $t2->id,
            'title' => 'Done subtask',
            'assigned_engineer_id' => $engCollab->id,
            'is_completed' => true,
            'completed_at' => now(),
            'created_by_id' => $engLead->id,
            'sort_order' => 1,
        ]);

        // Superadmin sees all 3 tickets; negative skew keeps 0.0 in denominator:
        // avg_first_response_hours = (0.0 + 1.5) / 2 = 0.75 -> 0.8
        // avg_resolution_hours = (0.0 + 3.0) / 2 = 1.5
        $adminOverview = $this->reportService->buildOverview($superadmin, []);
        $this->assertSame(3, $adminOverview['summary']['total_tickets']);
        $this->assertSame(0.8, $adminOverview['summary']['avg_first_response_hours']);
        $this->assertSame(1.5, $adminOverview['summary']['avg_resolution_hours']);
        $this->assertSame(1, $adminOverview['summary']['overdue_count']);

        // Both null and '' requester departments collapse into 'Unassigned' (total=2)
        $this->assertSame([
            [
                'department' => 'Unassigned',
                'total' => 2,
                'issues' => 1,
                'requests' => 1,
                'resolved_closed' => 2,
            ],
            [
                'department' => 'Finance',
                'total' => 1,
                'issues' => 1,
                'requests' => 0,
                'resolved_closed' => 0,
            ],
        ], $adminOverview['by_department']);

        // Collaborator engineer sees t2 (collaborator) and t3 (lead), NOT t1
        $collabOverview = $this->reportService->buildOverview($engCollab, []);
        $this->assertSame(2, $collabOverview['summary']['total_tickets']);
        $this->assertSame(1.5, $collabOverview['summary']['avg_first_response_hours']);
        $this->assertSame(3.0, $collabOverview['summary']['avg_resolution_hours']);
        $this->assertSame(1, $collabOverview['summary']['overdue_count']);

        // Lead engineer sees t1 and t2, NOT t3
        $leadOverview = $this->reportService->buildOverview($engLead, []);
        $this->assertSame(2, $leadOverview['summary']['total_tickets']);
        $this->assertSame(0, $leadOverview['summary']['overdue_count']);

        // Verify date, type, and internal_system_id filters
        $filtered = $this->reportService->buildOverview($superadmin, [
            'date_from' => '2026-08-10',
            'date_to' => '2026-08-10',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $sysInactive->id,
        ]);
        $this->assertSame(1, $filtered['summary']['total_tickets']);
        $this->assertCount(1, $filtered['by_system']);
        $this->assertSame('Retired System', $filtered['by_system'][0]['system_name']);
        $this->assertSame(1, $filtered['by_engineer'][1]['collaborating_total']);
        $this->assertSame(1, $filtered['by_engineer'][1]['subtasks_completed']);
    }

    public function test_stream_tickets_and_csv_export_handle_more_than_500_tickets_in_chunks(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Bulk Requester',
            'department' => 'Operations',
        ]);
        $system = InternalSystem::create(['name' => 'Bulk Core', 'is_active' => true]);

        $now = now()->toDateTimeString();
        $rows = [];
        for ($i = 1; $i <= 520; $i++) {
            $rows[] = [
                'ticket_number' => sprintf('ISS-2026-%04d', $i),
                'type' => Ticket::TYPE_ISSUE,
                'internal_system_id' => $system->id,
                'requester_id' => $requester->id,
                'lead_engineer_id' => null,
                'title' => "Bulk Ticket {$i}",
                'description' => 'Bulk load test ticket.',
                'priority' => Ticket::PRIORITY_LOW,
                'status' => Ticket::STATUS_OPEN,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tickets')->insert($chunk);
        }

        $streamedIds = $this->reportService
            ->streamTickets($superadmin, [])
            ->pluck('id')
            ->all();

        $this->assertCount(520, $streamedIds);
        $expectedDescIds = $streamedIds;
        rsort($expectedDescIds);
        $this->assertSame($expectedDescIds, $streamedIds);

        $csv = $this->actingAs($superadmin)
            ->get(route('reports.export', ['section' => 'tickets']))
            ->assertOk()
            ->streamedContent();

        $lines = preg_split("/\r\n|\n|\r/", trim($csv));
        $this->assertCount(521, $lines);
        $this->assertStringContainsString('ISS-2026-0001', $csv);
        $this->assertStringContainsString('ISS-2026-0520', $csv);
    }
}
