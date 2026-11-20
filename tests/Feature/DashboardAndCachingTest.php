<?php

namespace Tests\Feature;

use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardAndCachingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_metrics_return_zero_integers_when_no_tickets_exist(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('metrics', [
                    'total_visible' => 0,
                    'pending_approval_count' => 0,
                    'open_count' => 0,
                    'under_review_count' => 0,
                    'in_progress_count' => 0,
                    'waiting_on_user_count' => 0,
                    'resolved_count' => 0,
                    'closed_count' => 0,
                    'critical_high_active_count' => 0,
                    'issues_count' => 0,
                    'engineering_requests_count' => 0,
                    'overdue_count' => 0,
                ])
            );
    }

    public function test_dashboard_metrics_accuracy_by_role_and_overdue_boundaries(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $otherEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);
        $otherRequester = User::factory()->create(['role' => User::ROLE_USER]);

        // 1. Open, critical issue, overdue (yesterday), led by $engineer, requested by $requester
        $this->createTicket([
            'ticket_number' => 'ISS-2026-0001',
            'type' => Ticket::TYPE_ISSUE,
            'status' => Ticket::STATUS_OPEN,
            'priority' => Ticket::PRIORITY_CRITICAL,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'target_due_date' => today()->subDay()->toDateString(),
        ]);

        // 2. Under review, high issue, due today (not overdue), $engineer is collaborator, requested by $otherRequester
        $collabTicket = $this->createTicket([
            'ticket_number' => 'ISS-2026-0002',
            'type' => Ticket::TYPE_ISSUE,
            'status' => Ticket::STATUS_UNDER_REVIEW,
            'priority' => Ticket::PRIORITY_HIGH,
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'target_due_date' => today()->toDateString(),
        ]);
        $collabTicket->collaborators()->attach($engineer->id, ['added_by_id' => $superadmin->id]);

        // 3. In progress, medium engineering request, due tomorrow (not overdue), led by $engineer, requested by $requester
        $this->createTicket([
            'ticket_number' => 'REQ-2026-0001',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'priority' => Ticket::PRIORITY_MEDIUM,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'target_due_date' => today()->addDay()->toDateString(),
        ]);

        // 4. Waiting on user, low issue, null due date, led by $otherEngineer, requested by $requester
        $this->createTicket([
            'ticket_number' => 'ISS-2026-0003',
            'type' => Ticket::TYPE_ISSUE,
            'status' => Ticket::STATUS_WAITING_ON_USER,
            'priority' => Ticket::PRIORITY_LOW,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'target_due_date' => null,
        ]);

        // 5. Resolved, critical engineering request, due yesterday (not overdue because resolved), led by $otherEngineer, requested by $otherRequester
        $this->createTicket([
            'ticket_number' => 'REQ-2026-0002',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'status' => Ticket::STATUS_RESOLVED,
            'priority' => Ticket::PRIORITY_CRITICAL,
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'target_due_date' => today()->subDays(2)->toDateString(),
        ]);

        // 6. Closed, high engineering request, due yesterday (not overdue because closed), led by $engineer, requested by $otherRequester
        $this->createTicket([
            'ticket_number' => 'REQ-2026-0003',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'status' => Ticket::STATUS_CLOSED,
            'priority' => Ticket::PRIORITY_HIGH,
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => $engineer->id,
            'target_due_date' => today()->subDays(3)->toDateString(),
        ]);

        // 7. Rejected, critical issue, due yesterday (not overdue or active), unassigned, requested by $otherRequester
        $this->createTicket([
            'ticket_number' => 'ISS-2026-0004',
            'type' => Ticket::TYPE_ISSUE,
            'status' => Ticket::STATUS_REJECTED,
            'priority' => Ticket::PRIORITY_CRITICAL,
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => null,
            'target_due_date' => today()->subDay()->toDateString(),
        ]);

        // 8. Cancelled, high engineering request, due yesterday (not overdue or active), unassigned, requested by $requester
        $this->createTicket([
            'ticket_number' => 'REQ-2026-0004',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'status' => Ticket::STATUS_CANCELLED,
            'priority' => Ticket::PRIORITY_HIGH,
            'requester_id' => $requester->id,
            'lead_engineer_id' => null,
            'target_due_date' => today()->subDay()->toDateString(),
        ]);

        // Superadmin sees all 8 tickets.
        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics', [
                    'total_visible' => 8,
                    'pending_approval_count' => 0,
                    'open_count' => 1,
                    'under_review_count' => 1,
                    'in_progress_count' => 1,
                    'waiting_on_user_count' => 1,
                    'resolved_count' => 1,
                    'closed_count' => 1,
                    'critical_high_active_count' => 2,
                    'issues_count' => 4,
                    'engineering_requests_count' => 4,
                    'overdue_count' => 1,
                ])
            );

        // Engineer sees 4 tickets: #1 (lead), #2 (collaborator), #3 (lead), #6 (lead).
        $this->actingAs($engineer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics', [
                    'total_visible' => 4,
                    'pending_approval_count' => 0,
                    'open_count' => 1,
                    'under_review_count' => 1,
                    'in_progress_count' => 1,
                    'waiting_on_user_count' => 0,
                    'resolved_count' => 0,
                    'closed_count' => 1,
                    'critical_high_active_count' => 2,
                    'issues_count' => 2,
                    'engineering_requests_count' => 2,
                    'overdue_count' => 1,
                ])
            );

        // Requester sees 4 tickets: #1, #3, #4, #8.
        $this->actingAs($requester)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics', [
                    'total_visible' => 4,
                    'pending_approval_count' => 0,
                    'open_count' => 1,
                    'under_review_count' => 0,
                    'in_progress_count' => 1,
                    'waiting_on_user_count' => 1,
                    'resolved_count' => 0,
                    'closed_count' => 0,
                    'critical_high_active_count' => 1,
                    'issues_count' => 2,
                    'engineering_requests_count' => 2,
                    'overdue_count' => 1,
                ])
            );
    }

    public function test_dashboard_uses_single_aggregate_metrics_query_and_skips_activity_ticket_filter_for_superadmin(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        $visibleTicket = $this->createTicket([
            'ticket_number' => 'ISS-2026-0010',
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
        ]);
        $hiddenTicket = $this->createTicket([
            'ticket_number' => 'ISS-2026-0011',
            'requester_id' => $requester->id,
            'lead_engineer_id' => null,
        ]);

        TicketActivity::create([
            'ticket_id' => $visibleTicket->id,
            'user_id' => $engineer->id,
            'action_type' => 'commented',
            'description' => 'Visible ticket activity.',
        ]);
        TicketActivity::create([
            'ticket_id' => $hiddenTicket->id,
            'user_id' => $requester->id,
            'action_type' => 'created',
            'description' => 'Hidden ticket activity.',
        ]);

        // Warm internal systems cache first so query log focuses cleanly on dashboard queries.
        InternalSystem::cachedActiveList();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recent_activities', 2)
            );

        $superadminQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $ticketAggregateQueries = array_values(array_filter(
            $superadminQueries,
            static fn (array $entry): bool => str_contains(strtolower($entry['query']), 'count(*)')
                && str_contains(strtolower($entry['query']), 'from "tickets"')
        ));

        $this->assertCount(1, $ticketAggregateQueries);
        $this->assertStringContainsString('sum(case when', strtolower($ticketAggregateQueries[0]['query']));

        $activityQueries = array_values(array_filter(
            $superadminQueries,
            static fn (array $entry): bool => str_contains(strtolower($entry['query']), 'from "ticket_activities"')
        ));

        $this->assertCount(1, $activityQueries);
        $this->assertStringNotContainsString('from "tickets"', strtolower($activityQueries[0]['query']));

        // Non-superadmin sees only activities for visible tickets and still executes 1 aggregate metrics query.
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($engineer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recent_activities', 1)
                ->where('recent_activities.0.ticket_id', $visibleTicket->id)
            );

        $engineerQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $engineerAggregateQueries = array_values(array_filter(
            $engineerQueries,
            static fn (array $entry): bool => str_contains(strtolower($entry['query']), 'count(*)')
                && str_contains(strtolower($entry['query']), 'from "tickets"')
        ));

        $this->assertCount(1, $engineerAggregateQueries);
        $this->assertStringContainsString('sum(case when', strtolower($engineerAggregateQueries[0]['query']));

        $engineerActivityQueries = array_values(array_filter(
            $engineerQueries,
            static fn (array $entry): bool => str_contains(strtolower($entry['query']), 'from "ticket_activities"')
        ));

        $this->assertCount(1, $engineerActivityQueries);
        $this->assertStringContainsString('in (select', strtolower($engineerActivityQueries[0]['query']));
    }

    public function test_internal_systems_cached_active_list_caches_and_invalidates_on_mutations(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $zeta = InternalSystem::create([
            'name' => 'Zeta Core',
            'category' => 'Core Platform',
            'is_active' => true,
        ]);
        $alpha = InternalSystem::create([
            'name' => 'alpha gateway',
            'category' => 'Core Platform',
            'is_active' => true,
        ]);
        InternalSystem::create([
            'name' => 'Inactive Legacy',
            'category' => 'Core Platform',
            'is_active' => false,
        ]);

        Cache::forget(InternalSystem::ACTIVE_CACHE_KEY);

        // First dashboard load queries internal_systems once and populates cache.
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('internalSystems', 2)
                ->where('internalSystems.0.name', 'alpha gateway')
                ->where('internalSystems.1.name', 'Zeta Core')
            );

        $firstRequestSystemQueries = array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $entry): bool => str_contains(strtolower($entry['query']), 'internal_systems')
        ));
        DB::disableQueryLog();

        $this->assertCount(1, $firstRequestSystemQueries);
        $this->assertTrue(Cache::has(InternalSystem::ACTIVE_CACHE_KEY));

        // Second dashboard load executes 0 internal_systems queries.
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('internalSystems', 2)
                ->where('internalSystems.0.name', 'alpha gateway')
                ->where('internalSystems.1.name', 'Zeta Core')
            );

        $secondRequestSystemQueries = array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $entry): bool => str_contains(strtolower($entry['query']), 'internal_systems')
        ));
        DB::disableQueryLog();

        $this->assertCount(0, $secondRequestSystemQueries);

        // Creating an active system invalidates the cache immediately.
        $billing = InternalSystem::create([
            'name' => 'Billing Engine',
            'category' => 'Finance',
            'is_active' => true,
        ]);

        $this->assertFalse(Cache::has(InternalSystem::ACTIVE_CACHE_KEY));
        $this->assertSame(
            [
                ['id' => $alpha->id, 'name' => 'alpha gateway'],
                ['id' => $billing->id, 'name' => 'Billing Engine'],
                ['id' => $zeta->id, 'name' => 'Zeta Core'],
            ],
            InternalSystem::cachedActiveList()
        );
        $this->assertTrue(Cache::has(InternalSystem::ACTIVE_CACHE_KEY));

        // Renaming a system invalidates the cache immediately.
        $zeta->update(['name' => 'Apex Portal']);
        $this->assertFalse(Cache::has(InternalSystem::ACTIVE_CACHE_KEY));
        $this->assertSame(
            [
                ['id' => $alpha->id, 'name' => 'alpha gateway'],
                ['id' => $zeta->id, 'name' => 'Apex Portal'],
                ['id' => $billing->id, 'name' => 'Billing Engine'],
            ],
            InternalSystem::cachedActiveList()
        );

        // Toggling active/inactive invalidates the cache immediately.
        $billing->update(['is_active' => false]);
        $this->assertFalse(Cache::has(InternalSystem::ACTIVE_CACHE_KEY));
        $this->assertSame(
            [
                ['id' => $alpha->id, 'name' => 'alpha gateway'],
                ['id' => $zeta->id, 'name' => 'Apex Portal'],
            ],
            InternalSystem::cachedActiveList()
        );

        // Deleting a system invalidates the cache immediately.
        $alpha->delete();
        $this->assertFalse(Cache::has(InternalSystem::ACTIVE_CACHE_KEY));
        $this->assertSame(
            [
                ['id' => $zeta->id, 'name' => 'Apex Portal'],
            ],
            InternalSystem::cachedActiveList()
        );
    }

    /**
     * Create a ticket with sensible defaults for dashboard tests.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createTicket(array $overrides): Ticket
    {
        return Ticket::create([
            'ticket_number' => $overrides['ticket_number'] ?? 'ISS-2026-9999',
            'type' => $overrides['type'] ?? Ticket::TYPE_ISSUE,
            'requester_id' => $overrides['requester_id'],
            'lead_engineer_id' => $overrides['lead_engineer_id'] ?? null,
            'title' => $overrides['title'] ?? 'Sample dashboard ticket',
            'description' => $overrides['description'] ?? 'Sample dashboard ticket description.',
            'priority' => $overrides['priority'] ?? Ticket::PRIORITY_MEDIUM,
            'status' => $overrides['status'] ?? Ticket::STATUS_OPEN,
            'target_due_date' => $overrides['target_due_date'] ?? null,
        ]);
    }
}
