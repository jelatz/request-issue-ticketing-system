<?php

namespace Tests\Feature;

use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\TicketActivity;
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
        $response->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', "Signed in as {$user->name}.");
        $this->assertAuthenticatedAs($user);

        // Logout
        $this->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', 'You have been signed out.');
        $this->assertGuest();

        // Login with email
        $response = $this->post(route('login.store'), [
            'login' => 'jdoe@calltek.local',
            'password' => 'secret123',
        ]);
        $response->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', "Signed in as {$user->name}.");
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
        $issueResponse->assertRedirect(route('tickets.show', $issue))
            ->assertSessionHas('success', "Ticket {$issue->ticket_number} created successfully.");

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
        $requestResponse->assertRedirect(route('tickets.show', $engRequest))
            ->assertSessionHas('success', "Ticket {$engRequest->ticket_number} created successfully.");
        $this->assertSame("REQ-{$year}-0001", $engRequest->ticket_number);
        $this->assertNull($engRequest->lead_engineer_id);
        $this->assertCount(1, $engRequest->attachments);
        Storage::disk('public')->assertExists($engRequest->attachments->first()->file_path);
    }

    public function test_ticket_visibility_isolation_between_standard_users_and_staff(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $assignedEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $user1 = User::factory()->create(['role' => User::ROLE_USER]);
        $user2 = User::factory()->create(['role' => User::ROLE_USER]);

        $ticketUser2 = Ticket::create([
            'ticket_number' => 'ISS-2026-0101',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $user2->id,
            'lead_engineer_id' => $assignedEngineer->id,
            'title' => 'User2 Private Issue',
            'description' => 'Only user2, assigned engineers, and superadmin should see this.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($user2)->get(route('tickets.show', $ticketUser2))->assertOk();
        $this->actingAs($user1)->get(route('tickets.show', $ticketUser2))->assertForbidden();
        $this->actingAs($user1)->post(route('tickets.comments.store', $ticketUser2), [
            'body' => 'Trying to comment on another user ticket.',
        ])->assertForbidden();

        $this->actingAs($engineer)->get(route('tickets.show', $ticketUser2))->assertForbidden();
        $this->actingAs($assignedEngineer)->get(route('tickets.show', $ticketUser2))->assertOk();
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
        ])->assertRedirect()
            ->assertSessionHas('success', 'Internal engineering note posted.');

        // Standard user posts comment trying to force is_internal = true -> forced false
        $this->actingAs($requester)->post(route('tickets.comments.store', $ticket), [
            'body' => 'Public user follow-up message.',
            'is_internal' => true,
        ])->assertRedirect()
            ->assertSessionHas('success', 'Comment posted.');

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
        ])->assertRedirect()
            ->assertSessionHas('success', 'Ticket assignment updated.');

        $ticket->refresh();
        $this->assertSame($engineer1->id, $ticket->lead_engineer_id);
        $this->assertNotNull($ticket->first_responded_at);
        $this->assertEquals([$engineer2->id], $ticket->collaborators()->pluck('users.id')->all());

        // Create and toggle subtask
        $this->actingAs($engineer1)->post(route('tickets.subtasks.store', $ticket), [
            'title' => 'Build streaming CSV exporter',
            'assigned_engineer_id' => $engineer2->id,
        ])->assertRedirect()
            ->assertSessionHas('success', 'Subtask added.');

        $subtask = TicketSubtask::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertFalse($subtask->is_completed);

        $this->actingAs($engineer2)->patch(route('tickets.subtasks.toggle', [$ticket, $subtask]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Subtask status updated.');
        $subtask->refresh();
        $this->assertTrue($subtask->is_completed);
        $this->assertNotNull($subtask->completed_at);

        // Staff moves ticket to resolved
        $this->actingAs($engineer1)->patch(route('tickets.workflow.update', $ticket), [
            'status' => Ticket::STATUS_RESOLVED,
            'priority' => Ticket::PRIORITY_HIGH,
            'resolution_summary' => 'Export endpoint deployed to production.',
        ])->assertRedirect()
            ->assertSessionHas('success', 'Ticket workflow updated.');

        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_RESOLVED, $ticket->status);
        $this->assertSame(Ticket::PRIORITY_HIGH, $ticket->priority);
        $this->assertNotNull($ticket->resolved_at);

        // Requester confirms and closes ticket
        $this->actingAs($requester)->patch(route('tickets.workflow.update', $ticket), [
            'action' => 'confirm_close',
        ])->assertRedirect()
            ->assertSessionHas('success', 'Ticket confirmed and closed.');

        $ticket->refresh();
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->status);
        $this->assertNotNull($ticket->closed_at);

        // Requester reopens closed ticket with reason
        $this->actingAs($requester)->patch(route('tickets.workflow.update', $ticket), [
            'action' => 'reopen',
            'reopen_reason' => 'Date range filter is missing in exported CSV.',
        ])->assertRedirect()
            ->assertSessionHas('success', 'Ticket reopened.');

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
                ->has('by_engineer', 1)
                ->where('by_engineer', fn ($rows) => collect($rows)->pluck('id')->contains($engineer->id)
                    && !collect($rows)->pluck('id')->contains($superadmin->id))
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

        $this->actingAs($requester)->patch(route('notifications.read', $notification))
            ->assertRedirect()
            ->assertSessionHas('success', 'Notification marked as read.');
        $this->assertTrue($notification->fresh()->is_read);

        $secondNotification = UserNotification::create([
            'user_id' => $requester->id,
            'title' => 'Second Notification',
            'message' => 'World',
            'is_read' => false,
        ]);

        $this->actingAs($requester)->post(route('notifications.readAll'))
            ->assertRedirect()
            ->assertSessionHas('success', 'All notifications marked as read.');
        $this->assertTrue($secondNotification->fresh()->is_read);
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
        $createResponse->assertSessionHas('success', 'System Customer Provisioning created successfully.');

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
        $updateResponse->assertSessionHas('success', 'System Customer Provisioning v2 updated successfully.');

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
        $blankCategoryUpdate->assertSessionHas('success', 'System Customer Provisioning v2 updated successfully.');

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

    public function test_claim_subtask_destroy_requester_cancel_and_invalid_workflow_transitions_flash_feedback(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        $openTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0501',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Open ticket for workflow tests',
            'description' => 'Testing claim rejection, superadmin assignment, subtask destroy, cancel, and invalid transitions.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        // Self-claim is removed and assignment is superadmin-only
        $this->actingAs($engineer)->patch(route('tickets.assignment.update', $openTicket), [
            'action' => 'claim',
        ])->assertForbidden();

        $this->actingAs($superadmin)->patch(route('tickets.assignment.update', $openTicket), [
            'lead_engineer_id' => $engineer->id,
        ])->assertRedirect()
            ->assertSessionHas('success', 'Ticket assignment updated.');

        $this->assertSame($engineer->id, $openTicket->fresh()->lead_engineer_id);

        // Create and destroy subtask
        $this->actingAs($engineer)->post(route('tickets.subtasks.store', $openTicket), [
            'title' => 'Temporary checklist item',
        ])->assertRedirect()
            ->assertSessionHas('success', 'Subtask added.');

        $subtask = TicketSubtask::where('ticket_id', $openTicket->id)->firstOrFail();

        $this->actingAs($engineer)->delete(route('tickets.subtasks.destroy', [$openTicket, $subtask]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Subtask removed.');

        $this->assertDatabaseMissing('ticket_subtasks', ['id' => $subtask->id]);

        // Invalid confirm_close on open ticket sets both session error and errors.action
        $this->actingAs($requester)->patch(route('tickets.workflow.update', $openTicket), [
            'action' => 'confirm_close',
        ])->assertRedirect()
            ->assertSessionHas('error', 'This ticket cannot be confirmed closed in its current status.')
            ->assertSessionHasErrors('action');

        // Invalid reopen on open ticket sets both session error and errors.action
        $this->actingAs($requester)->patch(route('tickets.workflow.update', $openTicket), [
            'action' => 'reopen',
        ])->assertRedirect()
            ->assertSessionHas('error', 'Only resolved or closed tickets can be reopened.')
            ->assertSessionHasErrors('action');

        // Valid cancel on open ticket flashes success
        $this->actingAs($requester)->patch(route('tickets.workflow.update', $openTicket), [
            'action' => 'cancel',
        ])->assertRedirect()
            ->assertSessionHas('success', 'Ticket cancelled.');

        $this->assertSame(Ticket::STATUS_CANCELLED, $openTicket->fresh()->status);

        // Invalid cancel on closed ticket sets both session error and errors.action
        $closedTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0502',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Closed ticket for cancel guard',
            'description' => 'Should reject cancel.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_CLOSED,
        ]);

        $this->actingAs($requester)->patch(route('tickets.workflow.update', $closedTicket), [
            'action' => 'cancel',
        ])->assertRedirect()
            ->assertSessionHas('error', 'Only open tickets can be cancelled.')
            ->assertSessionHasErrors('action');
    }

    public function test_superadmin_user_create_and_update_flash_success_and_inertia_shares_flash_props(): void
    {
        $superadmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        // Baseline Inertia request with no flash has null flash.id, flash.success, and flash.error
        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.id', null)
                ->where('flash.success', null)
                ->where('flash.error', null)
            );

        // Admin user store flashes success
        $this->actingAs($superadmin)->post(route('admin.users.store'), [
            'name' => 'Alex Rivera',
            'username' => 'arivera',
            'email' => 'arivera@calltek.local',
            'password' => 'secret123',
            'role' => User::ROLE_ENGINEER,
            'department' => 'Infrastructure',
            'job_title' => 'Systems Engineer',
            'is_active' => true,
        ])->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'User Alex Rivera created successfully.');

        $createdUser = User::where('username', 'arivera')->firstOrFail();

        // Admin user update flashes success and shares it on the next Inertia response
        $this->actingAs($superadmin)
            ->followingRedirects()
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $createdUser), [
                'name' => 'Alex Rivera Jr.',
                'username' => 'arivera',
                'email' => 'arivera@calltek.local',
                'role' => User::ROLE_ENGINEER,
                'department' => 'Platform Engineering',
                'job_title' => 'Senior Systems Engineer',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', 'User Alex Rivera Jr. updated successfully.')
                ->where('flash.error', null)
                ->where('flash.id', fn ($id) => is_string($id) && str_starts_with($id, 'flash_'))
            );

        // Following a subsequent Inertia request without new flash returns null flash props
        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.id', null)
                ->where('flash.success', null)
                ->where('flash.error', null)
            );

        // Following a redirect after flash.success shares flash.id, flash.success, and null flash.error
        $firstFlashId = null;
        $this->actingAs($superadmin)
            ->followingRedirects()
            ->from(route('dashboard'))
            ->post(route('notifications.readAll'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$firstFlashId): void {
                $page->where('flash.success', 'All notifications marked as read.')
                    ->where('flash.error', null)
                    ->where('flash.id', function ($id) use (&$firstFlashId): bool {
                        $this->assertIsString($id);
                        $this->assertStringStartsWith('flash_', $id);
                        $firstFlashId = $id;

                        return true;
                    });
            });

        // Repeating the identical flash message generates a distinct flash.id token
        $this->actingAs($superadmin)
            ->followingRedirects()
            ->from(route('dashboard'))
            ->post(route('notifications.readAll'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($firstFlashId): void {
                $page->where('flash.success', 'All notifications marked as read.')
                    ->where('flash.error', null)
                    ->where('flash.id', function ($id) use ($firstFlashId): bool {
                        $this->assertIsString($id);
                        $this->assertNotSame($firstFlashId, $id);

                        return true;
                    });
            });

        // Following a redirect after flash.error shares flash.id and flash.error
        $standardRequester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0601',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $standardRequester->id,
            'title' => 'Flash error inertia check',
            'description' => 'Verify flash.error prop sharing.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($standardRequester)
            ->followingRedirects()
            ->from(route('tickets.show', $ticket))
            ->patch(route('tickets.workflow.update', $ticket), [
                'action' => 'confirm_close',
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', null)
                ->where('flash.error', 'This ticket cannot be confirmed closed in its current status.')
                ->where('flash.id', fn ($id) => is_string($id) && str_starts_with($id, 'flash_'))
                ->where('errors.action', 'This ticket cannot be confirmed closed in its current status.')
            );
    }

    public function test_kanban_loads_selected_ticket_details_and_permissions_when_ticket_query_present(): void
    {
        $engineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $system = InternalSystem::create([
            'name' => 'Telemetry Hub',
            'category' => 'Observability',
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0701',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $system->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Kanban selected ticket inspection',
            'description' => 'Full detail payload should load when ticket query parameter is present.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        TicketSubtask::create([
            'ticket_id' => $ticket->id,
            'title' => 'Inspect telemetry queue',
            'assigned_engineer_id' => $engineer->id,
            'is_completed' => false,
            'created_by_id' => $engineer->id,
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $requester->id,
            'body' => 'Public requester follow-up.',
            'is_internal' => false,
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $engineer->id,
            'body' => 'Internal triage note for staff.',
            'is_internal' => true,
        ]);

        TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'uploaded_by_id' => $requester->id,
            'original_name' => 'stacktrace.log',
            'file_path' => "tickets/{$ticket->id}/stacktrace.log",
            'mime_type' => 'text/plain',
            'file_size' => 128,
            'is_internal' => false,
        ]);

        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $engineer->id,
            'action_type' => 'ticket_created',
            'description' => 'Ticket submitted for triage.',
        ]);

        $this->actingAs($engineer)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->where('selectedTicket', null)
                ->where('selectedTicketPermissions', null)
            );

        $this->actingAs($engineer)
            ->get(route('tickets.kanban', ['ticket' => $ticket->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->where('selectedTicket.id', $ticket->id)
                ->where('selectedTicket.internal_system.id', $system->id)
                ->where('selectedTicket.requester.id', $requester->id)
                ->has('selectedTicket.subtasks', 1)
                ->has('selectedTicket.comments', 2)
                ->where('selectedTicket.comments.0.body', 'Public requester follow-up.')
                ->where('selectedTicket.comments.1.body', 'Internal triage note for staff.')
                ->where('selectedTicket.comments.1.is_internal', true)
                ->has('selectedTicket.attachments', 1)
                ->has('selectedTicket.activities', 1)
                ->where('selectedTicketPermissions', [
                    'can_manage_workflow' => true,
                    'can_assign' => false,
                    'can_post_internal_note' => true,
                    'can_manage_subtasks' => true,
                    'can_close_or_reopen' => true,
                    'can_cancel' => true,
                ])
            );

        $this->actingAs($engineer)
            ->get(route('tickets.kanban', ['ticket' => 999999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->where('selectedTicket', null)
                ->where('selectedTicketPermissions', null)
            );
    }

    public function test_kanban_modal_mutations_preserve_selected_ticket_and_refresh_board_columns(): void
    {
        $engineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0702',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Kanban modal mutation flow',
            'description' => 'Verify back() redirects refresh both selectedTicket and Kanban columns.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $kanbanModalUrl = route('tickets.kanban', ['ticket' => $ticket->id, 'priority' => 'high']);

        $this->actingAs($engineer)
            ->followingRedirects()
            ->from($kanbanModalUrl)
            ->patch(route('tickets.workflow.update', $ticket), [
                'status' => Ticket::STATUS_IN_PROGRESS,
                'resolution_summary' => 'Investigating worker pool saturation.',
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->where('selectedTicket.id', $ticket->id)
                ->where('selectedTicket.status', Ticket::STATUS_IN_PROGRESS)
                ->where('selectedTicket.resolution_summary', 'Investigating worker pool saturation.')
                ->has('columns.open', 0)
                ->has('columns.in_progress', 1)
                ->where('columns.in_progress.0.id', $ticket->id)
                ->where('flash.success', 'Ticket workflow updated.')
            );

        $this->actingAs($engineer)
            ->followingRedirects()
            ->from($kanbanModalUrl)
            ->post(route('tickets.comments.store', $ticket), [
                'body' => 'Checked worker pool metrics from modal.',
                'is_internal' => true,
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->where('selectedTicket.id', $ticket->id)
                ->has('selectedTicket.comments', 1)
                ->where('selectedTicket.comments.0.body', 'Checked worker pool metrics from modal.')
                ->where('selectedTicket.comments.0.is_internal', true)
                ->where('flash.success', 'Internal engineering note posted.')
            );

        $this->actingAs($engineer)
            ->followingRedirects()
            ->from($kanbanModalUrl)
            ->post(route('tickets.subtasks.store', $ticket), [
                'title' => 'Restart metrics collector',
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->where('selectedTicket.id', $ticket->id)
                ->has('selectedTicket.subtasks', 1)
                ->where('selectedTicket.subtasks.0.title', 'Restart metrics collector')
                ->where('columns.in_progress.0.subtasks_count', 1)
                ->where('flash.success', 'Subtask added.')
            );

        $this->actingAs($engineer)
            ->followingRedirects()
            ->from($kanbanModalUrl)
            ->patch(route('tickets.workflow.update', $ticket), [
                'status' => Ticket::STATUS_CANCELLED,
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->where('selectedTicket.id', $ticket->id)
                ->where('selectedTicket.status', Ticket::STATUS_CANCELLED)
                ->has('columns.open', 0)
                ->has('columns.under_review', 0)
                ->has('columns.in_progress', 0)
                ->has('columns.waiting_on_user', 0)
                ->has('columns.resolved', 0)
                ->where('flash.success', 'Ticket workflow updated.')
            );
    }

    public function test_only_superadmin_can_update_ticket_assignment_and_claim_action_is_removed(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0801',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Assignment authorization test ticket',
            'description' => 'Only superadmin may assign tickets.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($engineer)->patch(route('tickets.assignment.update', $ticket), [
            'lead_engineer_id' => $engineer->id,
        ])->assertForbidden();

        $this->actingAs($engineer)->patch(route('tickets.assignment.update', $ticket), [
            'action' => 'claim',
        ])->assertForbidden();

        $this->actingAs($requester)->patch(route('tickets.assignment.update', $ticket), [
            'lead_engineer_id' => $engineer->id,
        ])->assertForbidden();

        $this->actingAs($superadmin)->patch(route('tickets.assignment.update', $ticket), [
            'action' => 'claim',
        ])->assertRedirect();

        $this->assertNull($ticket->fresh()->lead_engineer_id);

        $this->actingAs($superadmin)->patch(route('tickets.assignment.update', $ticket), [
            'lead_engineer_id' => $engineer->id,
        ])->assertRedirect()
            ->assertSessionHas('success', 'Ticket assignment updated.');

        $this->assertSame($engineer->id, $ticket->fresh()->lead_engineer_id);

        $this->actingAs($superadmin)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Show')
                ->where('permissions.can_assign', true)
            );

        $this->actingAs($engineer)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Show')
                ->where('permissions.can_assign', false)
            );
    }

    public function test_assignment_and_subtask_pools_reject_superadmins_standard_users_and_inactive_engineers(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN, 'is_active' => true]);
        $activeEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER, 'is_active' => true]);
        $inactiveEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER, 'is_active' => false]);
        $standardUser = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0802',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $standardUser->id,
            'lead_engineer_id' => $activeEngineer->id,
            'title' => 'Engineer-only pool validation ticket',
            'description' => 'Validate assignable user pools.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        foreach ([$superadmin->id, $standardUser->id, $inactiveEngineer->id] as $invalidAssigneeId) {
            $this->actingAs($superadmin)
                ->patch(route('tickets.assignment.update', $ticket), [
                    'lead_engineer_id' => $invalidAssigneeId,
                ])
                ->assertSessionHasErrors('lead_engineer_id');

            $this->actingAs($superadmin)
                ->patch(route('tickets.assignment.update', $ticket), [
                    'lead_engineer_id' => $activeEngineer->id,
                    'collaborator_ids' => [$invalidAssigneeId],
                ])
                ->assertSessionHasErrors('collaborator_ids.0');

            $this->actingAs($superadmin)
                ->post(route('tickets.subtasks.store', $ticket), [
                    'title' => 'Invalid subtask assignee test',
                    'assigned_engineer_id' => $invalidAssigneeId,
                ])
                ->assertSessionHasErrors('assigned_engineer_id');
        }

        $assertEngineerPoolOnly = function (Assert $page) use ($activeEngineer, $superadmin, $inactiveEngineer, $standardUser): void {
            $page->where('engineers', function ($engineers) use ($activeEngineer, $superadmin, $inactiveEngineer, $standardUser): bool {
                $ids = collect($engineers)->pluck('id');

                return $ids->contains($activeEngineer->id)
                    && !$ids->contains($superadmin->id)
                    && !$ids->contains($inactiveEngineer->id)
                    && !$ids->contains($standardUser->id);
            });
        };

        $this->actingAs($superadmin)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $assertEngineerPoolOnly($page->component('Tickets/Index')));

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $assertEngineerPoolOnly($page->component('Tickets/Kanban')));

        $this->actingAs($superadmin)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $assertEngineerPoolOnly($page->component('Tickets/Show')));
    }

    public function test_unassigned_engineer_is_forbidden_from_viewing_or_mutating_unassigned_tickets(): void
    {
        Storage::fake('public');

        $unassignedEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $otherEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        $unassignedTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0803',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Completely unassigned ticket',
            'description' => 'Unassigned engineers must not see or mutate this.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $otherAssignedTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0804',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'title' => 'Other engineer ticket',
            'description' => 'Assigned to another engineer.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        foreach ([$unassignedTicket, $otherAssignedTicket] as $ticket) {
            Storage::disk('public')->put("tickets/{$ticket->id}/diag.txt", 'diagnostic log');
            $attachment = TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'uploaded_by_id' => $requester->id,
                'original_name' => 'diag.txt',
                'file_path' => "tickets/{$ticket->id}/diag.txt",
                'mime_type' => 'text/plain',
                'file_size' => 14,
                'is_internal' => false,
            ]);

            $subtask = TicketSubtask::create([
                'ticket_id' => $ticket->id,
                'title' => 'Protected subtask',
                'is_completed' => false,
                'created_by_id' => $otherEngineer->id,
            ]);

            $this->actingAs($unassignedEngineer)->get(route('tickets.show', $ticket))->assertForbidden();
            $this->actingAs($unassignedEngineer)->patch(route('tickets.workflow.update', $ticket), [
                'status' => Ticket::STATUS_IN_PROGRESS,
            ])->assertForbidden();
            $this->actingAs($unassignedEngineer)->post(route('tickets.comments.store', $ticket), [
                'body' => 'Unauthorized comment attempt',
            ])->assertForbidden();
            $this->actingAs($unassignedEngineer)->get(route('attachments.download', $attachment))->assertForbidden();
            $this->actingAs($unassignedEngineer)->post(route('tickets.subtasks.store', $ticket), [
                'title' => 'Unauthorized subtask',
            ])->assertForbidden();
            $this->actingAs($unassignedEngineer)->patch(route('tickets.subtasks.toggle', [$ticket, $subtask]))
                ->assertForbidden();
            $this->actingAs($unassignedEngineer)->delete(route('tickets.subtasks.destroy', [$ticket, $subtask]))
                ->assertForbidden();

            $this->actingAs($unassignedEngineer)
                ->get(route('tickets.kanban', ['ticket' => $ticket->id]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Tickets/Kanban')
                    ->where('selectedTicket', null)
                    ->where('selectedTicketPermissions', null)
                );
        }
    }

    public function test_collaborating_engineer_retains_work_access_without_assignment_rights(): void
    {
        Storage::fake('public');

        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $leadEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $collabEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0805',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Collaborative ticket work access',
            'description' => 'Collaborating engineers can work on assigned tickets without reassigning.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $ticket->collaborators()->attach($collabEngineer->id, ['added_by_id' => $superadmin->id]);

        Storage::disk('public')->put("tickets/{$ticket->id}/collab-log.txt", 'collab diagnostic');
        $attachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'uploaded_by_id' => $requester->id,
            'original_name' => 'collab-log.txt',
            'file_path' => "tickets/{$ticket->id}/collab-log.txt",
            'mime_type' => 'text/plain',
            'file_size' => 17,
            'is_internal' => false,
        ]);

        $this->actingAs($collabEngineer)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Show')
                ->where('permissions.can_assign', false)
                ->where('permissions.can_manage_workflow', true)
                ->where('permissions.can_post_internal_note', true)
                ->where('permissions.can_manage_subtasks', true)
            );

        $this->actingAs($collabEngineer)->patch(route('tickets.workflow.update', $ticket), [
            'status' => Ticket::STATUS_IN_PROGRESS,
        ])->assertRedirect();
        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $ticket->fresh()->status);

        $this->actingAs($collabEngineer)->post(route('tickets.comments.store', $ticket), [
            'body' => 'Collaborator internal note',
            'is_internal' => true,
        ])->assertRedirect();

        $this->actingAs($collabEngineer)->post(route('tickets.subtasks.store', $ticket), [
            'title' => 'Collaborator checklist item',
        ])->assertRedirect();

        $subtask = TicketSubtask::where('ticket_id', $ticket->id)->firstOrFail();

        $this->actingAs($collabEngineer)->patch(route('tickets.subtasks.toggle', [$ticket, $subtask]))
            ->assertRedirect();
        $this->assertTrue($subtask->fresh()->is_completed);

        $this->actingAs($collabEngineer)->delete(route('tickets.subtasks.destroy', [$ticket, $subtask]))
            ->assertRedirect();
        $this->assertDatabaseMissing('ticket_subtasks', ['id' => $subtask->id]);

        $this->actingAs($collabEngineer)->get(route('attachments.download', $attachment))->assertOk();

        $this->actingAs($collabEngineer)->patch(route('tickets.assignment.update', $ticket), [
            'lead_engineer_id' => $collabEngineer->id,
        ])->assertForbidden();
    }

    public function test_unassigning_engineer_immediately_revokes_access_and_hides_tickets_from_dashboard_lists_and_kanban(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0806',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Revocation upon unassignment ticket',
            'description' => 'Access and visibility must vanish immediately when unassigned.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($engineer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('metrics.total_visible', 1)
                ->has('attention_tickets', 1)
                ->where('attention_tickets.0.id', $ticket->id)
                ->has('recent_tickets', 1)
                ->where('recent_tickets.0.id', $ticket->id)
            );

        $this->actingAs($engineer)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $ticket->id)
            );

        $this->actingAs($engineer)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->has('columns.open', 1)
                ->where('columns.open.0.id', $ticket->id)
            );

        $this->actingAs($superadmin)->patch(route('tickets.assignment.update', $ticket), [
            'lead_engineer_id' => null,
            'collaborator_ids' => [],
        ])->assertRedirect()
            ->assertSessionHas('success', 'Ticket assignment updated.');

        $this->actingAs($engineer)->get(route('tickets.show', $ticket))->assertForbidden();
        $this->actingAs($engineer)->patch(route('tickets.workflow.update', $ticket), [
            'status' => Ticket::STATUS_IN_PROGRESS,
        ])->assertForbidden();
        $this->actingAs($engineer)->post(route('tickets.comments.store', $ticket), [
            'body' => 'Should be forbidden after unassignment.',
        ])->assertForbidden();
        $this->actingAs($engineer)->post(route('tickets.subtasks.store', $ticket), [
            'title' => 'Should be forbidden after unassignment.',
        ])->assertForbidden();

        $this->actingAs($engineer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('metrics.total_visible', 0)
                ->has('attention_tickets', 0)
                ->has('recent_tickets', 0)
            );

        $this->actingAs($engineer)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 0)
            );

        $this->actingAs($engineer)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->has('columns.open', 0)
            );

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('metrics.total_visible', 1)
                ->has('attention_tickets', 1)
                ->has('recent_tickets', 1)
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 1)
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->has('columns.open', 1)
            );

        $this->actingAs($superadmin)->get(route('tickets.show', $ticket))->assertOk();
    }

    public function test_engineer_ticket_submission_redirects_to_index_when_unassigned(): void
    {
        Storage::fake('public');

        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN, 'is_active' => true]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER, 'is_active' => true]);

        $response = $this->actingAs($engineer)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => 'Engineer submitted issue',
            'description' => 'Testing redirect when submitted ticket is not immediately assigned.',
            'priority' => Ticket::PRIORITY_HIGH,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('evidence.png', 10, 'image/png'),
            ],
        ]);

        $ticket = Ticket::where('title', 'Engineer submitted issue')->firstOrFail();

        $this->assertNull($ticket->lead_engineer_id);
        $this->assertSame($engineer->id, $ticket->requester_id);

        $response->assertRedirect(route('tickets.index'))
            ->assertSessionHas('success', "Ticket {$ticket->ticket_number} created successfully.");

        $this->actingAs($superadmin)->get(route('tickets.show', $ticket))->assertOk();
    }

    public function test_reports_and_csv_export_are_scoped_for_engineers_and_unscoped_for_superadmins(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $otherEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'department' => 'Support',
        ]);

        $assignedTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0901',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Assigned engineer ticket',
            'description' => 'Visible in engineer report.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        $unassignedTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0902',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => null,
            'title' => 'Unassigned ticket',
            'description' => 'Hidden from engineer report.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $otherTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0903',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'title' => 'Other engineer ticket',
            'description' => 'Hidden from engineer report.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_RESOLVED,
        ]);

        $this->actingAs($engineer)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('summary.total_tickets', 1)
                ->where('by_status.in_progress', 1)
                ->where('by_status.open', 0)
                ->where('by_status.resolved', 0)
            );

        $engineerCsv = $this->actingAs($engineer)
            ->get(route('reports.export', ['section' => 'tickets']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($assignedTicket->ticket_number, $engineerCsv);
        $this->assertStringNotContainsString($unassignedTicket->ticket_number, $engineerCsv);
        $this->assertStringNotContainsString($otherTicket->ticket_number, $engineerCsv);

        $this->actingAs($superadmin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('summary.total_tickets', 3)
            );

        $superadminCsv = $this->actingAs($superadmin)
            ->get(route('reports.export', ['section' => 'tickets']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($assignedTicket->ticket_number, $superadminCsv);
        $this->assertStringContainsString($unassignedTicket->ticket_number, $superadminCsv);
        $this->assertStringContainsString($otherTicket->ticket_number, $superadminCsv);
    }

    public function test_unassigned_engineer_receives_forbidden_before_not_found_on_cross_ticket_subtask_routes(): void
    {
        $unassignedEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $otherEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        $firstTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0904',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'title' => 'First ticket',
            'description' => 'Not assigned to unassignedEngineer.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $secondTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0905',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'title' => 'Second ticket',
            'description' => 'Owns the subtask.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $subtask = TicketSubtask::create([
            'ticket_id' => $secondTicket->id,
            'title' => 'Subtask on second ticket',
            'is_completed' => false,
            'created_by_id' => $otherEngineer->id,
        ]);

        $this->actingAs($unassignedEngineer)
            ->patch(route('tickets.subtasks.toggle', [$firstTicket, $subtask]))
            ->assertForbidden();

        $this->actingAs($unassignedEngineer)
            ->delete(route('tickets.subtasks.destroy', [$firstTicket, $subtask]))
            ->assertForbidden();
    }
}
