<?php

namespace Tests\Feature;

use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\TicketSubtask;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TicketingBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        Mail::fake();
    }

    public function test_users_can_login_with_username_or_email_and_deactivated_users_are_rejected(): void
    {
        $user = User::factory()->create([
            'username' => 'jdoe',
            'email' => 'jdoe@calltek.local',
            'password' => 'secret123',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        // Login with username
        $response = $this->post(route('login.store'), [
            'login' => 'jdoe',
            'password' => 'secret123',
        ]);
        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // Logout
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Login with email
        $response = $this->post(route('login.store'), [
            'login' => 'jdoe@calltek.local',
            'password' => 'secret123',
        ]);
        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // Deactivate mid-session -> EnsureUserIsActive logs user out
        $user->update(['is_active' => false]);
        Auth::forgetGuards();
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
        $this->assertGuest();

        // Deactivated user cannot log back in
        $this->post(route('login.store'), [
            'login' => 'jdoe',
            'password' => 'secret123',
        ])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_requester_can_submit_issue_and_engineering_request_with_attachments(): void
    {
        Storage::fake('public');

        $superadmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $otherEngineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'department' => 'Finance & Billing',
            'is_active' => true,
        ]);

        $system = InternalSystem::create([
            'name' => 'Billing Portal',
            'category' => 'Core Revenue',
            'is_active' => true,
        ]);

        // 1. Submit Issue
        $issueResponse = $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $system->id,
            'title' => 'Invoice PDF fails on zero-rated accounts',
            'description' => 'PDF export throws 500 error for VAT-exempt customers.',
            'priority' => Ticket::PRIORITY_HIGH,
            'environment' => 'production',
            'impact_scope' => 'department',
            'attachments' => [
                UploadedFile::fake()->create('error-trace.log', 12, 'text/plain'),
            ],
        ]);

        $issue = Ticket::where('type', Ticket::TYPE_ISSUE)->firstOrFail();
        $issueResponse->assertRedirect(route('tickets.show', $issue));

        $year = now()->format('Y');
        $this->assertSame("ISS-{$year}-0001", $issue->ticket_number);
        $this->assertNull($issue->lead_engineer_id);
        $this->assertSame(Ticket::STATUS_OPEN, $issue->status);
        $this->assertNull($issue->steps_to_reproduce);
        $this->assertNull($issue->expected_behavior);
        $this->assertNull($issue->actual_behavior);
        $this->assertCount(1, $issue->attachments);
        Storage::disk('public')->assertExists($issue->attachments->first()->file_path);

        // Notifications sent to active superadmins only (not engineers or requester)
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $superadmin->id,
            'ticket_id' => $issue->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $otherEngineer->id,
            'ticket_id' => $issue->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $issue->id,
        ]);

        // 2. Submit Engineering Request
        $requestResponse = $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => null,
            'title' => 'Automated daily ledger export',
            'description' => 'Need scheduled CSV export at 06:00 daily.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'business_justification' => 'Saves 2 hours of manual finance work daily.',
            'proposed_functionality' => 'Scheduled background job pushing CSV to SFTP.',
            'expected_benefits' => 'Faster morning reconciliation.',
            'requested_deadline' => now()->addDays(14)->toDateString(),
            'attachments' => [
                UploadedFile::fake()->create('ledger-spec.pdf', 18, 'application/pdf'),
            ],
        ]);

        $engRequest = Ticket::where('type', Ticket::TYPE_ENGINEERING_REQUEST)->firstOrFail();
        $requestResponse->assertRedirect(route('tickets.show', $engRequest));
        $this->assertSame("REQ-{$year}-0001", $engRequest->ticket_number);
        $this->assertNull($engRequest->lead_engineer_id);
        $this->assertCount(1, $engRequest->attachments);
        Storage::disk('public')->assertExists($engRequest->attachments->first()->file_path);
    }

    public function test_ticket_visibility_isolation_between_standard_users_and_staff(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $user1 = User::factory()->create(['role' => User::ROLE_USER]);
        $user2 = User::factory()->create(['role' => User::ROLE_USER]);

        $ticketUser2 = Ticket::create([
            'ticket_number' => 'ISS-2026-0101',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $user2->id,
            'title' => 'User2 Private Issue',
            'description' => 'Only user2 and staff should see this.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($user2)->get(route('tickets.show', $ticketUser2))->assertOk();
        $this->actingAs($user1)->get(route('tickets.show', $ticketUser2))->assertForbidden();
        $this->actingAs($user1)->post(route('tickets.comments.store', $ticketUser2), [
            'body' => 'Trying to comment on another user ticket.',
        ])->assertForbidden();

        $this->actingAs($engineer)->get(route('tickets.show', $ticketUser2))->assertOk();
        $this->actingAs($superadmin)->get(route('tickets.show', $ticketUser2))->assertOk();
    }

    public function test_internal_notes_and_internal_attachments_are_hidden_from_standard_users(): void
    {
        Storage::fake('public');

        $leadEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $collabEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0201',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Redis cache inconsistency',
            'description' => 'Stale cache entries observed.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);
        $ticket->collaborators()->attach($collabEngineer->id, ['added_by_id' => $leadEngineer->id]);

        // Lead engineer posts internal note with attachment
        $this->actingAs($leadEngineer)->post(route('tickets.comments.store', $ticket), [
            'body' => 'Internal root cause: Redis TTL misconfigured on prod-worker-02.',
            'is_internal' => true,
            'attachments' => [
                UploadedFile::fake()->create('redis-dump.txt', 5, 'text/plain'),
            ],
        ])->assertRedirect();

        // Standard user posts comment trying to force is_internal = true -> forced false
        $this->actingAs($requester)->post(route('tickets.comments.store', $ticket), [
            'body' => 'Public user follow-up message.',
            'is_internal' => true,
        ])->assertRedirect();

        $userComment = TicketComment::where('user_id', $requester->id)->firstOrFail();
        $this->assertFalse($userComment->is_internal);

        // Requester should NOT see the internal note or internal attachment in Inertia props
        $this->actingAs($requester)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Show')
                ->has('ticket.comments', 1)
                ->where('ticket.comments.0.body', 'Public user follow-up message.')
                ->has('ticket.attachments', 0)
            );

        // Collaborating engineer SHOULD see both comments and receive notification for internal note
        $this->actingAs($collabEngineer)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Show')
                ->has('ticket.comments', 2)
                ->has('ticket.attachments', 1)
            );

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $collabEngineer->id,
            'ticket_id' => $ticket->id,
            'title' => "Internal Note on {$ticket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $ticket->id,
            'title' => "Internal Note on {$ticket->ticket_number}",
        ]);

        // Requester receives 403 when trying to download the internal attachment directly
        $internalAttachment = TicketAttachment::where('is_internal', true)->firstOrFail();
        $this->actingAs($requester)
            ->get(route('attachments.download', $internalAttachment))
            ->assertForbidden();

        // Staff can download internal attachment
        $this->actingAs($collabEngineer)
            ->get(route('attachments.download', $internalAttachment))
            ->assertOk();
    }

    public function test_assignment_workflow_subtasks_and_requester_close_and_reopen(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer1 = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $engineer2 = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        $ticket = Ticket::create([
            'ticket_number' => 'REQ-2026-0301',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $requester->id,
            'title' => 'Add CSV export to audit trail',
            'description' => 'Compliance needs monthly CSV downloads.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        // Assign lead + collaborator (including lead in collaborator_ids to verify automatic exclusion)
        $this->actingAs($superadmin)->patch(route('tickets.assignment.update', $ticket), [
            'lead_engineer_id' => $engineer1->id,
            'collaborator_ids' => [$engineer1->id, $engineer2->id],
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame($engineer1->id, $ticket->lead_engineer_id);
        $this->assertNotNull($ticket->first_responded_at);
        $this->assertEquals([$engineer2->id], $ticket->collaborators()->pluck('users.id')->all());

        // Create and toggle subtask
        $this->actingAs($engineer1)->post(route('tickets.subtasks.store', $ticket), [
            'title' => 'Build streaming CSV exporter',
            'assigned_engineer_id' => $engineer2->id,
        ])->assertRedirect();

        $subtask = TicketSubtask::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertFalse($subtask->is_completed);

        $this->actingAs($engineer2)->patch(route('tickets.subtasks.toggle', [$ticket, $subtask]))->assertRedirect();
        $subtask->refresh();
        $this->assertTrue($subtask->is_completed);
        $this->assertNotNull($subtask->completed_at);

        // Staff moves ticket to resolved
        $this->actingAs($engineer1)->patch(route('tickets.workflow.update', $ticket), [
            'status' => Ticket::STATUS_RESOLVED,
            'priority' => Ticket::PRIORITY_HIGH,
            'resolution_summary' => 'Export endpoint deployed to production.',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_RESOLVED, $ticket->status);
        $this->assertSame(Ticket::PRIORITY_HIGH, $ticket->priority);
        $this->assertNotNull($ticket->resolved_at);

        // Requester confirms and closes ticket
        $this->actingAs($requester)->patch(route('tickets.workflow.update', $ticket), [
            'action' => 'confirm_close',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->status);
        $this->assertNotNull($ticket->closed_at);

        // Requester reopens closed ticket with reason
        $this->actingAs($requester)->patch(route('tickets.workflow.update', $ticket), [
            'action' => 'reopen',
            'reopen_reason' => 'Date range filter is missing in exported CSV.',
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $ticket->status);
        $this->assertNull($ticket->closed_at);
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'user_id' => $requester->id,
            'body' => 'Date range filter is missing in exported CSV.',
            'is_internal' => false,
        ]);
    }

    public function test_rbac_protection_and_admin_endpoints(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $standardUser = User::factory()->create(['role' => User::ROLE_USER]);

        // Standard user blocked from staff & admin endpoints
        $this->actingAs($standardUser)->get(route('tickets.kanban'))->assertForbidden();
        $this->actingAs($standardUser)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($standardUser)->get(route('reports.export'))->assertForbidden();
        $this->actingAs($standardUser)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($standardUser)->get(route('admin.systems.index'))->assertForbidden();

        // Engineer allowed on kanban & reports, blocked from admin
        $this->actingAs($engineer)->get(route('tickets.kanban'))->assertOk();
        $this->actingAs($engineer)->get(route('reports.index'))->assertOk();
        $this->actingAs($engineer)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($engineer)->get(route('admin.systems.index'))->assertForbidden();

        // Superadmin allowed on admin endpoints + self-protection guard
        $this->actingAs($superadmin)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($superadmin)->get(route('admin.systems.index'))->assertOk();

        $this->actingAs($superadmin)->put(route('admin.users.update', $superadmin), [
            'name' => $superadmin->name,
            'username' => $superadmin->username,
            'email' => $superadmin->email,
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => false,
        ])->assertSessionHasErrors('is_active');

        $this->actingAs($superadmin)->put(route('admin.users.update', $superadmin), [
            'name' => $superadmin->name,
            'username' => $superadmin->username,
            'email' => $superadmin->email,
            'role' => User::ROLE_USER,
            'is_active' => true,
        ])->assertSessionHasErrors('role');
    }

    public function test_reports_and_csv_export_succeed_for_engineers_and_superadmins(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'department' => 'Network Operations',
        ]);

        $system = InternalSystem::create([
            'name' => 'NOC Telemetry',
            'is_active' => true,
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0401',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $system->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'OLT SNMP lag',
            'description' => 'Telemetry delayed.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_RESOLVED,
            'first_responded_at' => now()->subHours(2),
            'resolved_at' => now()->subHour(),
        ]);

        $this->actingAs($engineer)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('summary.total_tickets', 1)
                ->where('summary.resolved_closed_count', 1)
                ->where('by_status.resolved', 1)
                ->where('by_priority.critical', 1)
            );

        foreach (['tickets', 'systems', 'engineers', 'departments'] as $section) {
            $response = $this->actingAs($superadmin)->get(route('reports.export', ['section' => $section]));
            $response->assertOk();
            $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        }

        // Notifications mark read & mark all read
        $notification = UserNotification::create([
            'user_id' => $requester->id,
            'title' => 'Test Notification',
            'message' => 'Hello',
            'is_read' => false,
        ]);

        $this->actingAs($requester)->patch(route('notifications.read', $notification))->assertRedirect();
        $this->assertTrue($notification->fresh()->is_read);
    }

    public function test_superadmin_can_create_and_update_internal_system_without_code_or_default_lead(): void
    {
        $superadmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        // Create system with blank category falling back to 'Core Platform'
        $createResponse = $this->actingAs($superadmin)->post(route('admin.systems.store'), [
            'name' => 'Customer Provisioning',
            'category' => '',
            'description' => 'Handles customer onboarding and circuit activation.',
            'is_active' => true,
        ]);

        $createResponse->assertRedirect();
        $createResponse->assertSessionHasNoErrors();

        $system = InternalSystem::where('name', 'Customer Provisioning')->firstOrFail();
        $this->assertSame('Core Platform', $system->category);
        $this->assertSame('Handles customer onboarding and circuit activation.', $system->description);
        $this->assertTrue($system->is_active);

        // Update system with explicit category and inactive flag
        $updateResponse = $this->actingAs($superadmin)->put(route('admin.systems.update', $system), [
            'name' => 'Customer Provisioning v2',
            'category' => 'Operations',
            'description' => 'Updated provisioning workflow.',
            'is_active' => false,
        ]);

        $updateResponse->assertRedirect();
        $updateResponse->assertSessionHasNoErrors();

        $system->refresh();
        $this->assertSame('Customer Provisioning v2', $system->name);
        $this->assertSame('Operations', $system->category);
        $this->assertSame('Updated provisioning workflow.', $system->description);
        $this->assertFalse($system->is_active);

        // Update system with blank category falling back cleanly to 'Core Platform'
        $blankCategoryUpdate = $this->actingAs($superadmin)->put(route('admin.systems.update', $system), [
            'name' => 'Customer Provisioning v2',
            'category' => null,
            'description' => null,
            'is_active' => true,
        ]);

        $blankCategoryUpdate->assertRedirect();
        $blankCategoryUpdate->assertSessionHasNoErrors();

        $system->refresh();
        $this->assertSame('Core Platform', $system->category);
        $this->assertNull($system->description);
        $this->assertTrue($system->is_active);
    }

    public function test_new_ticket_create_page_is_removed_and_internal_systems_are_shared(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        InternalSystem::create([
            'name' => 'Billing Portal',
            'category' => 'Core Revenue',
            'is_active' => true,
        ]);

        InternalSystem::create([
            'name' => 'Audit Logger',
            'category' => 'Compliance',
            'is_active' => true,
        ]);

        InternalSystem::create([
            'name' => 'Retired System',
            'category' => 'Legacy',
            'is_active' => false,
        ]);

        $this->assertFalse(Route::has('tickets.create'));

        $this->actingAs($user)
            ->get('/tickets/create')
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('internalSystems', 2)
                ->where('internalSystems.0.name', 'Audit Logger')
                ->where('internalSystems.1.name', 'Billing Portal')
            );
    }

    public function test_ticket_submission_requires_at_least_one_attachment(): void
    {
        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => 'Missing attachment issue',
            'description' => 'Should fail without attachments.',
            'priority' => Ticket::PRIORITY_HIGH,
            'environment' => 'production',
            'impact_scope' => 'single_user',
        ])->assertSessionHasErrors('attachments');

        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'title' => 'Missing attachment request',
            'description' => 'Should fail without attachments.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'business_justification' => 'Needed for audit.',
            'proposed_functionality' => 'Export endpoint.',
            'expected_benefits' => 'Saves time.',
            'attachments' => [],
        ])->assertSessionHasErrors('attachments');

        $this->assertDatabaseCount('tickets', 0);
    }
}
