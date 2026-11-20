<?php

namespace Tests\Feature;

use App\Mail\TicketNotificationMail;
use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\TicketSubtask;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationService;
use App\Services\TicketService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        Storage::fake((string) config('filesystems.attachments_disk'));

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
        Storage::disk((string) config('filesystems.attachments_disk'))->assertExists($issue->attachments->first()->file_path);

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
            ->assertSessionHas('success', "Ticket {$engRequest->ticket_number} submitted and is pending director approval.");
        $this->assertSame("REQ-{$year}-0001", $engRequest->ticket_number);
        $this->assertSame(Ticket::STATUS_PENDING_APPROVAL, $engRequest->status);
        $this->assertSame(Ticket::APPROVAL_STATUS_PENDING, $engRequest->approval_status);
        $this->assertNull($engRequest->lead_engineer_id);
        $this->assertCount(1, $engRequest->attachments);
        Storage::disk((string) config('filesystems.attachments_disk'))->assertExists($engRequest->attachments->first()->file_path);
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
        Storage::fake((string) config('filesystems.attachments_disk'));

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
                    && ! collect($rows)->pluck('id')->contains($superadmin->id))
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
                    'can_approve_request' => false,
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
                    && ! $ids->contains($superadmin->id)
                    && ! $ids->contains($inactiveEngineer->id)
                    && ! $ids->contains($standardUser->id);
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
        Storage::fake((string) config('filesystems.attachments_disk'));

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
            Storage::disk((string) config('filesystems.attachments_disk'))->put("tickets/{$ticket->id}/diag.txt", 'diagnostic log');
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
        Storage::fake((string) config('filesystems.attachments_disk'));

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

        Storage::disk((string) config('filesystems.attachments_disk'))->put("tickets/{$ticket->id}/collab-log.txt", 'collab diagnostic');
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
        Storage::fake((string) config('filesystems.attachments_disk'));

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

    public function test_database_seeder_only_seeds_superadmin_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertTrue(
            User::where('username', 'superadmin')
                ->where('role', User::ROLE_SUPERADMIN)
                ->exists()
        );

        foreach (['engineer1', 'engineer2', 'engineer3', 'user1', 'user2', 'user3'] as $removedUsername) {
            $this->assertFalse(
                User::where('username', $removedUsername)->exists(),
                "Seeded user {$removedUsername} should not be created."
            );
        }
    }

    public function test_admin_user_store_and_update_enforce_case_insensitive_unique_username_and_email_and_normalize_to_lowercase(): void
    {
        $superadmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'User One',
                'username' => '  Username_One  ',
                'email' => '  User.One@CallTek.Local  ',
                'password' => 'secret123',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'username' => 'username_one',
            'email' => 'user.one@calltek.local',
        ]);

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Duplicate Case User',
                'username' => 'USERNAME_ONE',
                'email' => 'USER.ONE@CALLTEK.LOCAL',
                'password' => 'secret123',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['username', 'email']);

        $legacyUser = User::factory()->create([
            'username' => 'Legacy_User',
            'email' => 'Legacy@CallTek.Local',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Legacy Clone',
                'username' => 'legacy_user',
                'email' => 'legacy@calltek.local',
                'password' => 'secret123',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['username', 'email']);

        $this->actingAs($superadmin)
            ->put(route('admin.users.update', $legacyUser), [
                'name' => $legacyUser->name,
                'username' => 'legacy_user',
                'email' => 'legacy@calltek.local',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'id' => $legacyUser->id,
            'username' => 'legacy_user',
            'email' => 'legacy@calltek.local',
        ]);

        $this->actingAs($superadmin)
            ->put(route('admin.users.update', $legacyUser), [
                'name' => $legacyUser->name,
                'username' => 'USERNAME_ONE',
                'email' => 'USER.ONE@CALLTEK.LOCAL',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['username', 'email']);
    }

    public function test_users_can_login_case_insensitively_with_username_or_email(): void
    {
        $user = User::factory()->create([
            'username' => 'jdoe',
            'email' => 'jdoe@calltek.local',
            'password' => 'secret123',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->post(route('login.store'), [
            'login' => 'JDOE',
            'password' => 'secret123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login.store'), [
            'login' => 'JDOE@CALLTEK.LOCAL',
            'password' => 'secret123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_user_store_and_update_reject_tldless_emails_while_allowing_internal_local_domains(): void
    {
        $superadmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        foreach (['qa@localhost', 'user@company', 'not-an-email', 'qa@invalid..com'] as $index => $invalidEmail) {
            $this->actingAs($superadmin)
                ->post(route('admin.users.store'), [
                    'name' => "Invalid Email {$index}",
                    'username' => "invalid_email_{$index}",
                    'email' => $invalidEmail,
                    'password' => 'secret123',
                    'role' => User::ROLE_USER,
                    'is_active' => true,
                ])
                ->assertSessionHasErrors('email');

            $this->actingAs($superadmin)
                ->put(route('admin.users.update', $target), [
                    'name' => $target->name,
                    'username' => $target->username,
                    'email' => $invalidEmail,
                    'role' => $target->role,
                    'is_active' => true,
                ])
                ->assertSessionHasErrors('email');
        }

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'QA Manager',
                'username' => 'qa_manager_subaddr',
                'email' => 'qa.manager+test@calltek.local',
                'password' => 'secret123',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Ops User',
                'username' => 'ops_calltekcenter',
                'email' => 'ops@mail2.calltekcenter.com',
                'password' => 'secret123',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_admin_user_store_and_update_reject_whitespace_only_passwords_and_allow_passphrases(): void
    {
        $superadmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $target = User::factory()->create([
            'username' => 'pass_target',
            'email' => 'pass.target@calltek.local',
            'password' => 'original123',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Whitespace Password User',
                'username' => 'whitespace_pw_user',
                'email' => 'whitespace.pw@calltek.local',
                'password' => '      ',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('password');

        $originalHash = $target->password;

        $this->actingAs($superadmin)
            ->put(route('admin.users.update', $target), [
                'name' => $target->name,
                'username' => $target->username,
                'email' => $target->email,
                'password' => '      ',
                'role' => $target->role,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame($originalHash, $target->fresh()->password);

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Passphrase User',
                'username' => 'passphrase_user',
                'email' => 'passphrase@calltek.local',
                'password' => 'my pass 123',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login.store'), [
            'login' => 'passphrase_user',
            'password' => 'my pass 123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_dashboard_baseline_root_redirect_and_metrics_across_all_statuses_types_priorities_and_overdue_boundaries(): void
    {
        // TC-01: Guest redirects
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        // TC-02: Seeded superadmin root redirect and empty dashboard baseline
        $this->seed(DatabaseSeeder::class);
        $superadmin = User::where('username', 'superadmin')->firstOrFail();

        $this->actingAs($superadmin)
            ->get('/')
            ->assertRedirect(route('dashboard'));

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('auth.user.department', 'IT Leadership')
                ->where('auth.user.is_staff', true)
                ->where('metrics.total_visible', 0)
                ->where('metrics.open_count', 0)
                ->where('metrics.under_review_count', 0)
                ->where('metrics.in_progress_count', 0)
                ->where('metrics.waiting_on_user_count', 0)
                ->where('metrics.resolved_count', 0)
                ->where('metrics.closed_count', 0)
                ->where('metrics.critical_high_active_count', 0)
                ->where('metrics.issues_count', 0)
                ->where('metrics.engineering_requests_count', 0)
                ->where('metrics.overdue_count', 0)
                ->has('recent_tickets', 0)
                ->has('attention_tickets', 0)
                ->has('recent_activities', 0)
            );

        // TC-03: Metrics aggregation across all 8 statuses, both types, and active critical/high priority
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        $seededTickets = [
            ['ISS-2026-0001', Ticket::TYPE_ISSUE, Ticket::STATUS_OPEN, Ticket::PRIORITY_CRITICAL],
            ['ISS-2026-0002', Ticket::TYPE_ISSUE, Ticket::STATUS_UNDER_REVIEW, Ticket::PRIORITY_HIGH],
            ['ISS-2026-0003', Ticket::TYPE_ISSUE, Ticket::STATUS_IN_PROGRESS, Ticket::PRIORITY_MEDIUM],
            ['ISS-2026-0004', Ticket::TYPE_ISSUE, Ticket::STATUS_WAITING_ON_USER, Ticket::PRIORITY_LOW],
            ['REQ-2026-0001', Ticket::TYPE_ENGINEERING_REQUEST, Ticket::STATUS_RESOLVED, Ticket::PRIORITY_CRITICAL],
            ['REQ-2026-0002', Ticket::TYPE_ENGINEERING_REQUEST, Ticket::STATUS_CLOSED, Ticket::PRIORITY_HIGH],
            ['REQ-2026-0003', Ticket::TYPE_ENGINEERING_REQUEST, Ticket::STATUS_REJECTED, Ticket::PRIORITY_CRITICAL],
            ['REQ-2026-0004', Ticket::TYPE_ENGINEERING_REQUEST, Ticket::STATUS_CANCELLED, Ticket::PRIORITY_HIGH],
        ];

        foreach ($seededTickets as [$ticketNumber, $type, $status, $priority]) {
            Ticket::create([
                'ticket_number' => $ticketNumber,
                'type' => $type,
                'requester_id' => $requester->id,
                'title' => "Ticket {$ticketNumber}",
                'description' => "Description for {$ticketNumber}",
                'priority' => $priority,
                'status' => $status,
            ]);
        }

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.total_visible', 8)
                ->where('metrics.open_count', 1)
                ->where('metrics.under_review_count', 1)
                ->where('metrics.in_progress_count', 1)
                ->where('metrics.waiting_on_user_count', 1)
                ->where('metrics.resolved_count', 1)
                ->where('metrics.closed_count', 1)
                ->where('metrics.critical_high_active_count', 2)
                ->where('metrics.issues_count', 4)
                ->where('metrics.engineering_requests_count', 4)
            );

        // TC-07: Overdue SLA boundary conditions
        Ticket::query()->delete();

        $overdueTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0040',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Overdue active ticket',
            'description' => 'Target due date was yesterday.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
            'target_due_date' => today()->subDay()->toDateString(),
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0041',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Due today active ticket',
            'description' => 'Target due date is today.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'target_due_date' => today()->toDateString(),
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0042',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Future due active ticket',
            'description' => 'Target due date is tomorrow.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_WAITING_ON_USER,
            'target_due_date' => today()->addDay()->toDateString(),
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0043',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Null due date active ticket',
            'description' => 'Target due date is null.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_UNDER_REVIEW,
            'target_due_date' => null,
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0044',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Past due resolved ticket',
            'description' => 'Resolved tickets should not count as overdue.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_RESOLVED,
            'target_due_date' => today()->subDays(5)->toDateString(),
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0045',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Past due closed ticket',
            'description' => 'Closed tickets should not count as overdue.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_CLOSED,
            'target_due_date' => today()->subDays(5)->toDateString(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.overdue_count', 1)
            );

        $overdueTicket->update(['status' => Ticket::STATUS_RESOLVED]);

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.overdue_count', 0)
            );
    }

    public function test_dashboard_attention_tickets_role_filtering_ordering_and_limits_plus_recent_tickets_and_activities_caps(): void
    {
        // TC-04: Superadmin attention_tickets filtering and priority ordering
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN]);
        $engineerA = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $requester = User::factory()->create(['role' => User::ROLE_USER]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0010',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => null,
            'title' => 'Unassigned open high',
            'description' => 'Unassigned open ticket.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0011',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $superadmin->id,
            'title' => 'Superadmin lead critical',
            'description' => 'Lead is superadmin.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        $collabTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0012',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineerA->id,
            'title' => 'Superadmin collab medium',
            'description' => 'Superadmin is collaborator.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_WAITING_ON_USER,
        ]);
        $collabTicket->collaborators()->attach($superadmin->id, ['added_by_id' => $engineerA->id]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0013',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineerA->id,
            'title' => 'Other engineer critical',
            'description' => 'Should not appear in superadmin attention_tickets.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0014',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => null,
            'title' => 'Unassigned under review critical',
            'description' => 'Under review unassigned should not appear.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_UNDER_REVIEW,
        ]);

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('attention_tickets', 3)
                ->where('attention_tickets.0.ticket_number', 'ISS-2026-0011')
                ->where('attention_tickets.1.ticket_number', 'ISS-2026-0010')
                ->where('attention_tickets.2.ticket_number', 'ISS-2026-0012')
            );

        // TC-05: Engineer metrics & attention_tickets
        Ticket::query()->delete();

        $engineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'department' => 'NOC',
        ]);
        $otherEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0020',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Engineer lead open low',
            'description' => 'Assigned to engineer.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $engineerCollabTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0021',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'title' => 'Engineer collab under review critical',
            'description' => 'Collaborator ticket.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_UNDER_REVIEW,
        ]);
        $engineerCollabTicket->collaborators()->attach($engineer->id, ['added_by_id' => $otherEngineer->id]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0022',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Engineer lead resolved high',
            'description' => 'Visible in metrics, excluded from attention_tickets.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_RESOLVED,
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0023',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => null,
            'title' => 'Unassigned open critical',
            'description' => 'Not visible to engineer.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($engineer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.department', 'NOC')
                ->where('metrics.total_visible', 3)
                ->has('attention_tickets', 2)
                ->where('attention_tickets.0.ticket_number', 'ISS-2026-0021')
                ->where('attention_tickets.1.ticket_number', 'ISS-2026-0020')
            );

        // TC-06: Standard User attention_tickets status + priority ordering
        Ticket::query()->delete();

        $standardUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'department' => 'Finance',
        ]);

        $standardTickets = [
            ['ISS-2026-0030', Ticket::STATUS_OPEN, Ticket::PRIORITY_CRITICAL],
            ['ISS-2026-0031', Ticket::STATUS_RESOLVED, Ticket::PRIORITY_LOW],
            ['ISS-2026-0032', Ticket::STATUS_WAITING_ON_USER, Ticket::PRIORITY_MEDIUM],
            ['ISS-2026-0033', Ticket::STATUS_WAITING_ON_USER, Ticket::PRIORITY_HIGH],
            ['ISS-2026-0034', Ticket::STATUS_IN_PROGRESS, Ticket::PRIORITY_CRITICAL],
            ['ISS-2026-0035', Ticket::STATUS_CLOSED, Ticket::PRIORITY_CRITICAL],
        ];

        foreach ($standardTickets as [$ticketNumber, $status, $priority]) {
            Ticket::create([
                'ticket_number' => $ticketNumber,
                'type' => Ticket::TYPE_ISSUE,
                'requester_id' => $standardUser->id,
                'title' => "User ticket {$ticketNumber}",
                'description' => "Description {$ticketNumber}",
                'priority' => $priority,
                'status' => $status,
            ]);
        }

        $this->actingAs($standardUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.department', 'Finance')
                ->where('auth.user.is_staff', false)
                ->where('metrics.total_visible', 6)
                ->has('attention_tickets', 4)
                ->where('attention_tickets.0.ticket_number', 'ISS-2026-0033')
                ->where('attention_tickets.1.ticket_number', 'ISS-2026-0032')
                ->where('attention_tickets.2.ticket_number', 'ISS-2026-0031')
                ->where('attention_tickets.3.ticket_number', 'ISS-2026-0030')
            );

        // TC-08, TC-09, TC-10: Limits & deterministic created_at / id tie-breaking
        TicketActivity::query()->delete();
        Ticket::query()->delete();

        $firstCreatedTicket = null;
        for ($i = 1; $i <= 12; $i++) {
            $num = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $timestamp = $i <= 10
                ? Carbon::parse("2026-11-19 08:{$num}:00")
                : Carbon::parse('2026-11-19 09:00:00');

            $ticket = Ticket::create([
                'ticket_number' => "ISS-2026-01{$num}",
                'type' => Ticket::TYPE_ISSUE,
                'requester_id' => $requester->id,
                'lead_engineer_id' => null,
                'title' => "Volume ticket {$num}",
                'description' => "Volume description {$num}",
                'priority' => Ticket::PRIORITY_HIGH,
                'status' => Ticket::STATUS_OPEN,
            ]);
            $ticket->created_at = $timestamp;
            $ticket->save();

            if ($i === 1) {
                $firstCreatedTicket = $ticket;
            }
        }

        for ($i = 1; $i <= 12; $i++) {
            $num = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $timestamp = $i <= 10
                ? Carbon::parse("2026-11-19 09:{$num}:00")
                : Carbon::parse('2026-11-19 09:30:00');

            $activity = TicketActivity::create([
                'ticket_id' => $firstCreatedTicket->id,
                'user_id' => $superadmin->id,
                'action_type' => 'note',
                'description' => "act-{$num}",
            ]);
            $activity->created_at = $timestamp;
            $activity->save();
        }

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('attention_tickets', 10)
                ->where('attention_tickets', fn ($items) => collect($items)->pluck('ticket_number')->all() === [
                    'ISS-2026-0101',
                    'ISS-2026-0102',
                    'ISS-2026-0103',
                    'ISS-2026-0104',
                    'ISS-2026-0105',
                    'ISS-2026-0106',
                    'ISS-2026-0107',
                    'ISS-2026-0108',
                    'ISS-2026-0109',
                    'ISS-2026-0110',
                ])
                ->has('recent_tickets', 8)
                ->where('recent_tickets', fn ($items) => collect($items)->pluck('ticket_number')->all() === [
                    'ISS-2026-0112',
                    'ISS-2026-0111',
                    'ISS-2026-0110',
                    'ISS-2026-0109',
                    'ISS-2026-0108',
                    'ISS-2026-0107',
                    'ISS-2026-0106',
                    'ISS-2026-0105',
                ])
                ->has('recent_activities', 10)
                ->where('recent_activities', fn ($items) => collect($items)->pluck('description')->all() === [
                    'act-12',
                    'act-11',
                    'act-10',
                    'act-09',
                    'act-08',
                    'act-07',
                    'act-06',
                    'act-05',
                    'act-04',
                    'act-03',
                ])
            );
    }

    public function test_dashboard_null_fallbacks_internal_systems_isolation_and_non_polluting_xss_probes(): void
    {
        // TC-11: Nullable fields serialize cleanly as null
        $nullDeptUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'department' => null,
        ]);

        $nullTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0200',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $nullDeptUser->id,
            'internal_system_id' => null,
            'lead_engineer_id' => null,
            'target_due_date' => null,
            'title' => 'Null fields ticket',
            'description' => 'Nullable relations check.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        TicketActivity::create([
            'ticket_id' => $nullTicket->id,
            'user_id' => null,
            'action_type' => 'sla_check',
            'description' => 'Automated SLA check ran.',
        ]);

        // TC-14: Internal systems shared prop filters active systems and orders by name
        InternalSystem::create([
            'name' => 'Zeta Core',
            'category' => 'Core',
            'is_active' => true,
        ]);
        InternalSystem::create([
            'name' => 'alpha gateway',
            'category' => 'Gateway',
            'is_active' => true,
        ]);
        InternalSystem::create([
            'name' => 'Legacy Billing',
            'category' => 'Billing',
            'is_active' => false,
        ]);

        $this->actingAs($nullDeptUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.department', null)
                ->where('attention_tickets.0.internal_system', null)
                ->where('attention_tickets.0.lead_engineer', null)
                ->where('attention_tickets.0.target_due_date', null)
                ->where('recent_tickets.0.internal_system', null)
                ->where('recent_tickets.0.lead_engineer', null)
                ->where('recent_activities.0.user', null)
                ->where('recent_activities.0.description', 'Automated SLA check ran.')
                ->has('internalSystems', 2)
                ->where('internalSystems.0.name', 'alpha gateway')
                ->where('internalSystems.1.name', 'Zeta Core')
            );

        // TC-15 & TC-16: Strict data isolation and deactivated user guard
        $userA = User::factory()->create(['role' => User::ROLE_USER]);
        $userB = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $engineerA = User::factory()->create(['role' => User::ROLE_ENGINEER]);
        $engineerB = User::factory()->create(['role' => User::ROLE_ENGINEER]);

        $ticketA = Ticket::create([
            'ticket_number' => 'ISS-2026-0300',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $userA->id,
            'lead_engineer_id' => $engineerA->id,
            'title' => 'User A isolated ticket',
            'description' => 'Private to User A and Engineer A.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        TicketActivity::create([
            'ticket_id' => $ticketA->id,
            'user_id' => $userA->id,
            'action_type' => 'created',
            'description' => 'Ticket ISS-2026-0300 submitted by User A.',
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-0301',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $engineerB->id,
            'lead_engineer_id' => null,
            'title' => 'Engineer B submitted unassigned ticket',
            'description' => 'Engineer B is requester, not lead or collaborator.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($userB)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.total_visible', 0)
                ->has('attention_tickets', 0)
                ->has('recent_tickets', 0)
                ->has('recent_activities', 0)
            );

        $this->actingAs($engineerB)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.total_visible', 0)
                ->has('attention_tickets', 0)
                ->has('recent_tickets', 0)
                ->has('recent_activities', 0)
            );

        $userB->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->actingAs($userB)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your account has been deactivated. Please contact the Superadmin.');

        // TC-12: Global Quick Search endpoint + trim contract
        $this->actingAs($userA)
            ->get('/tickets?search=ISS-2026-0300')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('filters.search', 'ISS-2026-0300')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.ticket_number', 'ISS-2026-0300')
            );

        $this->actingAs($userA)
            ->get('/tickets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->missing('filters.search')
                ->has('tickets.data', 1)
            );

        // TC-13: Whitespace-only required fields & empty attachments rejected on POST /tickets
        $this->actingAs($userA)
            ->post(route('tickets.store'), [
                'type' => Ticket::TYPE_ISSUE,
                'title' => '   ',
                'description' => '   ',
                'priority' => Ticket::PRIORITY_HIGH,
                'environment' => 'production',
                'impact_scope' => 'single_user',
                'attachments' => [],
            ])
            ->assertSessionHasErrors(['title', 'description', 'attachments']);

        $this->assertSame(1, Ticket::where('requester_id', $userA->id)->count());

        // TC-19: Non-polluting XSS + >255 title boundary probe & Dashboard literal escaping
        $overlongXssTitle = '<script>window.__xss=1</script>'.str_repeat('A', 230);
        $this->actingAs($userA)
            ->post(route('tickets.store'), [
                'type' => Ticket::TYPE_ISSUE,
                'title' => $overlongXssTitle,
                'description' => '<img src=x onerror=alert(1)>',
                'priority' => Ticket::PRIORITY_HIGH,
                'environment' => 'production',
                'impact_scope' => 'single_user',
                'attachments' => [],
            ])
            ->assertSessionHasErrors(['title', 'attachments']);

        $this->assertFalse(
            Ticket::where('title', $overlongXssTitle)->exists()
        );

        $xssTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0302',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $userA->id,
            'lead_engineer_id' => null,
            'title' => '<script>window.__xss=1</script>',
            'description' => 'XSS probe ticket.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        TicketActivity::create([
            'ticket_id' => $xssTicket->id,
            'user_id' => $userA->id,
            'action_type' => 'note',
            'description' => '<img src=x onerror=alert(1)>',
        ]);

        $this->actingAs($userA)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('recent_tickets.0.title', '<script>window.__xss=1</script>')
                ->where('recent_activities.0.description', '<img src=x onerror=alert(1)>')
            );

        // TC-18: Dashboard UI, layout shortcuts, & NewTicketModal accessibility contract
        $dashboardVue = file_get_contents(resource_path('js/Pages/Dashboard/Index.vue'));
        $this->assertIsString($dashboardVue);
        $this->assertStringContainsString('headerContext', $dashboardVue);
        $this->assertStringContainsString('v-if="user?.is_staff"', $dashboardVue);
        $this->assertStringContainsString('href="/tickets/kanban"', $dashboardVue);
        $this->assertStringContainsString('openNewTicketModal', $dashboardVue);
        $this->assertStringContainsString('No tickets currently require attention.', $dashboardVue);
        $this->assertStringContainsString('No recent ticket activity recorded.', $dashboardVue);
        $this->assertStringContainsString('No tickets have been submitted yet.', $dashboardVue);

        $appLayoutVue = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));
        $this->assertIsString($appLayoutVue);
        $this->assertStringContainsString('handleQuickSearch', $appLayoutVue);
        $this->assertStringContainsString('const trimmed = searchQuery.value.trim();', $appLayoutVue);
        $this->assertStringContainsString("router.get('/tickets', trimmed ? { search: trimmed } : {});", $appLayoutVue);
        $this->assertStringContainsString('aria-label="Search tickets"', $appLayoutVue);
        $this->assertStringContainsString("event.key === '/'", $appLayoutVue);
        $this->assertStringContainsString("event.key === 'Escape'", $appLayoutVue);
        $this->assertStringContainsString(':aria-expanded="notificationsOpen"', $appLayoutVue);

        $newTicketModalVue = file_get_contents(resource_path('js/Components/NewTicketModal.vue'));
        $this->assertIsString($newTicketModalVue);
        $this->assertStringContainsString('role="dialog"', $newTicketModalVue);
        $this->assertStringContainsString('aria-modal="true"', $newTicketModalVue);
        $this->assertStringContainsString('for="new-ticket-title"', $newTicketModalVue);
        $this->assertStringContainsString('id="new-ticket-title"', $newTicketModalVue);
        $this->assertStringContainsString('for="new-ticket-description"', $newTicketModalVue);
        $this->assertStringContainsString('id="new-ticket-description"', $newTicketModalVue);
        $this->assertStringContainsString("event.key !== 'Escape'", $newTicketModalVue);
    }

    public function test_seeded_superadmin_login_rotates_session_csrf_supports_remember_me_and_shares_auth_flags(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superadmin = User::where('username', 'superadmin')->firstOrFail();

        // Start guest session on /login
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('auth.user', null)
                ->where('flash.id', null)
                ->where('flash.success', null)
                ->where('flash.error', null)
            );

        $preLoginSessionId = $this->app['session']->getId();
        $preLoginCsrf = csrf_token();

        // Login with username + remember=false rotates session ID and does not set recaller cookie
        $loginResponse = $this->post(route('login.store'), [
            'login' => 'superadmin',
            'password' => 'password',
            'remember' => false,
        ]);

        $loginResponse->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Signed in as System Superadmin.')
            ->assertCookieMissing(Auth::guard()->getRecallerName());

        $this->assertAuthenticatedAs($superadmin);
        $postLoginSessionId = $this->app['session']->getId();
        $this->assertNotSame($preLoginSessionId, $postLoginSessionId);
        $this->assertNull($superadmin->fresh()->remember_token);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('auth.user.username', 'superadmin')
                ->where('auth.user.role', User::ROLE_SUPERADMIN)
                ->where('auth.user.is_super_admin', true)
                ->where('auth.user.is_staff', true)
                ->where('auth.user.is_engineer', false)
            );

        // Logout invalidates session, rotates session ID and CSRF token
        $this->post(route('logout'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('success', 'You have been signed out.');

        $this->assertGuest();
        $this->assertNotSame($postLoginSessionId, $this->app['session']->getId());
        $this->assertNotSame($preLoginCsrf, csrf_token());

        // Login with email + remember=true sets recaller cookie and persists remember_token
        $rememberResponse = $this->post(route('login.store'), [
            'login' => 'superadmin@calltek.local',
            'password' => 'password',
            'remember' => true,
        ]);

        $rememberResponse->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Signed in as System Superadmin.')
            ->assertCookie(Auth::guard()->getRecallerName());

        $this->assertAuthenticatedAs($superadmin);
        $this->assertNotNull($superadmin->fresh()->remember_token);
    }

    public function test_superadmin_creates_engineer_and_requester_who_can_login_with_expected_role_flags(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superadmin = User::where('username', 'superadmin')->firstOrFail();

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => "Dana O'Neil-Smith",
                'username' => 'doneil_01',
                'email' => 'dana.oneil@calltek.local',
                'password' => 'Secret123!',
                'role' => User::ROLE_ENGINEER,
                'department' => 'NOC',
                'job_title' => 'Network Engineer',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "User Dana O'Neil-Smith created successfully.");

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Sam Lee',
                'username' => 'slee-02',
                'email' => 'sam.lee@calltek.local',
                'password' => 'my pass 123',
                'role' => User::ROLE_USER,
                'department' => 'Finance',
                'job_title' => 'Billing Analyst',
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'User Sam Lee created successfully.');

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Engineer login and role flags
        $this->post(route('login.store'), [
            'login' => 'doneil_01',
            'password' => 'Secret123!',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.name', "Dana O'Neil-Smith")
                ->where('auth.user.username', 'doneil_01')
                ->where('auth.user.role', User::ROLE_ENGINEER)
                ->where('auth.user.is_engineer', true)
                ->where('auth.user.is_staff', true)
                ->where('auth.user.is_super_admin', false)
                ->where('auth.user.department', 'NOC')
                ->where('auth.user.job_title', 'Network Engineer')
            );

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Requester login and role flags
        $this->post(route('login.store'), [
            'login' => 'slee-02',
            'password' => 'my pass 123',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.name', 'Sam Lee')
                ->where('auth.user.username', 'slee-02')
                ->where('auth.user.role', User::ROLE_USER)
                ->where('auth.user.is_engineer', false)
                ->where('auth.user.is_staff', false)
                ->where('auth.user.is_super_admin', false)
                ->where('auth.user.department', 'Finance')
                ->where('auth.user.job_title', 'Billing Analyst')
            );
    }

    public function test_login_edge_cases_validation_whitespace_case_insensitivity_and_injection_probes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superadmin = User::where('username', 'superadmin')->firstOrFail();

        // Empty and whitespace-only login & password fail required validation on both fields
        $this->post(route('login.store'), [
            'login' => '',
            'password' => '',
        ])->assertSessionHasErrors(['login', 'password']);
        $this->assertGuest();

        $this->post(route('login.store'), [
            'login' => '   ',
            'password' => '   ',
        ])->assertSessionHasErrors(['login', 'password']);
        $this->assertGuest();

        $this->post(route('login.store'), [
            'login' => 'superadmin',
            'password' => '   ',
        ])->assertSessionHasErrors(['password']);
        $this->assertGuest();

        // Leading/trailing whitespace on login succeeds
        $this->post(route('login.store'), [
            'login' => '  superadmin@calltek.local  ',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($superadmin);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Invalid password, nonexistent email, SQL/quote probe, and XSS probe fail uniformly
        $invalidPayloads = [
            ['login' => 'superadmin', 'password' => 'WrongPass123'],
            ['login' => 'nonexistent@calltek.local', 'password' => 'password'],
            ['login' => "admin' OR '1'='1", 'password' => 'password'],
            ['login' => '<script>window.__xss=1</script><img src=x onerror=alert(1)>', 'password' => 'password'],
        ];

        foreach ($invalidPayloads as $payload) {
            $this->post(route('login.store'), $payload)
                ->assertSessionHasErrors([
                    'login' => 'These credentials do not match our records.',
                ]);
            $this->assertGuest();
        }

        // Case-insensitive login for seeded superadmin (username & email)
        $this->post(route('login.store'), [
            'login' => 'SUPERADMIN',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($superadmin);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post(route('login.store'), [
            'login' => 'SuperAdmin@CallTek.Local',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($superadmin);
    }

    public function test_admin_user_validation_non_polluting_probes_for_trim_whitespace_password_formats_and_xss(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superadmin = User::where('username', 'superadmin')->firstOrFail();

        // Non-polluting trim probe: username & email trimmed cleanly; fails ONLY on department (>255)
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Trim Probe',
                'username' => '  Trim_User  ',
                'email' => '  Trim.User@CallTek.Local  ',
                'password' => 'Secret123!',
                'role' => User::ROLE_USER,
                'department' => str_repeat('a', 260),
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['department'])
            ->assertSessionDoesntHaveErrors(['name', 'username', 'email', 'password', 'role']);
        $this->assertDatabaseMissing('users', ['username' => 'trim_user']);

        // Non-polluting whitespace password probe
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Whitespace Password Probe',
                'username' => 'ws_probe_user',
                'email' => 'ws.probe@calltek.local',
                'password' => '      ',
                'role' => User::ROLE_USER,
                'department' => str_repeat('a', 260),
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['password', 'department']);
        $this->assertDatabaseMissing('users', ['username' => 'ws_probe_user']);

        // Malformed username and invalid role
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Invalid Format Probe',
                'username' => 'bad user!',
                'email' => 'valid.format@calltek.local',
                'password' => 'Secret123!',
                'role' => 'admin',
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['username', 'role']);
        $this->assertDatabaseMissing('users', ['email' => 'valid.format@calltek.local']);

        // Invalid/TLD-less emails rejected
        foreach (['qa@localhost', 'user@company', 'not-an-email', 'qa@invalid..com'] as $index => $invalidEmail) {
            $this->actingAs($superadmin)
                ->post(route('admin.users.store'), [
                    'name' => "Bad Email {$index}",
                    'username' => "bad_email_probe_{$index}",
                    'email' => $invalidEmail,
                    'password' => 'Secret123!',
                    'role' => User::ROLE_USER,
                    'is_active' => true,
                ])
                ->assertSessionHasErrors(['email']);
            $this->assertDatabaseMissing('users', ['username' => "bad_email_probe_{$index}"]);
        }

        // Non-polluting valid subaddressed email + alpha_dash username probe paired with 260-char department
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Subaddress Probe',
                'username' => 'qa_sub-01',
                'email' => 'qa.manager+test@calltek.local',
                'password' => 'Secret123!',
                'role' => User::ROLE_USER,
                'department' => str_repeat('a', 260),
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['department'])
            ->assertSessionDoesntHaveErrors(['username', 'email', 'name', 'password', 'role']);
        $this->assertDatabaseMissing('users', ['username' => 'qa_sub-01']);

        // Non-polluting case-insensitive duplicate probe against seeded superadmin
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Duplicate Superadmin',
                'username' => 'SUPERADMIN',
                'email' => 'SUPERADMIN@CALLTEK.LOCAL',
                'password' => 'Secret123!',
                'role' => User::ROLE_USER,
                'department' => str_repeat('a', 260),
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['username', 'email', 'department']);

        // Self-update with uppercase own username/email ignores own ID and fails ONLY on department
        $this->actingAs($superadmin)
            ->put(route('admin.users.update', $superadmin), [
                'name' => $superadmin->name,
                'username' => 'SUPERADMIN',
                'email' => 'SUPERADMIN@CALLTEK.LOCAL',
                'role' => User::ROLE_SUPERADMIN,
                'department' => str_repeat('a', 260),
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['department'])
            ->assertSessionDoesntHaveErrors(['username', 'email', 'role', 'is_active']);

        // Non-polluting XSS probe in name paired with 260-char department
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => '<script>window.__xss=1</script>',
                'username' => 'xss_probe',
                'email' => 'xss.probe@calltek.local',
                'password' => 'Secret123!',
                'role' => User::ROLE_USER,
                'department' => str_repeat('a', 260),
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['department'])
            ->assertSessionDoesntHaveErrors(['name', 'username', 'email', 'password', 'role']);
        $this->assertDatabaseMissing('users', ['username' => 'xss_probe']);
    }

    public function test_admin_user_length_boundaries_and_email_rfc_filter_limit(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superadmin = User::where('username', 'superadmin')->firstOrFail();

        $buildEmail = static function (int $totalLength): string {
            $local = str_repeat('a', 63);
            $suffix = '.calltek.local';
            $remaining = $totalLength - strlen($local) - 1 - strlen($suffix);
            $parts = [];

            while ($remaining > 0) {
                $chunk = min(50, $remaining);
                if ($remaining - $chunk === 1) {
                    $chunk--;
                }
                $parts[] = str_repeat('b', $chunk);
                $remaining -= $chunk;
                if ($remaining > 0) {
                    $parts[] = '.';
                    $remaining--;
                }
            }

            return $local.'@'.implode('', $parts).$suffix;
        };

        $email254 = $buildEmail(254);
        $email255 = $buildEmail(255);
        $email256 = $buildEmail(256);

        $this->assertSame(254, strlen($email254));
        $this->assertSame(255, strlen($email255));
        $this->assertSame(256, strlen($email256));

        // Reject out-of-bounds lengths
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => str_repeat('N', 256),
                'username' => str_repeat('u', 256),
                'email' => $email256,
                'password' => '12345',
                'role' => User::ROLE_USER,
                'department' => str_repeat('D', 256),
                'job_title' => str_repeat('J', 256),
                'is_active' => true,
            ])
            ->assertSessionHasErrors([
                'name',
                'username',
                'email',
                'password',
                'department',
                'job_title',
            ]);

        // email:rfc,filter (specifically PHP FILTER_VALIDATE_EMAIL) rejects 255-char emails per RFC 5321 254-char path limit
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Email 255 Probe',
                'username' => 'email_255_probe',
                'email' => $email255,
                'password' => '123456',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertSessionHasErrors(['email']);
        $this->assertDatabaseMissing('users', ['username' => 'email_255_probe']);

        // Valid maximum/minimum boundaries: 6-char password, 255-char name/username/department/job_title, 254-char email
        $maxName = str_repeat('N', 255);
        $maxUsername = str_repeat('u', 255);
        $maxDepartment = str_repeat('D', 255);
        $maxJobTitle = str_repeat('J', 255);

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => $maxName,
                'username' => $maxUsername,
                'email' => $email254,
                'password' => '123456',
                'role' => User::ROLE_USER,
                'department' => $maxDepartment,
                'job_title' => $maxJobTitle,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "User {$maxName} created successfully.");

        $this->assertDatabaseHas('users', [
            'name' => $maxName,
            'username' => $maxUsername,
            'email' => $email254,
            'department' => $maxDepartment,
            'job_title' => $maxJobTitle,
        ]);

        // Created boundary user can sign in with 6-char password
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'login' => $maxUsername,
            'password' => '123456',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_admin_user_optional_null_fields_inactive_creation_and_reactivation_keeps_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superadmin = User::where('username', 'superadmin')->firstOrFail();

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Inactive Optional User',
                'username' => 'inactive_opt',
                'email' => 'inactive.opt@calltek.local',
                'password' => 'initial123',
                'role' => User::ROLE_USER,
                'department' => null,
                'job_title' => null,
                'is_active' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'User Inactive Optional User created successfully.');

        $createdUser = User::where('username', 'inactive_opt')->firstOrFail();
        $this->assertNull($createdUser->department);
        $this->assertNull($createdUser->job_title);
        $this->assertFalse($createdUser->is_active);
        $originalPasswordHash = $createdUser->password;

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Inactive user cannot sign in
        $this->post(route('login.store'), [
            'login' => 'inactive_opt',
            'password' => 'initial123',
        ])->assertSessionHasErrors([
            'login' => 'Your account has been deactivated. Please contact the Superadmin.',
        ]);
        $this->assertGuest();

        // Superadmin reactivates user with blank password -> keeps existing password hash
        $this->actingAs($superadmin)
            ->put(route('admin.users.update', $createdUser), [
                'name' => 'Inactive Optional User',
                'username' => 'inactive_opt',
                'email' => 'inactive.opt@calltek.local',
                'password' => '',
                'role' => User::ROLE_USER,
                'department' => null,
                'job_title' => null,
                'is_active' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'User Inactive Optional User updated successfully.');

        $createdUser->refresh();
        $this->assertTrue($createdUser->is_active);
        $this->assertSame($originalPasswordHash, $createdUser->password);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Reactivated user can sign in with original password
        $this->post(route('login.store'), [
            'login' => 'inactive_opt',
            'password' => 'initial123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($createdUser);
    }

    public function test_guest_redirects_intended_url_guest_middleware_and_mid_session_deactivation_on_get_and_post(): void
    {
        // Guest redirects across protected pages
        foreach ([
            route('dashboard'),
            route('tickets.index'),
            route('tickets.kanban'),
            route('reports.index'),
            route('admin.users.index'),
            route('admin.systems.index'),
            route('notifications.index'),
        ] as $protectedUrl) {
            $this->get($protectedUrl)->assertRedirect(route('login'));
        }

        // Intended URL redirect: guest requests GET /tickets then logs in -> redirected to /tickets
        $user = User::factory()->create([
            'username' => 'intended_user',
            'password' => 'secret123',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->get(route('tickets.index'))->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'login' => 'intended_user',
            'password' => 'secret123',
        ])->assertRedirect(route('tickets.index'));
        $this->assertAuthenticatedAs($user);

        // Authenticated user hitting GET /login and POST /login is redirected to /dashboard by guest middleware
        $this->get(route('login'))->assertRedirect(route('dashboard'));
        $this->post(route('login.store'), [
            'login' => 'intended_user',
            'password' => 'secret123',
        ])->assertRedirect(route('dashboard'));

        // Mid-session deactivation on GET /dashboard logs out and flashes error
        $user->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your account has been deactivated. Please contact the Superadmin.');
        $this->assertGuest();

        // Mid-session deactivation on POST /tickets logs out, flashes error, and creates no ticket
        Storage::fake((string) config('filesystems.attachments_disk'));
        $user2 = User::factory()->create([
            'username' => 'deactivated_poster',
            'password' => 'secret123',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->post(route('login.store'), [
            'login' => 'deactivated_poster',
            'password' => 'secret123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user2);

        $user2->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => 'Deactivated POST attempt',
            'description' => 'Should be blocked by EnsureUserIsActive.',
            'priority' => Ticket::PRIORITY_HIGH,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('trace.log', 5, 'text/plain'),
            ],
        ])->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your account has been deactivated. Please contact the Superadmin.');

        $this->assertGuest();
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_complete_rbac_matrix_and_notification_ownership(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN, 'is_active' => true]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER, 'is_active' => true]);
        $assignedEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER, 'is_active' => true]);
        $requester = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);

        $system = InternalSystem::create([
            'name' => 'RBAC Target System',
            'category' => 'Core Platform',
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0999',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $system->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $assignedEngineer->id,
            'title' => 'RBAC verification ticket',
            'description' => 'Testing complete RBAC matrix.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        // Admin user & system mutations: forbidden for requester & engineer, allowed for superadmin
        foreach ([$requester, $engineer] as $nonAdmin) {
            $this->actingAs($nonAdmin)->get(route('admin.users.index'))->assertForbidden();
            $this->actingAs($nonAdmin)->post(route('admin.users.store'), [
                'name' => 'Blocked User',
                'username' => 'blocked_user',
                'email' => 'blocked@calltek.local',
                'password' => 'secret123',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])->assertForbidden();
            $this->actingAs($nonAdmin)->put(route('admin.users.update', $requester), [
                'name' => 'Blocked Update',
                'username' => $requester->username,
                'email' => $requester->email,
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])->assertForbidden();

            $this->actingAs($nonAdmin)->get(route('admin.systems.index'))->assertForbidden();
            $this->actingAs($nonAdmin)->post(route('admin.systems.store'), [
                'name' => 'Blocked System',
                'category' => 'Core',
                'is_active' => true,
            ])->assertForbidden();
            $this->actingAs($nonAdmin)->put(route('admin.systems.update', $system), [
                'name' => 'Blocked System Update',
                'category' => 'Core',
                'is_active' => true,
            ])->assertForbidden();

            $this->actingAs($nonAdmin)->patch(route('tickets.assignment.update', $ticket), [
                'lead_engineer_id' => $assignedEngineer->id,
            ])->assertForbidden();
        }

        // Superadmin succeeds on admin mutations and assignment
        $this->actingAs($superadmin)->post(route('admin.users.store'), [
            'name' => 'Allowed User',
            'username' => 'allowed_rbac_user',
            'email' => 'allowed.rbac@calltek.local',
            'password' => 'secret123',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $allowedUser = User::where('username', 'allowed_rbac_user')->firstOrFail();

        $this->actingAs($superadmin)->put(route('admin.users.update', $allowedUser), [
            'name' => 'Allowed User Updated',
            'username' => 'allowed_rbac_user',
            'email' => 'allowed.rbac@calltek.local',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($superadmin)->post(route('admin.systems.store'), [
            'name' => 'Allowed RBAC System',
            'category' => 'Core',
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $allowedSystem = InternalSystem::where('name', 'Allowed RBAC System')->firstOrFail();

        $this->actingAs($superadmin)->put(route('admin.systems.update', $allowedSystem), [
            'name' => 'Allowed RBAC System Updated',
            'category' => 'Platform',
            'is_active' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($superadmin)->patch(route('tickets.assignment.update', $ticket), [
            'lead_engineer_id' => $assignedEngineer->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Subtask store: forbidden for requester & unassigned engineer, allowed for assigned engineer & superadmin
        $this->actingAs($requester)->post(route('tickets.subtasks.store', $ticket), [
            'title' => 'Requester subtask attempt',
        ])->assertForbidden();

        $this->actingAs($engineer)->post(route('tickets.subtasks.store', $ticket), [
            'title' => 'Unassigned engineer subtask attempt',
        ])->assertForbidden();

        $this->actingAs($assignedEngineer)->post(route('tickets.subtasks.store', $ticket), [
            'title' => 'Assigned engineer subtask',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($superadmin)->post(route('tickets.subtasks.store', $ticket), [
            'title' => 'Superadmin subtask',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Kanban & Reports & Reports Export: forbidden for requester, allowed for engineer & superadmin
        $this->actingAs($requester)->get(route('tickets.kanban'))->assertForbidden();
        $this->actingAs($requester)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($requester)->get(route('reports.export'))->assertForbidden();

        foreach ([$engineer, $superadmin] as $staff) {
            $this->actingAs($staff)->get(route('tickets.kanban'))->assertOk();
            $this->actingAs($staff)->get(route('reports.index'))->assertOk();
            $this->actingAs($staff)->get(route('reports.export'))->assertOk();
        }

        // Cross-user notification ownership: requester & engineer cannot mark superadmin notification read
        $superadminNotification = UserNotification::create([
            'user_id' => $superadmin->id,
            'ticket_id' => $ticket->id,
            'title' => 'Superadmin Private Notification',
            'message' => 'Only superadmin may mark this read.',
            'is_read' => false,
        ]);

        $this->actingAs($requester)
            ->patch(route('notifications.read', $superadminNotification))
            ->assertForbidden();
        $this->actingAs($engineer)
            ->patch(route('notifications.read', $superadminNotification))
            ->assertForbidden();
        $this->assertFalse($superadminNotification->fresh()->is_read);
    }

    public function test_ui_and_inertia_auth_rbac_contract(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('auth.user', null)
                ->where('flash.id', null)
                ->where('flash.success', null)
                ->where('flash.error', null)
            );

        $loginVue = (string) file_get_contents(resource_path('js/Pages/Auth/Login.vue'));
        $this->assertStringContainsString('<label for="login"', $loginVue);
        $this->assertStringContainsString('id="login"', $loginVue);
        $this->assertStringContainsString('<label for="password"', $loginVue);
        $this->assertStringContainsString('id="password"', $loginVue);
        $this->assertStringContainsString(':aria-expanded="showDemoAccounts"', $loginVue);
        $this->assertStringContainsString("form.reset('password')", $loginVue);

        $appLayoutVue = (string) file_get_contents(resource_path('js/Layouts/AppLayout.vue'));
        $this->assertStringContainsString("group: 'Workspace'", $appLayoutVue);
        $this->assertStringContainsString("group: 'Engineering'", $appLayoutVue);
        $this->assertStringContainsString("group: 'Administration'", $appLayoutVue);
        $this->assertStringContainsString('user.value?.is_staff', $appLayoutVue);
        $this->assertStringContainsString('user.value?.is_super_admin', $appLayoutVue);

        $roleBadgeVue = (string) file_get_contents(resource_path('js/Components/RoleBadge.vue'));
        $this->assertStringContainsString("superadmin: 'Superadmin'", $roleBadgeVue);
        $this->assertStringContainsString("engineer: 'Engineer'", $roleBadgeVue);
        $this->assertStringContainsString("user: 'Requester'", $roleBadgeVue);

        $usersIndexVue = (string) file_get_contents(resource_path('js/Pages/Admin/Users/Index.vue'));
        $this->assertStringContainsString('unlockCreateField', $usersIndexVue);
        $this->assertStringContainsString('unlockEditPassword', $usersIndexVue);
        $this->assertStringContainsString("removeAttribute('readonly')", $usersIndexVue);
        $this->assertStringContainsString('isEditingSelf', $usersIndexVue);
        $this->assertStringContainsString(':disabled="isEditingSelf"', $usersIndexVue);

        $modalVue = (string) file_get_contents(resource_path('js/Components/Modal.vue'));
        $this->assertStringContainsString("event.key === 'Escape'", $modalVue);
    }

    public function test_ticket_submission_happy_paths_all_roles_types_enums_multiple_attachments_and_superadmin_self_exclusion(): void
    {
        Storage::fake((string) config('filesystems.attachments_disk'));
        $year = now()->format('Y');

        // TC-01: Actors & System
        $superadminA = User::factory()->create([
            'name' => 'Superadmin A',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);
        $superadminB = User::factory()->create([
            'name' => 'Superadmin B',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);
        $superadminC = User::factory()->create([
            'name' => 'Inactive Superadmin C',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => false,
        ]);
        $engineer = User::factory()->create([
            'name' => "Dana O'Neil",
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);
        $requester = User::factory()->create([
            'name' => 'Sam Lee',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $system = InternalSystem::create([
            'name' => 'Billing Portal',
            'category' => 'Core Revenue',
            'is_active' => true,
        ]);
        $authSystem = InternalSystem::create([
            'name' => 'Auth Service',
            'category' => 'Identity',
            'is_active' => true,
        ]);

        // TC-01: Issue submission as Requester
        $issueResponse = $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $system->id,
            'priority' => Ticket::PRIORITY_HIGH,
            'title' => 'Invoice PDF fails on zero-rated accounts',
            'description' => 'PDF export throws 500 error for VAT-exempt customers.',
            'environment' => 'production',
            'impact_scope' => 'department',
            'attachments' => [
                UploadedFile::fake()->create('error-trace.log', 12, 'text/plain'),
            ],
        ]);

        $issue = Ticket::where('ticket_number', "ISS-{$year}-0001")->firstOrFail();

        $issueResponse->assertStatus(302)
            ->assertRedirect(route('tickets.show', $issue))
            ->assertSessionHas('success', "Ticket {$issue->ticket_number} created successfully.");

        $this->assertSame("ISS-{$year}-0001", $issue->ticket_number);
        $this->assertSame('issue', $issue->type);
        $this->assertSame($system->id, $issue->internal_system_id);
        $this->assertSame($requester->id, $issue->requester_id);
        $this->assertNull($issue->lead_engineer_id);
        $this->assertSame('high', $issue->priority);
        $this->assertSame('open', $issue->status);
        $this->assertSame('production', $issue->environment);
        $this->assertSame('department', $issue->impact_scope);
        $this->assertNull($issue->steps_to_reproduce);
        $this->assertNull($issue->expected_behavior);
        $this->assertNull($issue->actual_behavior);
        $this->assertNull($issue->business_justification);
        $this->assertNull($issue->proposed_functionality);
        $this->assertNull($issue->expected_benefits);
        $this->assertNull($issue->requested_deadline);

        $issueAttachments = TicketAttachment::where('ticket_id', $issue->id)->get();
        $this->assertCount(1, $issueAttachments);
        $this->assertFalse((bool) $issueAttachments[0]->is_internal);
        $this->assertNull($issueAttachments[0]->ticket_comment_id);
        Storage::disk((string) config('filesystems.attachments_disk'))->assertExists($issueAttachments[0]->file_path);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $issue->id,
            'user_id' => $requester->id,
            'action_type' => 'ticket_created',
            'old_value' => null,
            'new_value' => 'open',
            'description' => "Ticket {$issue->ticket_number} submitted by Sam Lee.",
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $superadminA->id,
            'ticket_id' => $issue->id,
            'title' => "New Ticket: {$issue->ticket_number}",
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $superadminB->id,
            'ticket_id' => $issue->id,
            'title' => "New Ticket: {$issue->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $superadminC->id,
            'ticket_id' => $issue->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $engineer->id,
            'ticket_id' => $issue->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $issue->id,
        ]);

        // TC-02: Engineering Request submission with 2 attachments
        $engReqResponse = $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $system->id,
            'priority' => Ticket::PRIORITY_MEDIUM,
            'title' => 'Automated daily ledger export',
            'description' => 'Need scheduled CSV export at 06:00 daily.',
            'business_justification' => 'Saves 2 hours of manual finance work daily.',
            'proposed_functionality' => 'Scheduled background job pushing CSV to SFTP.',
            'expected_benefits' => 'Faster morning reconciliation.',
            'requested_deadline' => '2026-12-15',
            'attachments' => [
                UploadedFile::fake()->create('ledger-spec.pdf', 18, 'application/pdf'),
                UploadedFile::fake()->create('sample.csv', 4, 'text/csv'),
            ],
        ]);

        $engRequest = Ticket::where('ticket_number', "REQ-{$year}-0001")->firstOrFail();

        $engReqResponse->assertStatus(302)
            ->assertRedirect(route('tickets.show', $engRequest))
            ->assertSessionHas('success', "Ticket REQ-{$year}-0001 submitted and is pending director approval.");

        $this->assertSame("REQ-{$year}-0001", $engRequest->ticket_number);
        $this->assertSame('engineering_request', $engRequest->type);
        $this->assertSame('medium', $engRequest->priority);
        $this->assertSame(Ticket::STATUS_PENDING_APPROVAL, $engRequest->status);
        $this->assertSame('2026-12-15', $engRequest->requested_deadline?->toDateString());
        $this->assertNull($engRequest->environment);
        $this->assertNull($engRequest->impact_scope);

        $engReqAttachments = TicketAttachment::where('ticket_id', $engRequest->id)->get();
        $this->assertCount(2, $engReqAttachments);
        foreach ($engReqAttachments as $att) {
            Storage::disk((string) config('filesystems.attachments_disk'))->assertExists($att->file_path);
            $this->actingAs($requester)
                ->get(route('attachments.download', $att))
                ->assertOk();
        }

        // TC-03: Remaining enum combinations for issue tickets
        $enumCombos = [
            ['priority' => 'low', 'environment' => 'staging', 'impact_scope' => 'single_user'],
            ['priority' => 'critical', 'environment' => 'internal_network', 'impact_scope' => 'company_wide'],
            ['priority' => 'medium', 'environment' => 'other', 'impact_scope' => 'department'],
        ];

        foreach ($enumCombos as $index => $combo) {
            $resp = $this->actingAs($requester)->post(route('tickets.store'), [
                'type' => Ticket::TYPE_ISSUE,
                'internal_system_id' => $system->id,
                'priority' => $combo['priority'],
                'title' => "Enum coverage ticket {$index}",
                'description' => "Testing enum combination {$index}.",
                'environment' => $combo['environment'],
                'impact_scope' => $combo['impact_scope'],
                'attachments' => [
                    UploadedFile::fake()->create('diag.txt', 2, 'text/plain'),
                ],
            ]);

            $created = Ticket::latest('id')->firstOrFail();
            $resp->assertStatus(302)->assertRedirect(route('tickets.show', $created));

            $this->actingAs($requester)
                ->get(route('tickets.show', $created))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Tickets/Show')
                    ->where('ticket.priority', $combo['priority'])
                    ->where('ticket.environment', $combo['environment'])
                    ->where('ticket.impact_scope', $combo['impact_scope'])
                    ->where('ticket.type', Ticket::TYPE_ISSUE)
                    ->where('ticket.status', Ticket::STATUS_OPEN)
                );
        }

        // TC-04: Superadmin submission & self-exclusion from notifications
        $saResponse = $this->actingAs($superadminA)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $authSystem->id,
            'priority' => Ticket::PRIORITY_HIGH,
            'title' => 'Superadmin filed outage',
            'description' => 'Auth service gateway unresponsive.',
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('outage.log', 5, 'text/plain'),
            ],
        ]);

        $saTicket = Ticket::where('title', 'Superadmin filed outage')->firstOrFail();
        $saResponse->assertStatus(302)->assertRedirect(route('tickets.show', $saTicket));

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $superadminB->id,
            'ticket_id' => $saTicket->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $superadminA->id,
            'ticket_id' => $saTicket->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $superadminC->id,
            'ticket_id' => $saTicket->id,
        ]);

        // TC-05: Engineer submission redirects to index and hides unassigned show page from submitting engineer
        $engResponse = $this->actingAs($engineer)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $authSystem->id,
            'priority' => Ticket::PRIORITY_HIGH,
            'title' => 'Engineer observed BGP flap',
            'description' => 'Upstream BGP peer flapped twice.',
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('bgp.png', 10, 'image/png'),
            ],
        ]);

        $engTicket = Ticket::where('title', 'Engineer observed BGP flap')->firstOrFail();
        $engResponse->assertStatus(302)
            ->assertRedirect(route('tickets.index'))
            ->assertSessionHas('success', "Ticket {$engTicket->ticket_number} created successfully.");

        $this->assertSame($engineer->id, $engTicket->requester_id);
        $this->assertNull($engTicket->lead_engineer_id);

        $this->actingAs($engineer)->get(route('tickets.show', $engTicket))->assertForbidden();
        $this->actingAs($superadminA)->get(route('tickets.show', $engTicket))->assertOk();

        // TC-06: Classification filtering on tickets.index as Superadmin A
        $this->actingAs($superadminA)
            ->get(route('tickets.index', ['type' => Ticket::TYPE_ISSUE]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 6)
                ->where('tickets.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['type'] === Ticket::TYPE_ISSUE))
            );

        $this->actingAs($superadminA)
            ->get(route('tickets.index', ['type' => Ticket::TYPE_ENGINEERING_REQUEST]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.type', Ticket::TYPE_ENGINEERING_REQUEST)
            );

        $this->actingAs($superadminA)
            ->get(route('tickets.index', ['priority' => Ticket::PRIORITY_CRITICAL]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.priority', Ticket::PRIORITY_CRITICAL)
            );

        $this->actingAs($superadminA)
            ->get(route('tickets.index', ['internal_system_id' => $system->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 5)
                ->where('tickets.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['internal_system_id'] === $system->id))
            );

        $this->actingAs($superadminA)
            ->get(route('tickets.index', ['status' => Ticket::STATUS_OPEN]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 6)
                ->where('statusCounts.all', 7)
                ->where('statusCounts.open', 6)
                ->where('statusCounts.pending_approval', 1)
            );

        $this->actingAs($superadminA)
            ->get(route('tickets.index', ['search' => "REQ-{$year}-0001"]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.ticket_number', "REQ-{$year}-0001")
            );
    }

    public function test_ticket_submission_validation_whitespace_trim_enums_formats_case_sensitivity_and_sequential_numbering(): void
    {
        $year = now()->format('Y');
        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        // TC-07: Required issue fields with whitespace / empty values
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => '   ',
            'description' => '   ',
            'priority' => '',
            'environment' => '',
            'impact_scope' => '',
            'attachments' => [],
        ])->assertSessionHasErrors([
            'title',
            'description',
            'priority',
            'environment',
            'impact_scope',
            'attachments',
        ]);
        $this->assertDatabaseCount('tickets', 0);

        // TC-08: Required engineering_request fields with whitespace / empty values
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'title' => '   ',
            'description' => '',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'business_justification' => '   ',
            'proposed_functionality' => '   ',
            'expected_benefits' => '   ',
            'attachments' => [],
        ])->assertSessionHasErrors([
            'title',
            'description',
            'business_justification',
            'proposed_functionality',
            'expected_benefits',
            'attachments',
        ]);
        $this->assertDatabaseCount('tickets', 0);

        // TC-09: Non-polluting trim probe (valid padded text passes, only empty attachments fails)
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'title' => '   Ledger Sync Request  ',
            'description' => '  Sync description  ',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'business_justification' => '  Audit requirement  ',
            'proposed_functionality' => '  Nightly cron  ',
            'expected_benefits' => '  Zero manual entry  ',
            'attachments' => [],
        ])->assertSessionHasErrors(['attachments'])
            ->assertSessionDoesntHaveErrors([
                'title',
                'description',
                'priority',
                'business_justification',
                'proposed_functionality',
                'expected_benefits',
            ]);
        $this->assertDatabaseCount('tickets', 0);

        // TC-10: Invalid enum values
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => 'bug_report',
            'priority' => 'urgent',
            'title' => 'Bad enums',
            'description' => 'Probe',
            'attachments' => [],
        ])->assertSessionHasErrors(['type', 'priority', 'attachments'])
            ->assertSessionDoesntHaveErrors(['environment', 'impact_scope']);

        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'priority' => Ticket::PRIORITY_HIGH,
            'environment' => 'development',
            'impact_scope' => 'global',
            'title' => 'Bad issue enums',
            'description' => 'Probe',
            'attachments' => [],
        ])->assertSessionHasErrors(['environment', 'impact_scope', 'attachments']);
        $this->assertDatabaseCount('tickets', 0);

        // TC-11: Invalid system, date, and attachment format
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => 999999,
            'priority' => Ticket::PRIORITY_MEDIUM,
            'title' => 'Boundary formats',
            'description' => 'Format probe',
            'business_justification' => 'Justification',
            'proposed_functionality' => 'Functionality',
            'expected_benefits' => 'Benefits',
            'requested_deadline' => 'not-a-valid-date',
            'attachments' => ['not-a-file-instance'],
        ])->assertSessionHasErrors(['internal_system_id', 'requested_deadline', 'attachments.0']);
        $this->assertDatabaseCount('tickets', 0);

        // TC-12: Case-sensitive enum rejection
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'priority' => 'HIGH',
            'environment' => 'PRODUCTION',
            'impact_scope' => 'SINGLE_USER',
            'title' => 'Uppercase enum probe',
            'description' => 'Probe',
            'attachments' => [],
        ])->assertSessionHasErrors(['priority', 'environment', 'impact_scope', 'attachments']);

        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => 'ISSUE',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'title' => 'Uppercase type',
            'description' => 'Probe',
            'attachments' => [],
        ])->assertSessionHasErrors(['type', 'attachments']);
        $this->assertDatabaseCount('tickets', 0);

        // TC-13: Sequential numbering skips gaps/non-numeric suffixes & dashboard sorts active systems case-insensitively
        foreach ([
            ['ticket_number' => "ISS-{$year}-0001", 'type' => Ticket::TYPE_ISSUE],
            ['ticket_number' => "ISS-{$year}-0004", 'type' => Ticket::TYPE_ISSUE],
            ['ticket_number' => "ISS-{$year}-ABCD", 'type' => Ticket::TYPE_ISSUE],
            ['ticket_number' => "REQ-{$year}-0002", 'type' => Ticket::TYPE_ENGINEERING_REQUEST],
        ] as $seed) {
            Ticket::create([
                'ticket_number' => $seed['ticket_number'],
                'type' => $seed['type'],
                'requester_id' => $requester->id,
                'title' => "Seed {$seed['ticket_number']}",
                'description' => 'Sequential numbering probe.',
                'priority' => Ticket::PRIORITY_MEDIUM,
                'status' => Ticket::STATUS_OPEN,
            ]);
        }

        $ticketService = app(TicketService::class);
        $this->assertSame("ISS-{$year}-0005", $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));
        $this->assertSame("REQ-{$year}-0003", $ticketService->generateTicketNumber(Ticket::TYPE_ENGINEERING_REQUEST));

        InternalSystem::create([
            'name' => 'Zeta Core',
            'category' => 'Core',
            'is_active' => true,
        ]);
        InternalSystem::create([
            'name' => 'alpha gateway',
            'category' => 'Network',
            'is_active' => true,
        ]);
        InternalSystem::create([
            'name' => 'Legacy Billing',
            'category' => 'Legacy',
            'is_active' => false,
        ]);

        $this->actingAs($requester)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('internalSystems', 2)
                ->where('internalSystems.0.name', 'alpha gateway')
                ->where('internalSystems.1.name', 'Zeta Core')
            );
    }

    public function test_ticket_submission_length_limits_attachment_size_boundaries_cross_type_isolation_rbac_and_ui_a11y_security(): void
    {
        Storage::fake((string) config('filesystems.attachments_disk'));

        $requester = User::factory()->create([
            'name' => 'Standard Requester',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        // TC-14: Title 256 vs 255 length boundary & 1200-char description
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => str_repeat('T', 256),
            'description' => str_repeat('D', 1200),
            'priority' => Ticket::PRIORITY_MEDIUM,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [],
        ])->assertSessionHasErrors(['title', 'attachments'])
            ->assertSessionDoesntHaveErrors(['description']);

        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => str_repeat('T', 255),
            'description' => str_repeat('D', 1200),
            'priority' => Ticket::PRIORITY_MEDIUM,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [],
        ])->assertSessionHasErrors(['attachments'])
            ->assertSessionDoesntHaveErrors(['title', 'description']);

        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => str_repeat('T', 255),
            'description' => str_repeat('D', 1200),
            'priority' => Ticket::PRIORITY_MEDIUM,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('valid-10kb.log', 10, 'text/plain'),
            ],
        ])->assertRedirect()
            ->assertSessionHasNoErrors();

        // TC-15: 220-char requester name activity truncation & 10 MB attachment boundary
        $longNameRequester = User::factory()->create([
            'name' => str_repeat('A', 220),
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->actingAs($longNameRequester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => 'Oversize attachment check',
            'description' => 'Should reject 10241 KB file.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('oversize.zip', 10241, 'application/zip'),
            ],
        ])->assertSessionHasErrors(['attachments.0']);

        $this->actingAs($longNameRequester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => 'Exact 10MB attachment check',
            'description' => 'Should accept 10240 KB file and truncate activity description.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('exact-10mb.bin', 10240, 'application/octet-stream'),
            ],
        ])->assertRedirect()
            ->assertSessionHasNoErrors();

        $exactTicket = Ticket::where('title', 'Exact 10MB attachment check')->firstOrFail();
        $activity = TicketActivity::where('ticket_id', $exactTicket->id)->firstOrFail();
        $this->assertLessThanOrEqual(253, Str::length($activity->description));
        $this->assertStringEndsWith('...', $activity->description);

        // TC-16: Cross-type field isolation & nullable system/deadline
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => '',
            'priority' => Ticket::PRIORITY_LOW,
            'title' => 'Issue cross-type strip',
            'description' => 'Issue desc',
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'business_justification' => 'Should be ignored',
            'proposed_functionality' => 'Should be ignored',
            'expected_benefits' => 'Should be ignored',
            'requested_deadline' => '2026-12-31',
            'attachments' => [
                UploadedFile::fake()->create('issue.txt', 2, 'text/plain'),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $strippedIssue = Ticket::where('title', 'Issue cross-type strip')->firstOrFail();
        $this->assertNull($strippedIssue->internal_system_id);
        $this->assertNull($strippedIssue->business_justification);
        $this->assertNull($strippedIssue->proposed_functionality);
        $this->assertNull($strippedIssue->expected_benefits);
        $this->assertNull($strippedIssue->requested_deadline);

        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => null,
            'priority' => Ticket::PRIORITY_MEDIUM,
            'title' => 'Req cross-type strip',
            'description' => 'Req desc',
            'business_justification' => 'Just',
            'proposed_functionality' => 'Func',
            'expected_benefits' => 'Ben',
            'requested_deadline' => '',
            'environment' => 'production',
            'impact_scope' => 'company_wide',
            'attachments' => [
                UploadedFile::fake()->create('req.txt', 2, 'text/plain'),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $strippedReq = Ticket::where('title', 'Req cross-type strip')->firstOrFail();
        $this->assertNull($strippedReq->internal_system_id);
        $this->assertNull($strippedReq->requested_deadline);
        $this->assertNull($strippedReq->environment);
        $this->assertNull($strippedReq->impact_scope);

        // TC-17: Auth, non-existent /tickets/create 404, and mid-session deactivated user check
        Auth::logout();
        $this->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => 'Guest submission',
            'description' => 'Should redirect to login.',
            'priority' => Ticket::PRIORITY_LOW,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('guest.txt', 1, 'text/plain'),
            ],
        ])->assertRedirect(route('login'));

        $this->actingAs($requester)->get('/tickets/create')->assertNotFound();

        $deactivatedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $ticketCountBefore = Ticket::count();
        $deactivatedUser->update(['is_active' => false]);

        $this->actingAs($deactivatedUser)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => 'Deactivated mid-session submission',
            'description' => 'Must be rejected and logged out.',
            'priority' => Ticket::PRIORITY_LOW,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('deactivated.txt', 1, 'text/plain'),
            ],
        ])->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame($ticketCountBefore, Ticket::count());

        // TC-18: UI & Accessibility assertions on NewTicketModal.vue
        $modalVue = (string) file_get_contents(resource_path('js/Components/NewTicketModal.vue'));
        foreach ([
            'role="dialog"',
            'aria-modal="true"',
            'aria-labelledby="new-ticket-modal-title"',
            'role="tablist"',
            'role="tab"',
            ':aria-selected',
            'maxlength="255"',
            'handleFileChange',
            'removeAttachment',
            "event.key !== 'Escape'",
            'form.reset()',
            'form.clearErrors()',
            ':disabled="form.processing"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $modalVue);
        }

        foreach ([
            'new-ticket-internal-system',
            'new-ticket-priority',
            'new-ticket-title',
            'new-ticket-description',
            'new-ticket-environment',
            'new-ticket-impact-scope',
            'new-ticket-business-justification',
            'new-ticket-proposed-functionality',
            'new-ticket-expected-benefits',
            'new-ticket-requested-deadline',
        ] as $fieldId) {
            $this->assertStringContainsString("for=\"{$fieldId}\"", $modalVue);
            $this->assertStringContainsString("id=\"{$fieldId}\"", $modalVue);
        }

        // TC-19: Non-polluting XSS/SQLi probe + raw Inertia rendering & Vue escaping check
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'title' => '<script>window.__xss=1</script>'.str_repeat('A', 230),
            'description' => '<img src=x onerror=alert(1)>',
            'business_justification' => '<svg onload=alert(2)>',
            'proposed_functionality' => "'; DROP TABLE tickets; --",
            'expected_benefits' => '<iframe src=javascript:alert(3)>',
            'priority' => Ticket::PRIORITY_HIGH,
            'attachments' => [],
        ])->assertSessionHasErrors(['title', 'attachments'])
            ->assertSessionDoesntHaveErrors([
                'description',
                'business_justification',
                'proposed_functionality',
                'expected_benefits',
            ]);

        $xssTitle = '<script>window.__xss=1</script>';
        $xssDesc = '<img src=x onerror=alert(1)>';

        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => $xssTitle,
            'description' => $xssDesc,
            'priority' => Ticket::PRIORITY_HIGH,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('xss-probe.txt', 1, 'text/plain'),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $xssTicket = Ticket::where('title', $xssTitle)->firstOrFail();

        $this->actingAs($requester)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('tickets.data', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $xssTicket->id && $row['title'] === $xssTitle))
            );

        $this->actingAs($requester)
            ->get(route('tickets.show', $xssTicket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Show')
                ->where('ticket.title', $xssTitle)
                ->where('ticket.description', $xssDesc)
            );

        $ticketsIndexVue = (string) file_get_contents(resource_path('js/Pages/Tickets/Index.vue'));
        $ticketsShowVue = (string) file_get_contents(resource_path('js/Pages/Tickets/Show.vue'));
        $ticketDetailVue = (string) file_get_contents(resource_path('js/Components/TicketDetailWorkspace.vue'));
        $this->assertStringNotContainsString('v-html', $ticketsIndexVue);
        $this->assertStringNotContainsString('v-html', $ticketsShowVue);
        $this->assertStringNotContainsString('v-html', $ticketDetailVue);
        $this->assertStringContainsString('{{ ticket.title }}', $ticketsIndexVue);
        $this->assertStringContainsString('{{ ticket.title }}', $ticketsShowVue);
        $this->assertStringContainsString('{{ ticket.description }}', $ticketDetailVue);
    }

    public function test_ticket_queue_happy_paths_role_visibility_status_counts_and_individual_filters(): void
    {
        $superadmin = User::factory()->create([
            'name' => 'Queue Admin',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $dana = User::factory()->create([
            'name' => "Dana O'Neil",
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $collabOne = User::factory()->create([
            'name' => 'Collab One',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $collabTwo = User::factory()->create([
            'name' => 'Collab Two',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $sam = User::factory()->create([
            'name' => 'Sam Lee',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $otherRequester = User::factory()->create([
            'name' => 'Other Requester',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $billingPortal = InternalSystem::create([
            'name' => 'Billing Portal',
            'category' => 'Core Revenue',
            'is_active' => true,
        ]);

        $makeTicket = function (array $overrides): Ticket {
            return Ticket::create(array_merge([
                'internal_system_id' => null,
                'lead_engineer_id' => null,
                'description' => 'Default queue ticket description.',
                'environment' => 'production',
                'impact_scope' => 'department',
            ], $overrides));
        };

        $tOpen = $makeTicket([
            'ticket_number' => 'ISS-2026-0101',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $billingPortal->id,
            'requester_id' => $sam->id,
            'lead_engineer_id' => null,
            'title' => 'Open critical billing outage',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_OPEN,
            'target_due_date' => today()->subDay()->toDateString(),
        ]);

        $tUnderReview = $makeTicket([
            'ticket_number' => 'REQ-2026-0102',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $billingPortal->id,
            'requester_id' => $sam->id,
            'lead_engineer_id' => $dana->id,
            'title' => 'Under review high request',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_UNDER_REVIEW,
            'environment' => null,
            'impact_scope' => null,
            'business_justification' => 'Audit compliance',
            'proposed_functionality' => 'Automated ledger export',
            'expected_benefits' => 'Saves 5 hours weekly',
            'target_due_date' => today()->addDays(3)->toDateString(),
        ]);
        $tUnderReview->collaborators()->attach([
            $collabOne->id => ['added_by_id' => $superadmin->id],
            $collabTwo->id => ['added_by_id' => $superadmin->id],
        ]);

        $tInProgress = $makeTicket([
            'ticket_number' => 'ISS-2026-0103',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => null,
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => $dana->id,
            'title' => 'In progress medium general issue',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        $tWaiting = $makeTicket([
            'ticket_number' => 'REQ-2026-0104',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $billingPortal->id,
            'requester_id' => $sam->id,
            'lead_engineer_id' => $collabOne->id,
            'title' => 'Waiting on user low request',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_WAITING_ON_USER,
            'environment' => null,
            'impact_scope' => null,
            'business_justification' => 'Reporting',
            'proposed_functionality' => 'CSV filters',
            'expected_benefits' => 'Faster triage',
        ]);
        $tWaiting->collaborators()->attach($dana->id, ['added_by_id' => $superadmin->id]);

        $tResolved = $makeTicket([
            'ticket_number' => 'ISS-2026-0105',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $billingPortal->id,
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => $collabOne->id,
            'title' => 'Resolved high billing issue',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_RESOLVED,
            'resolved_at' => now()->subHour(),
        ]);

        $tClosed = $makeTicket([
            'ticket_number' => 'REQ-2026-0106',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => null,
            'requester_id' => $dana->id,
            'lead_engineer_id' => null,
            'title' => 'Closed unassigned request submitted by Dana',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_CLOSED,
            'environment' => null,
            'impact_scope' => null,
            'business_justification' => 'Internal tooling',
            'proposed_functionality' => 'CLI helper',
            'expected_benefits' => 'Reduces manual steps',
            'closed_at' => now()->subHours(2),
        ]);

        $tRejected = $makeTicket([
            'ticket_number' => 'ISS-2026-0107',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => null,
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => $collabTwo->id,
            'title' => 'Rejected low issue',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_REJECTED,
        ]);

        $tCancelled = $makeTicket([
            'ticket_number' => 'REQ-2026-0108',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $billingPortal->id,
            'requester_id' => $sam->id,
            'lead_engineer_id' => $collabTwo->id,
            'title' => 'Cancelled critical request',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_CANCELLED,
            'environment' => null,
            'impact_scope' => null,
            'business_justification' => 'Superseded',
            'proposed_functionality' => 'Legacy sync',
            'expected_benefits' => 'N/A',
        ]);
        $tCancelled->collaborators()->attach($collabOne->id, ['added_by_id' => $superadmin->id]);

        // Set explicit non-monotonic updated_at values to verify updated_at DESC, id DESC ordering.
        $baseTime = Carbon::create(2026, 11, 19, 10, 0, 0);
        $orderedTickets = [
            $tInProgress,
            $tUnderReview,
            $tOpen,
            $tWaiting,
            $tResolved,
            $tClosed,
            $tRejected,
            $tCancelled,
        ];
        foreach ($orderedTickets as $index => $ticket) {
            DB::table('tickets')
                ->where('id', $ticket->id)
                ->update(['updated_at' => $baseTime->copy()->subMinutes($index * 10)->toDateTimeString()]);
        }

        // TC-01: Superadmin sees all 8 tickets, accurate statusCounts, ordering, eager-loaded relations, systems & engineers
        $this->actingAs($superadmin)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('statusCounts.all', 8)
                ->where('statusCounts.open', 1)
                ->where('statusCounts.under_review', 1)
                ->where('statusCounts.in_progress', 1)
                ->where('statusCounts.waiting_on_user', 1)
                ->where('statusCounts.resolved', 1)
                ->where('statusCounts.closed', 1)
                ->where('statusCounts.rejected', 1)
                ->where('statusCounts.cancelled', 1)
                ->has('tickets.data', 8)
                ->where('tickets.data', function ($rows) use ($orderedTickets): bool {
                    $actualIds = collect($rows)->pluck('id')->all();
                    $expectedIds = collect($orderedTickets)->pluck('id')->all();

                    return $actualIds === $expectedIds;
                })
                ->where('tickets.data.0.internal_system', null)
                ->where('tickets.data.1.internal_system.name', 'Billing Portal')
                ->where('tickets.data.1.requester.name', 'Sam Lee')
                ->where('tickets.data.1.lead_engineer.name', "Dana O'Neil")
                ->where('tickets.data.1.collaborators', function ($collabs): bool {
                    $names = collect($collabs)->pluck('name')->sort()->values()->all();

                    return $names === ['Collab One', 'Collab Two'];
                })
                ->where('systems', fn ($systems) => collect($systems)->pluck('name')->contains('Billing Portal'))
                ->where('engineers', function ($engineers): bool {
                    $names = collect($engineers)->pluck('name');

                    return $names->contains("Dana O'Neil")
                        && $names->contains('Collab One')
                        && $names->contains('Collab Two');
                })
            );

        // TC-02: Engineer (Dana O'Neil) sees only tickets where she is lead or collaborator
        $this->actingAs($dana)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 3)
                ->where('tickets.data', function ($rows) use ($tInProgress, $tUnderReview, $tWaiting): bool {
                    $ids = collect($rows)->pluck('id')->all();

                    return $ids === [$tInProgress->id, $tUnderReview->id, $tWaiting->id];
                })
                ->where('statusCounts.all', 3)
                ->where('statusCounts.open', 0)
                ->where('statusCounts.under_review', 1)
                ->where('statusCounts.in_progress', 1)
                ->where('statusCounts.waiting_on_user', 1)
                ->where('statusCounts.resolved', 0)
                ->where('statusCounts.closed', 0)
                ->where('statusCounts.rejected', 0)
                ->where('statusCounts.cancelled', 0)
            );

        // TC-03: Standard Requester (Sam Lee) sees only tickets where he is requester_id
        $this->actingAs($sam)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->has('tickets.data', 4)
                ->where('tickets.data', function ($rows) use ($tUnderReview, $tOpen, $tWaiting, $tCancelled): bool {
                    $ids = collect($rows)->pluck('id')->all();

                    return $ids === [$tUnderReview->id, $tOpen->id, $tWaiting->id, $tCancelled->id];
                })
                ->where('statusCounts.all', 4)
                ->where('statusCounts.open', 1)
                ->where('statusCounts.under_review', 1)
                ->where('statusCounts.in_progress', 0)
                ->where('statusCounts.waiting_on_user', 1)
                ->where('statusCounts.resolved', 0)
                ->where('statusCounts.closed', 0)
                ->where('statusCounts.rejected', 0)
                ->where('statusCounts.cancelled', 1)
            );

        // TC-04: Individual filters
        $statusToTicketId = [
            Ticket::STATUS_OPEN => $tOpen->id,
            Ticket::STATUS_UNDER_REVIEW => $tUnderReview->id,
            Ticket::STATUS_IN_PROGRESS => $tInProgress->id,
            Ticket::STATUS_WAITING_ON_USER => $tWaiting->id,
            Ticket::STATUS_RESOLVED => $tResolved->id,
            Ticket::STATUS_CLOSED => $tClosed->id,
            Ticket::STATUS_REJECTED => $tRejected->id,
            Ticket::STATUS_CANCELLED => $tCancelled->id,
        ];

        foreach ($statusToTicketId as $status => $expectedId) {
            $this->actingAs($superadmin)
                ->get(route('tickets.index', ['status' => $status]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('tickets.data', 1)
                    ->where('tickets.data.0.id', $expectedId)
                    ->where('filters.status', $status)
                    ->where('statusCounts.all', 8)
                    ->where("statusCounts.{$status}", 1)
                );
        }

        // Type filter
        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['type' => Ticket::TYPE_ISSUE]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 4)
                ->where('tickets.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['type'] === Ticket::TYPE_ISSUE))
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['type' => Ticket::TYPE_ENGINEERING_REQUEST]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 4)
                ->where('tickets.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['type'] === Ticket::TYPE_ENGINEERING_REQUEST))
            );

        // Priority filter
        foreach ([Ticket::PRIORITY_CRITICAL, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_LOW] as $priority) {
            $this->actingAs($superadmin)
                ->get(route('tickets.index', ['priority' => $priority]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('tickets.data', 2)
                    ->where('tickets.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['priority'] === $priority))
                );
        }

        // Internal system filter
        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['internal_system_id' => $billingPortal->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 5)
                ->where('tickets.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['internal_system_id'] === $billingPortal->id))
            );

        // Lead engineer filter (Superadmin)
        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['lead_engineer_id' => $dana->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 2)
                ->where('tickets.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$tInProgress->id, $tUnderReview->id])
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['lead_engineer_id' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 2)
                ->where('tickets.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$tOpen->id, $tClosed->id])
            );

        // Scope filters across roles
        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['scope' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 2)
                ->where('tickets.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$tOpen->id, $tClosed->id])
            );

        $this->actingAs($dana)
            ->get(route('tickets.index', ['scope' => 'led_by_me']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 2)
                ->where('tickets.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$tInProgress->id, $tUnderReview->id])
            );

        $this->actingAs($dana)
            ->get(route('tickets.index', ['scope' => 'collaborating']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $tWaiting->id)
            );

        $this->actingAs($sam)
            ->get(route('tickets.index', ['scope' => 'my_submitted']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 4)
                ->where('tickets.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['requester_id'] === $sam->id))
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['scope' => 'overdue']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $tOpen->id)
            );
    }

    public function test_ticket_queue_search_trim_wildcards_combined_filters_pagination_and_boundaries(): void
    {
        $superadmin = User::factory()->create([
            'name' => 'Super Admin',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        // TC-14 engineers (created with mixed casing for case-insensitive sort verification)
        $engAlex = User::factory()->create([
            'name' => 'alex rivera',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);
        $engMaya = User::factory()->create([
            'name' => 'Maya Lin',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);
        $engZane = User::factory()->create([
            'name' => 'Zane Mercer',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);
        $inactiveEngineer = User::factory()->create([
            'name' => 'Retired Engineer',
            'role' => User::ROLE_ENGINEER,
            'is_active' => false,
        ]);

        $requester = User::factory()->create([
            'name' => 'Standard User',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        // TC-14 systems (created with mixed casing for case-insensitive sort verification)
        $sysAlpha = InternalSystem::create([
            'name' => 'alpha gateway',
            'category' => 'Core',
            'is_active' => true,
        ]);
        $sysBeta = InternalSystem::create([
            'name' => 'Beta Ops',
            'category' => 'Ops',
            'is_active' => true,
        ]);
        $sysZeta = InternalSystem::create([
            'name' => 'Zeta Core',
            'category' => 'Platform',
            'is_active' => true,
        ]);
        $inactiveSystem = InternalSystem::create([
            'name' => 'Legacy Decommissioned',
            'category' => 'Archived',
            'is_active' => false,
        ]);

        // TC-05 & TC-06 tickets (avoid '%' and '_' in unrelated titles/descriptions/ticket_numbers so TC-07 literal '%' and '_' are isolated)
        $tSearchKey = Ticket::create([
            'ticket_number' => 'ISS-2026-0901',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $sysBeta->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engAlex->id,
            'title' => "Investigate Dana O'Neil VAT-exempt invoice",
            'description' => 'Users connecting via VPN+MFA experience timeout.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        // TC-07 wildcard escaping tickets
        $tPercentMatch = Ticket::create([
            'ticket_number' => 'ISS-2026-0902',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'CPU at 100% usage',
            'description' => 'Sustained load alert.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);
        $tPercentFalse = Ticket::create([
            'ticket_number' => 'ISS-2026-0903',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'CPU at 1000 cycles',
            'description' => 'Normal clock burst.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);
        $tUnderscoreMatch = Ticket::create([
            'ticket_number' => 'ISS-2026-0904',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Fix node_1 heartbeat',
            'description' => 'Telemetry gap.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);
        $tUnderscoreFalse = Ticket::create([
            'ticket_number' => 'ISS-2026-0905',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Fix nodeX1 heartbeat',
            'description' => 'Telemetry noise.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);
        $tBackslashMatch = Ticket::create([
            'ticket_number' => 'ISS-2026-0906',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Check C:\\logs\\app.log',
            'description' => 'Disk full warning.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);
        $tBackslashFalse = Ticket::create([
            'ticket_number' => 'ISS-2026-0907',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Check C:logsapp.log',
            'description' => 'Typo entry.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        // TC-05: Case-insensitive search across ticket_number, title, description + special chars
        foreach ([
            ['iss-2026-0901', $tSearchKey->id],
            ["dana o'neil", $tSearchKey->id],
            ['VAT-EXEMPT', $tSearchKey->id],
            ['vpn+mfa', $tSearchKey->id],
        ] as [$term, $expectedId]) {
            $this->actingAs($superadmin)
                ->get(route('tickets.index', ['search' => $term]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('tickets.data', 1)
                    ->where('tickets.data.0.id', $expectedId)
                );
        }

        // TC-06: Empty, whitespace-only, and padded search trim parity
        $totalAfterSearchSetup = Ticket::count();

        $this->actingAs($superadmin)
            ->get('/tickets?search=')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', null)
                ->where('tickets.total', $totalAfterSearchSetup)
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => '   ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', null)
                ->where('tickets.total', $totalAfterSearchSetup)
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => '  ISS-2026-0901  ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'ISS-2026-0901')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $tSearchKey->id)
            );

        // TC-07: SQL LIKE wildcard escaping
        foreach ([
            ['100%', $tPercentMatch->id, $tPercentFalse->id],
            ['node_1', $tUnderscoreMatch->id, $tUnderscoreFalse->id],
            ['C:\\logs', $tBackslashMatch->id, $tBackslashFalse->id],
            ['%', $tPercentMatch->id, $tPercentFalse->id],
            ['_', $tUnderscoreMatch->id, $tUnderscoreFalse->id],
        ] as [$term, $matchedId, $excludedId]) {
            $this->actingAs($superadmin)
                ->get(route('tickets.index', ['search' => $term]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('tickets.data', 1)
                    ->where('tickets.data.0.id', $matchedId)
                    ->where('tickets.data', fn ($rows) => ! collect($rows)->pluck('id')->contains($excludedId))
                );
        }

        // TC-08: Combined 7-filter intersection & incompatible combination
        $tExactCombined = Ticket::create([
            'ticket_number' => 'REQ-2026-0910',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engMaya->id,
            'title' => 'Batch Settlement Overhaul',
            'description' => 'Overdue critical request.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'target_due_date' => today()->subDays(2)->toDateString(),
        ]);

        // Near-miss ticket differing only in priority
        Ticket::create([
            'ticket_number' => 'REQ-2026-0911',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engMaya->id,
            'title' => 'Batch Settlement Followup',
            'description' => 'Overdue high request.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'target_due_date' => today()->subDays(2)->toDateString(),
        ]);

        $sevenFilters = [
            'status' => Ticket::STATUS_IN_PROGRESS,
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'priority' => Ticket::PRIORITY_CRITICAL,
            'internal_system_id' => $sysAlpha->id,
            'lead_engineer_id' => $engMaya->id,
            'scope' => 'overdue',
            'search' => 'Batch',
        ];

        $this->actingAs($superadmin)
            ->get(route('tickets.index', $sevenFilters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $tExactCombined->id)
                ->where('tickets.total', 1)
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', array_merge($sevenFilters, ['priority' => Ticket::PRIORITY_LOW])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 0)
                ->where('tickets.total', 0)
            );

        // TC-09: Pagination boundary (15 vs 16), tie-breaker ordering on identical updated_at, and query string retention
        $paginationRequester = User::factory()->create([
            'name' => 'Pagination Requester',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $batchTickets = [];
        for ($i = 1; $i <= 15; $i++) {
            $batchTickets[] = Ticket::create([
                'ticket_number' => sprintf('ISS-2026-10%02d', $i),
                'type' => Ticket::TYPE_ISSUE,
                'requester_id' => $paginationRequester->id,
                'title' => "Batch queue item {$i}",
                'description' => 'Pagination check.',
                'priority' => Ticket::PRIORITY_HIGH,
                'status' => Ticket::STATUS_OPEN,
            ]);
        }

        $this->actingAs($paginationRequester)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.last_page', 1)
                ->where('tickets.total', 15)
                ->has('tickets.data', 15)
            );

        $batchTicket16 = Ticket::create([
            'ticket_number' => 'ISS-2026-1016',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $paginationRequester->id,
            'title' => 'Batch queue item 16',
            'description' => 'Pagination overflow check.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        // Set identical newest updated_at on tickets 14 and 15 (15 has higher id than 14)
        $tieTimestamp = Carbon::create(2026, 11, 20, 12, 0, 0)->toDateTimeString();
        $olderTimestamp = Carbon::create(2026, 11, 19, 8, 0, 0)->toDateTimeString();
        foreach ($batchTickets as $idx => $bt) {
            $ts = $idx >= 13 ? $tieTimestamp : $olderTimestamp;
            DB::table('tickets')->where('id', $bt->id)->update(['updated_at' => $ts]);
        }
        DB::table('tickets')->where('id', $batchTicket16->id)->update(['updated_at' => $olderTimestamp]);

        $ticket14 = $batchTickets[13];
        $ticket15 = $batchTickets[14];

        $this->actingAs($paginationRequester)
            ->get(route('tickets.index', [
                'status' => Ticket::STATUS_OPEN,
                'priority' => Ticket::PRIORITY_HIGH,
                'search' => 'Batch',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.current_page', 1)
                ->where('tickets.last_page', 2)
                ->where('tickets.total', 16)
                ->has('tickets.data', 15)
                ->where('tickets.data.0.id', $ticket15->id)
                ->where('tickets.data.1.id', $ticket14->id)
                ->where('tickets.links', function ($links): bool {
                    $pageTwoLink = collect($links)->firstWhere('label', '2');

                    return $pageTwoLink !== null
                        && str_contains((string) $pageTwoLink['url'], 'status=open')
                        && str_contains((string) $pageTwoLink['url'], 'priority=high')
                        && str_contains((string) $pageTwoLink['url'], 'search=Batch');
                })
            );

        $this->actingAs($paginationRequester)
            ->get(route('tickets.index', [
                'status' => Ticket::STATUS_OPEN,
                'priority' => Ticket::PRIORITY_HIGH,
                'search' => 'Batch',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.current_page', 2)
                ->has('tickets.data', 1)
            );

        // TC-10: Malformed non-scalar array params, unknown enums, and pagination bounds
        $this->actingAs($superadmin)
            ->get('/tickets?search[]=foo&type[]=issue&status[]=open&priority[]=high&internal_system_id[]=1&lead_engineer_id[]=1&scope[]=overdue')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', null)
                ->where('filters.type', null)
                ->where('filters.status', null)
                ->where('filters.priority', null)
                ->where('filters.internal_system_id', null)
                ->where('filters.lead_engineer_id', null)
                ->where('filters.scope', null)
            );

        foreach ([
            ['type' => 'bug_report'],
            ['status' => 'archived'],
            ['priority' => 'urgent'],
        ] as $unknownEnum) {
            $this->actingAs($superadmin)
                ->get(route('tickets.index', $unknownEnum))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('tickets.data', 0)
                    ->where('tickets.total', 0)
                );
        }

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['scope' => 'everything']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.scope', 'everything')
            );

        foreach (['0', '-3', 'abc'] as $invalidPage) {
            $this->actingAs($paginationRequester)
                ->get(route('tickets.index', ['page' => $invalidPage]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('tickets.current_page', 1)
                    ->has('tickets.data', 15)
                );
        }

        $this->actingAs($paginationRequester)
            ->get(route('tickets.index', ['page' => 999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 0)
            );

        // TC-11: Search length boundaries (255-char match, 256-char 0 rows cleanly)
        $title255 = str_repeat('Q', 255);
        $tMaxLen = Ticket::create([
            'ticket_number' => 'ISS-2026-0920',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => $title255,
            'description' => 'Length boundary check.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => $title255]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $tMaxLen->id)
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => str_repeat('Q', 256)]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 0)
                ->where('tickets.total', 0)
            );

        // TC-12: scope=overdue date boundaries
        $overdueRequester = User::factory()->create([
            'name' => 'Overdue Boundary Requester',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $includedOverdueIds = [];
        foreach ([
            Ticket::STATUS_OPEN,
            Ticket::STATUS_UNDER_REVIEW,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_WAITING_ON_USER,
        ] as $idx => $activeStatus) {
            $includedOverdueIds[] = Ticket::create([
                'ticket_number' => "ISS-2026-110{$idx}",
                'type' => Ticket::TYPE_ISSUE,
                'requester_id' => $overdueRequester->id,
                'title' => "Overdue active {$activeStatus}",
                'description' => 'Yesterday due date on active status.',
                'priority' => Ticket::PRIORITY_MEDIUM,
                'status' => $activeStatus,
                'target_due_date' => today()->subDay()->toDateString(),
            ])->id;
        }

        // Non-overdue dates on open status (today, tomorrow, null)
        foreach ([
            today()->toDateString(),
            today()->addDay()->toDateString(),
            null,
        ] as $idx => $dueDate) {
            Ticket::create([
                'ticket_number' => "ISS-2026-111{$idx}",
                'type' => Ticket::TYPE_ISSUE,
                'requester_id' => $overdueRequester->id,
                'title' => 'Not overdue open ticket',
                'description' => 'Should be excluded from overdue scope.',
                'priority' => Ticket::PRIORITY_MEDIUM,
                'status' => Ticket::STATUS_OPEN,
                'target_due_date' => $dueDate,
            ]);
        }

        // Terminal statuses with yesterday due date must be excluded
        foreach ([
            Ticket::STATUS_RESOLVED,
            Ticket::STATUS_CLOSED,
            Ticket::STATUS_REJECTED,
            Ticket::STATUS_CANCELLED,
        ] as $idx => $terminalStatus) {
            Ticket::create([
                'ticket_number' => "ISS-2026-112{$idx}",
                'type' => Ticket::TYPE_ISSUE,
                'requester_id' => $overdueRequester->id,
                'title' => "Terminal yesterday {$terminalStatus}",
                'description' => 'Should be excluded from overdue scope.',
                'priority' => Ticket::PRIORITY_MEDIUM,
                'status' => $terminalStatus,
                'target_due_date' => today()->subDay()->toDateString(),
            ]);
        }

        $this->actingAs($overdueRequester)
            ->get(route('tickets.index', ['scope' => 'overdue']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 4)
                ->where('tickets.total', 4)
                ->where('tickets.data', function ($rows) use ($includedOverdueIds): bool {
                    $actual = collect($rows)->pluck('id')->sort()->values()->all();
                    $expected = collect($includedOverdueIds)->sort()->values()->all();

                    return $actual === $expected;
                })
            );

        // TC-13: Foreign key & inactive relation boundaries
        $inactiveRelTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0930',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $inactiveSystem->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $inactiveEngineer->id,
            'title' => 'Ticket referencing inactive system and engineer',
            'description' => 'Inactive relation check.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['internal_system_id' => 999999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 0)
                ->where('tickets.total', 0)
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['lead_engineer_id' => 999999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 0)
                ->where('tickets.total', 0)
            );

        // TC-13 (inactive excluded from dropdown props while loaded on ticket row) & TC-14 (case-insensitive sort)
        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => 'ISS-2026-0930']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $inactiveRelTicket->id)
                ->where('tickets.data.0.internal_system.name', 'Legacy Decommissioned')
                ->where('tickets.data.0.lead_engineer.name', 'Retired Engineer')
                ->where('systems', function ($systems): bool {
                    $names = collect($systems)->pluck('name')->values()->all();

                    return ! in_array('Legacy Decommissioned', $names, true)
                        && $names === ['alpha gateway', 'Beta Ops', 'Zeta Core'];
                })
                ->where('engineers', function ($engineers): bool {
                    $names = collect($engineers)->pluck('name')->values()->all();

                    return ! in_array('Retired Engineer', $names, true)
                        && $names === ['alex rivera', 'Maya Lin', 'Zane Mercer'];
                })
            );

        // TC-15: Optional/null ticket fields serialized cleanly
        $nullFieldsTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0931',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => null,
            'requester_id' => $requester->id,
            'lead_engineer_id' => null,
            'title' => 'Null optional relations ticket',
            'description' => 'Clean null serialization check.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
            'target_due_date' => null,
        ]);

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => 'ISS-2026-0931']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $nullFieldsTicket->id)
                ->where('tickets.data.0.internal_system', null)
                ->where('tickets.data.0.lead_engineer', null)
                ->where('tickets.data.0.target_due_date', null)
                ->where('tickets.data.0.collaborators', [])
            );
    }

    public function test_ticket_queue_rbac_filter_isolation_revocation_ui_accessibility_xss_and_idempotency(): void
    {
        $superadmin = User::factory()->create([
            'name' => 'Queue Superadmin',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $engineer = User::factory()->create([
            'name' => 'Target Engineer',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $otherEngineer = User::factory()->create([
            'name' => 'Other Engineer',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $requester = User::factory()->create([
            'name' => 'Queue Requester',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        // TC-16: Guest redirect, mid-session deactivation, and Standard User /tickets vs /tickets/kanban RBAC
        $this->get(route('tickets.index', ['status' => Ticket::STATUS_OPEN]))
            ->assertRedirect(route('login'));

        $deactivatedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $deactivatedUser->update(['is_active' => false]);

        $this->actingAs($deactivatedUser)
            ->get(route('tickets.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
        $this->assertGuest();

        $this->actingAs($requester)->get(route('tickets.index'))->assertOk();
        $this->actingAs($requester)->get(route('tickets.kanban'))->assertForbidden();

        // TC-17: Filter isolation for Engineer and Standard User
        $engAssignedTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-1201',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Assigned to Target Engineer',
            'description' => 'Visible to Target Engineer.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        $otherEngTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-1202',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'title' => 'Assigned to Other Engineer',
            'description' => 'Hidden from Target Engineer.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $engSubmittedUnassigned = Ticket::create([
            'ticket_number' => 'ISS-2026-1203',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $engineer->id,
            'lead_engineer_id' => null,
            'title' => 'Unassigned ticket submitted by Target Engineer',
            'description' => 'Hidden from Target Engineer until assigned.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        foreach ([
            ['lead_engineer_id' => 'unassigned'],
            ['lead_engineer_id' => $otherEngineer->id],
            ['scope' => 'unassigned'],
            ['scope' => 'my_submitted'],
        ] as $isolationQuery) {
            $this->actingAs($engineer)
                ->get(route('tickets.index', $isolationQuery))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('tickets.data', 1)
                    ->where('tickets.data.0.id', $engAssignedTicket->id)
                );
        }

        // Standard User passing lead_engineer_id=<eng_id> still sees her own ticket assigned to another engineer
        $this->actingAs($requester)
            ->get(route('tickets.index', ['lead_engineer_id' => $engineer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 2)
                ->where('tickets.data', function ($rows) use ($engAssignedTicket, $otherEngTicket): bool {
                    $ids = collect($rows)->pluck('id')->sort()->values()->all();
                    $expected = collect([$engAssignedTicket->id, $otherEngTicket->id])->sort()->values()->all();

                    return $ids === $expected;
                })
            );

        // TC-18: Immediate visibility & statusCounts revocation upon unassigning and restoration upon adding as Collaborator
        $this->actingAs($superadmin)
            ->patch(route('tickets.assignment.update', $engAssignedTicket), [
                'lead_engineer_id' => null,
                'collaborator_ids' => [],
            ])
            ->assertRedirect();

        $this->actingAs($engineer)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 0)
                ->where('statusCounts.all', 0)
                ->where('statusCounts.in_progress', 0)
            );

        $this->actingAs($superadmin)
            ->patch(route('tickets.assignment.update', $engAssignedTicket), [
                'lead_engineer_id' => null,
                'collaborator_ids' => [$engineer->id],
            ])
            ->assertRedirect();

        $this->actingAs($engineer)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $engAssignedTicket->id)
                ->where('statusCounts.all', 1)
                ->where('statusCounts.in_progress', 1)
            );

        // TC-19 & TC-20: UI & Accessibility inspection on Index.vue and Pagination.vue
        $indexVue = (string) file_get_contents(resource_path('js/Pages/Tickets/Index.vue'));
        $paginationVue = (string) file_get_contents(resource_path('js/Components/Pagination.vue'));

        foreach ([
            'role="tablist"',
            'role="tab"',
            ':aria-selected',
            'maxlength="255"',
            '@keydown.escape="onSearchEscape"',
            'v-if="user?.is_staff"',
            'href="/tickets/kanban"',
            'v-if="user?.is_super_admin"',
            'No tickets match the selected filters.',
            'Reset filters',
        ] as $needle) {
            $this->assertStringContainsString($needle, $indexVue);
        }

        foreach ([
            'filter-search',
            'filter-type',
            'filter-priority',
            'filter-system',
            'filter-scope',
        ] as $filterId) {
            $this->assertStringContainsString("<label for=\"{$filterId}\"", $indexVue);
            $this->assertStringContainsString("id=\"{$filterId}\"", $indexVue);
        }

        $this->assertStringContainsString('for="filter-lead"', $indexVue);
        $this->assertStringContainsString('id="filter-lead"', $indexVue);

        $this->assertStringContainsString('aria-label="Pagination"', $paginationVue);
        $this->assertStringContainsString('preserve-scroll', $paginationVue);

        foreach (['v-html', '<i class=', 'fa-'] as $forbiddenPattern) {
            $this->assertStringNotContainsString($forbiddenPattern, $indexVue);
            $this->assertStringNotContainsString($forbiddenPattern, $paginationVue);
        }

        // TC-21: Non-polluting XSS/SQLi probe & raw Inertia prop encoding
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => '<script>window.__queue_xss=1</script>'.str_repeat('A', 227),
            'description' => 'XSS length overflow probe.',
            'priority' => Ticket::PRIORITY_HIGH,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [],
        ])->assertSessionHasErrors(['title', 'attachments']);

        $sqliXssSearch = "<script>window.__queue_xss=1</script>' OR 1=1 --";
        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => $sqliXssSearch]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', $sqliXssSearch)
                ->has('tickets.data', 0)
                ->where('tickets.total', 0)
            );

        $xssTitle = '<script>window.__queue_xss=1</script>';
        $this->actingAs($requester)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => $xssTitle,
            'description' => 'Raw Inertia prop check.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('queue-xss.txt', 1, 'text/plain'),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $xssTicket = Ticket::where('title', $xssTitle)->firstOrFail();

        $this->actingAs($requester)
            ->get(route('tickets.index', ['search' => 'window.__queue_xss']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $xssTicket->id)
                ->where('tickets.data.0.title', $xssTitle)
            );
        $this->assertStringContainsString('{{ ticket.title }}', $indexVue);

        // TC-22: Read-only idempotency check
        $activityCountBefore = TicketActivity::count();
        $notificationCountBefore = UserNotification::count();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($superadmin)
                ->get(route('tickets.index', [
                    'status' => Ticket::STATUS_OPEN,
                    'priority' => Ticket::PRIORITY_MEDIUM,
                    'search' => 'ISS-2026',
                ]))
                ->assertOk();
        }

        $this->assertSame($activityCountBefore, TicketActivity::count());
        $this->assertSame($notificationCountBefore, UserNotification::count());
    }

    public function test_phase1_engineering_kanban_board_matrix(): void
    {
        $superadmin = User::factory()->create([
            'name' => 'Kanban Superadmin',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $engineer = User::factory()->create([
            'name' => 'Kanban Engineer One',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $otherEngineer = User::factory()->create([
            'name' => 'Kanban Engineer Two',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $requester = User::factory()->create([
            'name' => 'Kanban Requester',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        // Systems for TC-08 (case-insensitive sort & active-only filtering)
        $zetaSystem = InternalSystem::create([
            'name' => 'Zeta Core',
            'category' => 'Core',
            'description' => 'Core platform',
            'is_active' => true,
        ]);

        $alphaSystem = InternalSystem::create([
            'name' => 'alpha gateway',
            'category' => 'Gateway',
            'description' => 'Gateway service',
            'is_active' => true,
        ]);

        $inactiveSystem = InternalSystem::create([
            'name' => 'Archived Legacy',
            'category' => 'Legacy',
            'description' => 'Inactive system',
            'is_active' => false,
        ]);

        // TC-01 & TC-02: Create tickets across all 7 KANBAN_STATUSES plus cancelled
        $openAssigned = Ticket::create([
            'ticket_number' => 'ISS-2026-0101',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $alphaSystem->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Primary Open Ticket 100% Reliability',
            'description' => 'Database failover investigation on prod_db cluster.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
            'target_due_date' => today()->subDays(2)->toDateString(),
        ]);

        $underReviewCollab = Ticket::create([
            'ticket_number' => 'REQ-2026-0102',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $zetaSystem->id,
            'requester_id' => $superadmin->id,
            'lead_engineer_id' => $otherEngineer->id,
            'title' => 'Critical Engineering Request',
            'description' => 'Audit log path C:\\logs\\audit.log export.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_UNDER_REVIEW,
            'target_due_date' => today()->subDay()->toDateString(),
        ]);
        $underReviewCollab->collaborators()->attach($engineer->id, ['added_by_id' => $superadmin->id]);

        $inProgressOther = Ticket::create([
            'ticket_number' => 'ISS-2026-0103',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $zetaSystem->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'title' => 'Other Engineer In Progress 1000ms',
            'description' => 'Non-matching wildcard decoy prodXdb and Cxlogs.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'target_due_date' => today()->toDateString(),
        ]);

        $waitingUnassigned = Ticket::create([
            'ticket_number' => 'ISS-2026-0104',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $alphaSystem->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => null,
            'title' => 'Unassigned Waiting Ticket',
            'description' => 'Awaiting requester clarification.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_WAITING_ON_USER,
            'target_due_date' => today()->addDays(3)->toDateString(),
        ]);

        $resolvedAssigned = Ticket::create([
            'ticket_number' => 'ISS-2026-0105',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $alphaSystem->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Resolved Past Due Ticket',
            'description' => 'Already resolved.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_RESOLVED,
            'target_due_date' => today()->subDays(5)->toDateString(),
        ]);

        $closedOther = Ticket::create([
            'ticket_number' => 'ISS-2026-0106',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $zetaSystem->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'title' => 'Closed Past Due Ticket',
            'description' => 'Already closed.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_CLOSED,
            'target_due_date' => today()->subDays(5)->toDateString(),
        ]);

        $rejectedAssigned = Ticket::create([
            'ticket_number' => 'REQ-2026-0107',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $zetaSystem->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Rejected Past Due Ticket',
            'description' => 'Already rejected.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_REJECTED,
            'target_due_date' => today()->subDays(5)->toDateString(),
        ]);

        $cancelledTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0108',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $alphaSystem->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Cancelled Ticket Excluded From Board',
            'description' => 'Cancelled status should not appear on Kanban columns.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_CANCELLED,
            'target_due_date' => today()->subDays(1)->toDateString(),
        ]);

        // TC-01: Superadmin receives all 7 KANBAN_STATUSES columns and excludes cancelled
        $this->actingAs($superadmin)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->has('columns.open', 1)
                ->has('columns.under_review', 1)
                ->has('columns.in_progress', 1)
                ->has('columns.waiting_on_user', 1)
                ->has('columns.resolved', 1)
                ->has('columns.closed', 1)
                ->has('columns.rejected', 1)
                ->missing('columns.cancelled')
                ->where('columns', function ($columns) use ($cancelledTicket): bool {
                    $allIds = collect($columns)->flatten(1)->pluck('id')->all();

                    return ! in_array($cancelledTicket->id, $allIds, true);
                })
            );

        // TC-02: Engineer only sees tickets where they are lead_engineer_id or collaborator
        $this->actingAs($engineer)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use (
                    $openAssigned,
                    $underReviewCollab,
                    $resolvedAssigned,
                    $rejectedAssigned,
                    $inProgressOther,
                    $waitingUnassigned,
                    $closedOther,
                    $cancelledTicket
                ): bool {
                    $visibleIds = collect($columns)->flatten(1)->pluck('id')->sort()->values()->all();
                    $expectedIds = collect([
                        $openAssigned->id,
                        $underReviewCollab->id,
                        $resolvedAssigned->id,
                        $rejectedAssigned->id,
                    ])->sort()->values()->all();

                    return $visibleIds === $expectedIds
                        && ! in_array($inProgressOther->id, $visibleIds, true)
                        && ! in_array($waitingUnassigned->id, $visibleIds, true)
                        && ! in_array($closedOther->id, $visibleIds, true)
                        && ! in_array($cancelledTicket->id, $visibleIds, true);
                })
            );

        // TC-03: Column ordering by priority ASC (critical -> high -> medium -> low), updated_at DESC, id DESC
        $sortLow = Ticket::create([
            'ticket_number' => 'ISS-2026-0201',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Sort Low',
            'description' => 'Sorting verification.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $sortHighOlder = Ticket::create([
            'ticket_number' => 'ISS-2026-0202',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Sort High Older',
            'description' => 'Sorting verification.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $sortHighTieLowerId = Ticket::create([
            'ticket_number' => 'ISS-2026-0203',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Sort High Tie Lower ID',
            'description' => 'Sorting verification.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $sortHighTieHigherId = Ticket::create([
            'ticket_number' => 'ISS-2026-0204',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Sort High Tie Higher ID',
            'description' => 'Sorting verification.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $sortMedium = Ticket::create([
            'ticket_number' => 'ISS-2026-0205',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Sort Medium',
            'description' => 'Sorting verification.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $sortCritical = Ticket::create([
            'ticket_number' => 'ISS-2026-0206',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Sort Critical',
            'description' => 'Sorting verification.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $tieTimestamp = now()->subHours(2)->format('Y-m-d H:i:s');
        $olderTimestamp = now()->subHours(5)->format('Y-m-d H:i:s');
        $oldestHighTimestamp = now()->subHours(8)->format('Y-m-d H:i:s');

        DB::table('tickets')->where('id', $sortCritical->id)->update(['updated_at' => $olderTimestamp]);
        DB::table('tickets')->where('id', $sortHighTieHigherId->id)->update(['updated_at' => $tieTimestamp]);
        DB::table('tickets')->where('id', $sortHighTieLowerId->id)->update(['updated_at' => $tieTimestamp]);
        DB::table('tickets')->where('id', $sortHighOlder->id)->update(['updated_at' => $olderTimestamp]);
        DB::table('tickets')->where('id', $openAssigned->id)->update(['updated_at' => $oldestHighTimestamp]);
        DB::table('tickets')->where('id', $sortMedium->id)->update(['updated_at' => $tieTimestamp]);
        DB::table('tickets')->where('id', $sortLow->id)->update(['updated_at' => $tieTimestamp]);

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.open', function ($cards) use (
                    $sortCritical,
                    $sortHighTieHigherId,
                    $sortHighTieLowerId,
                    $sortHighOlder,
                    $openAssigned,
                    $sortMedium,
                    $sortLow
                ): bool {
                    $actualIds = collect($cards)->pluck('id')->values()->all();

                    return $actualIds === [
                        $sortCritical->id,
                        $sortHighTieHigherId->id,
                        $sortHighTieLowerId->id,
                        $sortHighOlder->id,
                        $openAssigned->id,
                        $sortMedium->id,
                        $sortLow->id,
                    ];
                })
            );

        // TC-04: Board cards include accurate subtasks_count and completed_subtasks_count
        TicketSubtask::create([
            'ticket_id' => $openAssigned->id,
            'title' => 'Subtask completed 1',
            'is_completed' => true,
            'completed_at' => now(),
            'created_by_id' => $engineer->id,
        ]);
        TicketSubtask::create([
            'ticket_id' => $openAssigned->id,
            'title' => 'Subtask completed 2',
            'is_completed' => true,
            'completed_at' => now(),
            'created_by_id' => $engineer->id,
        ]);
        TicketSubtask::create([
            'ticket_id' => $openAssigned->id,
            'title' => 'Subtask pending 3',
            'is_completed' => false,
            'created_by_id' => $engineer->id,
        ]);

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['search' => $openAssigned->ticket_number]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('columns.open', 1)
                ->where('columns.open.0.id', $openAssigned->id)
                ->where('columns.open.0.subtasks_count', 3)
                ->where('columns.open.0.completed_subtasks_count', 2)
            );

        // TC-05: Search trims whitespace and matches ticket_number, title, or description
        $this->actingAs($superadmin)
            ->get('/tickets/kanban?search=%20%20ISS-2026-0101%20%20')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.search', 'ISS-2026-0101')
                ->where('columns', function ($columns) use ($openAssigned): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$openAssigned->id];
                })
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['search' => '  Primary Open Ticket  ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($openAssigned): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$openAssigned->id];
                })
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['search' => 'failover investigation']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($openAssigned): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$openAssigned->id];
                })
            );

        // TC-06: Escaping SQL LIKE wildcards (%, _, \)
        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['search' => '100%']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($openAssigned): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$openAssigned->id];
                })
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['search' => 'prod_db']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($openAssigned): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$openAssigned->id];
                })
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['search' => 'C:\\logs']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($underReviewCollab): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$underReviewCollab->id];
                })
            );

        // TC-07: Type and priority filter
        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', [
                'type' => Ticket::TYPE_ENGINEERING_REQUEST,
                'priority' => Ticket::PRIORITY_CRITICAL,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($underReviewCollab): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$underReviewCollab->id];
                })
            );

        // TC-08: System filter + case-insensitive systems sort + inactive system excluded
        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['internal_system_id' => $alphaSystem->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('systems', function ($systems) use ($inactiveSystem): bool {
                    $names = collect($systems)->pluck('name')->values()->all();

                    return ! in_array($inactiveSystem->name, $names, true)
                        && $names === ['alpha gateway', 'Zeta Core'];
                })
                ->where('columns', function ($columns) use ($openAssigned, $waitingUnassigned, $resolvedAssigned): bool {
                    $ids = collect($columns)->flatten(1)->pluck('id')->sort()->values()->all();
                    $expected = collect([$openAssigned->id, $waitingUnassigned->id, $resolvedAssigned->id])->sort()->values()->all();

                    return $ids === $expected;
                })
            );

        // TC-09: lead_engineer_id filter for Superadmin vs ignored for Engineer
        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['lead_engineer_id' => $otherEngineer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($underReviewCollab, $inProgressOther, $closedOther): bool {
                    $ids = collect($columns)->flatten(1)->pluck('id')->sort()->values()->all();
                    $expected = collect([$underReviewCollab->id, $inProgressOther->id, $closedOther->id])->sort()->values()->all();

                    return $ids === $expected;
                })
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['lead_engineer_id' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($waitingUnassigned): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$waitingUnassigned->id];
                })
            );

        $this->actingAs($engineer)
            ->get(route('tickets.kanban', ['lead_engineer_id' => $otherEngineer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($openAssigned, $underReviewCollab, $inProgressOther): bool {
                    $ids = collect($columns)->flatten(1)->pluck('id')->all();

                    return in_array($openAssigned->id, $ids, true)
                        && in_array($underReviewCollab->id, $ids, true)
                        && ! in_array($inProgressOther->id, $ids, true);
                })
            );

        // TC-10: scope=unassigned & scope=my_submitted for Superadmin vs ignored for Engineer
        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['scope' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($waitingUnassigned): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$waitingUnassigned->id];
                })
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['scope' => 'my_submitted']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($underReviewCollab): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$underReviewCollab->id];
                })
            );

        $this->actingAs($engineer)
            ->get(route('tickets.kanban', ['scope' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($openAssigned, $waitingUnassigned): bool {
                    $ids = collect($columns)->flatten(1)->pluck('id')->all();

                    return in_array($openAssigned->id, $ids, true)
                        && ! in_array($waitingUnassigned->id, $ids, true);
                })
            );

        $this->actingAs($engineer)
            ->get(route('tickets.kanban', ['scope' => 'my_submitted']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($openAssigned): bool {
                    return in_array($openAssigned->id, collect($columns)->flatten(1)->pluck('id')->all(), true);
                })
            );

        // TC-11: scope=led_by_me & scope=collaborating for Superadmin and Engineer
        $superadminLed = Ticket::create([
            'ticket_number' => 'ISS-2026-0290',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $superadmin->id,
            'title' => 'Superadmin Led Ticket',
            'description' => 'Led by superadmin.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);
        $inProgressOther->collaborators()->attach($superadmin->id, ['added_by_id' => $superadmin->id]);

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['scope' => 'led_by_me']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($superadminLed): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$superadminLed->id];
                })
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['scope' => 'collaborating']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($inProgressOther): bool {
                    return collect($columns)->flatten(1)->pluck('id')->all() === [$inProgressOther->id];
                })
            );

        $this->actingAs($engineer)
            ->get(route('tickets.kanban', ['scope' => 'led_by_me']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($openAssigned, $underReviewCollab): bool {
                    $ids = collect($columns)->flatten(1)->pluck('id')->all();

                    return in_array($openAssigned->id, $ids, true)
                        && ! in_array($underReviewCollab->id, $ids, true);
                })
            );

        $this->actingAs($engineer)
            ->get(route('tickets.kanban', ['scope' => 'collaborating']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use ($underReviewCollab, $openAssigned): bool {
                    $ids = collect($columns)->flatten(1)->pluck('id')->all();

                    return $ids === [$underReviewCollab->id]
                        && ! in_array($openAssigned->id, $ids, true);
                })
            );

        // TC-12: scope=overdue covers active statuses with target_due_date < today(), excluding today/future/null/terminal
        $overdueInProgress = Ticket::create([
            'ticket_number' => 'ISS-2026-0301',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Overdue In Progress',
            'description' => 'Overdue.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'target_due_date' => today()->subDay()->toDateString(),
        ]);

        $overdueWaiting = Ticket::create([
            'ticket_number' => 'ISS-2026-0302',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => 'Overdue Waiting On User',
            'description' => 'Overdue.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_WAITING_ON_USER,
            'target_due_date' => today()->subDays(3)->toDateString(),
        ]);

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', ['scope' => 'overdue']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns', function ($columns) use (
                    $openAssigned,
                    $underReviewCollab,
                    $overdueInProgress,
                    $overdueWaiting,
                    $inProgressOther,
                    $waitingUnassigned,
                    $resolvedAssigned,
                    $closedOther,
                    $rejectedAssigned,
                    $cancelledTicket,
                    $sortLow
                ): bool {
                    $ids = collect($columns)->flatten(1)->pluck('id')->sort()->values()->all();
                    $expected = collect([
                        $openAssigned->id,
                        $underReviewCollab->id,
                        $overdueInProgress->id,
                        $overdueWaiting->id,
                    ])->sort()->values()->all();

                    return $ids === $expected
                        && ! in_array($inProgressOther->id, $ids, true) // due today
                        && ! in_array($waitingUnassigned->id, $ids, true) // due future
                        && ! in_array($sortLow->id, $ids, true) // due null
                        && ! in_array($resolvedAssigned->id, $ids, true)
                        && ! in_array($closedOther->id, $ids, true)
                        && ! in_array($rejectedAssigned->id, $ids, true)
                        && ! in_array($cancelledTicket->id, $ids, true);
                })
            );

        // TC-13 & TC-16: selectedTicket + selectedTicketPermissions + internal comments & attachments for staff
        TicketComment::create([
            'ticket_id' => $openAssigned->id,
            'user_id' => $requester->id,
            'body' => 'Public requester comment',
            'is_internal' => false,
        ]);
        $internalComment = TicketComment::create([
            'ticket_id' => $openAssigned->id,
            'user_id' => $engineer->id,
            'body' => 'Internal engineering triage note',
            'is_internal' => true,
        ]);

        TicketAttachment::create([
            'ticket_id' => $openAssigned->id,
            'uploaded_by_id' => $requester->id,
            'original_name' => 'public-log.txt',
            'file_path' => 'ticket-attachments/public-log.txt',
            'mime_type' => 'text/plain',
            'file_size' => 128,
            'is_internal' => false,
        ]);
        $internalAttachment = TicketAttachment::create([
            'ticket_id' => $openAssigned->id,
            'uploaded_by_id' => $engineer->id,
            'original_name' => 'internal-trace.log',
            'file_path' => 'ticket-attachments/internal-trace.log',
            'mime_type' => 'text/plain',
            'file_size' => 256,
            'is_internal' => true,
        ]);

        TicketActivity::create([
            'ticket_id' => $openAssigned->id,
            'user_id' => $superadmin->id,
            'action_type' => 'ticket_created',
            'description' => 'Ticket created for modal inspection.',
        ]);

        $this->actingAs($engineer)
            ->get(route('tickets.kanban', ['ticket' => $openAssigned->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedTicket.id', $openAssigned->id)
                ->has('selectedTicket.internal_system')
                ->has('selectedTicket.requester')
                ->has('selectedTicket.lead_engineer')
                ->has('selectedTicket.collaborators')
                ->has('selectedTicket.subtasks', 3)
                ->has('selectedTicket.comments', 2)
                ->has('selectedTicket.attachments', 2)
                ->has('selectedTicket.activities', 1)
                ->where('selectedTicket.comments', function ($comments) use ($internalComment): bool {
                    return collect($comments)->contains(
                        fn ($c) => $c['id'] === $internalComment->id && $c['is_internal'] === true
                    );
                })
                ->where('selectedTicket.attachments', function ($attachments) use ($internalAttachment): bool {
                    return collect($attachments)->contains(
                        fn ($a) => $a['id'] === $internalAttachment->id && $a['is_internal'] === true
                    );
                })
                ->where('selectedTicketPermissions.can_manage_workflow', true)
                ->where('selectedTicketPermissions.can_assign', false)
                ->where('selectedTicketPermissions.can_post_internal_note', true)
                ->where('selectedTicketPermissions.can_manage_subtasks', true)
            );

        // TC-14: Invalid ticket query parameters return null selectedTicket & selectedTicketPermissions
        foreach ([999999, 0, -5, 'abc'] as $invalidTicketParam) {
            $this->actingAs($superadmin)
                ->get(route('tickets.kanban', ['ticket' => $invalidTicketParam]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('selectedTicket', null)
                    ->where('selectedTicketPermissions', null)
                );
        }

        // TC-15: Engineer requesting unassigned or other engineer's ticket receives null modal props
        foreach ([$waitingUnassigned->id, $inProgressOther->id] as $hiddenTicketId) {
            $this->actingAs($engineer)
                ->get(route('tickets.kanban', ['ticket' => $hiddenTicketId]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('selectedTicket', null)
                    ->where('selectedTicketPermissions', null)
                );
        }

        // TC-17: Workflow transition updates Kanban column placement, lifecycle timestamps, and activity log
        $this->assertNull($openAssigned->first_responded_at);
        $this->assertNull($openAssigned->resolved_at);

        $this->actingAs($engineer)
            ->patch(route('tickets.workflow.update', $openAssigned), [
                'status' => Ticket::STATUS_IN_PROGRESS,
            ])
            ->assertRedirect();

        $openAssigned->refresh();
        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $openAssigned->status);
        $this->assertNotNull($openAssigned->first_responded_at);
        $this->assertNull($openAssigned->resolved_at);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $openAssigned->id,
            'action_type' => 'status_changed',
            'old_value' => Ticket::STATUS_OPEN,
            'new_value' => Ticket::STATUS_IN_PROGRESS,
        ]);

        $this->actingAs($engineer)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.in_progress', fn ($cards): bool => collect($cards)->pluck('id')->contains($openAssigned->id))
                ->where('columns.open', fn ($cards): bool => ! collect($cards)->pluck('id')->contains($openAssigned->id))
            );

        $this->actingAs($engineer)
            ->patch(route('tickets.workflow.update', $openAssigned), [
                'status' => Ticket::STATUS_RESOLVED,
                'resolution_summary' => 'Applied failover patch.',
            ])
            ->assertRedirect();

        $openAssigned->refresh();
        $this->assertSame(Ticket::STATUS_RESOLVED, $openAssigned->status);
        $this->assertNotNull($openAssigned->resolved_at);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $openAssigned->id,
            'action_type' => 'status_changed',
            'old_value' => Ticket::STATUS_IN_PROGRESS,
            'new_value' => Ticket::STATUS_RESOLVED,
        ]);

        $this->actingAs($engineer)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.resolved', fn ($cards): bool => collect($cards)->pluck('id')->contains($openAssigned->id))
            );

        // TC-18: Guest redirect, standard user 403, and mid-session deactivated engineer logout + redirect
        Auth::logout();
        $this->get(route('tickets.kanban'))
            ->assertRedirect(route('login'));

        $this->actingAs($requester)
            ->get(route('tickets.kanban'))
            ->assertForbidden();

        $deactivatedEngineer = User::factory()->create([
            'name' => 'Deactivated Kanban Engineer',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);
        $this->actingAs($deactivatedEngineer)->get(route('tickets.kanban'))->assertOk();

        $deactivatedEngineer->forceFill(['is_active' => false])->save();

        $this->actingAs($deactivatedEngineer)
            ->get(route('tickets.kanban'))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        // TC-19: Kanban.vue & TicketDetailModal.vue UI, accessibility, and props.filters watcher checks
        $kanbanVue = (string) file_get_contents(resource_path('js/Pages/Tickets/Kanban.vue'));
        $modalVue = (string) file_get_contents(resource_path('js/Components/TicketDetailModal.vue'));

        foreach ([
            'id="kanban-search"',
            'id="kanban-system"',
            'id="kanban-lead"',
            'id="kanban-type"',
            'id="kanban-priority"',
            'id="kanban-scope"',
            'for="kanban-show-closed-rejected"',
            'id="kanban-show-closed-rejected"',
            ':id="`kanban-move-${ticket.id}`"',
            '() => props.filters',
        ] as $needle) {
            $this->assertStringContainsString($needle, $kanbanVue);
        }

        foreach ([
            'role="dialog"',
            'aria-modal="true"',
            "event.key !== 'Escape'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $modalVue);
        }

        // TC-20: XSS payload stored and returned raw in Inertia props without v-html in Kanban.vue or TicketDetailModal.vue
        $xssTitle = '<script>window.__kanbanXss=1</script>';
        $xssTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-0999',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $engineer->id,
            'title' => $xssTitle,
            'description' => 'Kanban XSS raw prop test.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban', [
                'search' => 'window.__kanbanXss',
                'ticket' => $xssTicket->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('columns.open', 1)
                ->where('columns.open.0.id', $xssTicket->id)
                ->where('columns.open.0.title', $xssTitle)
                ->where('selectedTicket.id', $xssTicket->id)
                ->where('selectedTicket.title', $xssTitle)
            );

        $this->assertStringContainsString('{{ ticket.title }}', $kanbanVue);
        $this->assertStringContainsString('{{ ticket.title }}', $modalVue);
        $this->assertStringNotContainsString('v-html', $kanbanVue);
        $this->assertStringNotContainsString('v-html', $modalVue);
    }

    public function test_phase2_ticket_detail_workspace_triage_and_lifecycle_matrix(): void
    {
        Storage::fake((string) config('filesystems.attachments_disk'));

        $superadmin = User::factory()->create([
            'name' => 'Phase2 Superadmin',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $leadEngineer = User::factory()->create([
            'name' => 'Phase2 Lead Engineer',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $collaboratorA = User::factory()->create([
            'name' => 'Phase2 Collaborator Alpha',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $collaboratorB = User::factory()->create([
            'name' => 'Phase2 Collaborator Beta',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $unassignedEngineer = User::factory()->create([
            'name' => 'Phase2 Unassigned Engineer',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $inactiveEngineer = User::factory()->create([
            'name' => 'Phase2 Inactive Engineer',
            'role' => User::ROLE_ENGINEER,
            'is_active' => false,
        ]);

        $requester = User::factory()->create([
            'name' => 'Phase2 Requester',
            'role' => User::ROLE_USER,
            'department' => 'Operations',
            'is_active' => true,
        ]);

        $otherRequester = User::factory()->create([
            'name' => 'Phase2 Unrelated Requester',
            'role' => User::ROLE_USER,
            'department' => 'HR',
            'is_active' => true,
        ]);

        $system = InternalSystem::create([
            'name' => 'Phase2 Core System',
            'category' => 'Platform',
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-2001',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $system->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Phase 2 Detail Workspace Ticket',
            'description' => 'Testing detail workspace, triage, and lifecycle.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $ticket->collaborators()->attach($collaboratorA->id, ['added_by_id' => $superadmin->id]);

        TicketSubtask::create([
            'ticket_id' => $ticket->id,
            'created_by_id' => $superadmin->id,
            'assigned_engineer_id' => $leadEngineer->id,
            'title' => 'Initial investigation subtask',
            'is_completed' => false,
        ]);

        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $requester->id,
            'action_type' => 'created',
            'description' => 'Ticket created.',
        ]);

        $publicComment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $requester->id,
            'body' => 'Public requester comment.',
            'is_internal' => false,
        ]);

        $internalComment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $leadEngineer->id,
            'body' => 'Internal staff note.',
            'is_internal' => true,
        ]);

        Storage::disk((string) config('filesystems.attachments_disk'))->put('tickets/public-spec.txt', 'public content');
        Storage::disk((string) config('filesystems.attachments_disk'))->put('tickets/internal-debug.log', 'internal content');

        $publicAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_comment_id' => $publicComment->id,
            'uploaded_by_id' => $requester->id,
            'original_name' => 'public-spec.txt',
            'file_path' => 'tickets/public-spec.txt',
            'mime_type' => 'text/plain',
            'file_size' => 14,
            'is_internal' => false,
        ]);

        $internalAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_comment_id' => $internalComment->id,
            'uploaded_by_id' => $leadEngineer->id,
            'original_name' => 'internal-debug.log',
            'file_path' => 'tickets/internal-debug.log',
            'mime_type' => 'text/plain',
            'file_size' => 16,
            'is_internal' => true,
        ]);

        // TC-01: GET /tickets/{ticket} returns complete relations and accurate permissions per role
        $this->actingAs($superadmin)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Show')
                ->where('ticket.id', $ticket->id)
                ->where('ticket.internal_system.id', $system->id)
                ->where('ticket.requester.id', $requester->id)
                ->where('ticket.lead_engineer.id', $leadEngineer->id)
                ->has('ticket.collaborators', 1)
                ->has('ticket.subtasks', 1)
                ->has('ticket.activities', 1)
                ->has('ticket.comments', 2)
                ->has('ticket.attachments', 2)
                ->where('permissions', [
                    'can_manage_workflow' => true,
                    'can_assign' => true,
                    'can_post_internal_note' => true,
                    'can_manage_subtasks' => true,
                    'can_approve_request' => false,
                    'can_close_or_reopen' => true,
                    'can_cancel' => true,
                ])
            );

        foreach ([$leadEngineer, $collaboratorA] as $assignedStaff) {
            $this->actingAs($assignedStaff)
                ->get(route('tickets.show', $ticket))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('ticket.comments', 2)
                    ->has('ticket.attachments', 2)
                    ->where('permissions', [
                        'can_manage_workflow' => true,
                        'can_assign' => false,
                        'can_post_internal_note' => true,
                        'can_manage_subtasks' => true,
                        'can_approve_request' => false,
                        'can_close_or_reopen' => true,
                        'can_cancel' => true,
                    ])
                );
        }

        $this->actingAs($requester)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ticket.comments', 1)
                ->has('ticket.attachments', 1)
                ->where('permissions', [
                    'can_manage_workflow' => false,
                    'can_assign' => false,
                    'can_post_internal_note' => false,
                    'can_manage_subtasks' => false,
                    'can_approve_request' => false,
                    'can_close_or_reopen' => false,
                    'can_cancel' => true,
                ])
            );

        // TC-02: PATCH /tickets/{ticket}/assignment assigns lead + multiple collaborators, stamps first_responded_at, logs activities, and notifies
        $triageTicket = Ticket::create([
            'ticket_number' => 'REQ-2026-2002',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $system->id,
            'requester_id' => $requester->id,
            'title' => 'Triage Assignment Ticket',
            'description' => 'Needs lead and collaborators.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($superadmin)
            ->patch(route('tickets.assignment.update', $triageTicket), [
                'lead_engineer_id' => $leadEngineer->id,
                'collaborator_ids' => [$collaboratorA->id, $collaboratorB->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Ticket assignment updated.');

        $triageTicket->refresh();
        $this->assertSame($leadEngineer->id, $triageTicket->lead_engineer_id);
        $this->assertNotNull($triageTicket->first_responded_at);
        $this->assertEqualsCanonicalizing(
            [$collaboratorA->id, $collaboratorB->id],
            $triageTicket->collaborators()->pluck('users.id')->all()
        );

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $triageTicket->id,
            'action_type' => 'lead_assigned',
            'old_value' => 'Unassigned',
            'new_value' => $leadEngineer->name,
        ]);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $triageTicket->id,
            'action_type' => 'collaborators_updated',
            'new_value' => "{$collaboratorA->name}, {$collaboratorB->name}",
        ]);

        foreach ([$leadEngineer->id, $collaboratorA->id, $collaboratorB->id, $requester->id] as $notifiedUserId) {
            $this->assertDatabaseHas('user_notifications', [
                'ticket_id' => $triageTicket->id,
                'user_id' => $notifiedUserId,
            ]);
        }

        // TC-03: Duplicate collaborator IDs and lead engineer ID in collaborator_ids are deduplicated and lead excluded from pivot
        $this->actingAs($superadmin)
            ->patch(route('tickets.assignment.update', $triageTicket), [
                'lead_engineer_id' => $leadEngineer->id,
                'collaborator_ids' => [$collaboratorA->id, $collaboratorA->id, $leadEngineer->id, $collaboratorB->id],
            ])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(
            [$collaboratorA->id, $collaboratorB->id],
            DB::table('ticket_collaborators')
                ->where('ticket_id', $triageTicket->id)
                ->pluck('user_id')
                ->all()
        );
        $this->assertDatabaseMissing('ticket_collaborators', [
            'ticket_id' => $triageTicket->id,
            'user_id' => $leadEngineer->id,
        ]);

        // TC-04: Setting lead_engineer_id: null and collaborator_ids: [] logs transition to Unassigned and None, and revokes removed engineers' access
        $this->actingAs($superadmin)
            ->patch(route('tickets.assignment.update', $triageTicket), [
                'lead_engineer_id' => null,
                'collaborator_ids' => [],
            ])
            ->assertRedirect();

        $triageTicket->refresh();
        $this->assertNull($triageTicket->lead_engineer_id);
        $this->assertCount(0, $triageTicket->collaborators()->get());
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $triageTicket->id,
            'action_type' => 'lead_assigned',
            'old_value' => $leadEngineer->name,
            'new_value' => 'Unassigned',
        ]);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $triageTicket->id,
            'action_type' => 'collaborators_updated',
            'new_value' => 'None',
        ]);

        $this->actingAs($leadEngineer)->get(route('tickets.show', $triageTicket))->assertForbidden();
        $this->actingAs($collaboratorA)->get(route('tickets.show', $triageTicket))->assertForbidden();
        $this->actingAs($collaboratorB)->get(route('tickets.show', $triageTicket))->assertForbidden();

        // TC-05: Assignment rejects non-existent ID (999999), Superadmin ID, Standard User ID, and inactive Engineer ID
        foreach ([999999, $superadmin->id, $requester->id, $inactiveEngineer->id] as $invalidId) {
            $this->actingAs($superadmin)
                ->patch(route('tickets.assignment.update', $triageTicket), [
                    'lead_engineer_id' => $invalidId,
                ])
                ->assertSessionHasErrors('lead_engineer_id');

            $this->actingAs($superadmin)
                ->patch(route('tickets.assignment.update', $triageTicket), [
                    'collaborator_ids' => [$invalidId],
                ])
                ->assertSessionHasErrors('collaborator_ids.0');
        }

        // TC-06: Submitting updateAssignment with unchanged lead and collaborators records zero new activities and zero duplicate notifications
        $this->actingAs($superadmin)
            ->patch(route('tickets.assignment.update', $triageTicket), [
                'lead_engineer_id' => $leadEngineer->id,
                'collaborator_ids' => [$collaboratorA->id],
            ])
            ->assertRedirect();

        $activityCountBeforeIdempotentAssign = TicketActivity::where('ticket_id', $triageTicket->id)->count();
        $notificationCountBeforeIdempotentAssign = UserNotification::where('ticket_id', $triageTicket->id)->count();

        $this->actingAs($superadmin)
            ->patch(route('tickets.assignment.update', $triageTicket), [
                'lead_engineer_id' => $leadEngineer->id,
                'collaborator_ids' => [$collaboratorA->id],
            ])
            ->assertRedirect();

        $this->assertSame($activityCountBeforeIdempotentAssign, TicketActivity::where('ticket_id', $triageTicket->id)->count());
        $this->assertSame($notificationCountBeforeIdempotentAssign, UserNotification::where('ticket_id', $triageTicket->id)->count());

        // TC-07: Lead engineer and Collaborator update workflow fields, recording individual activities and notifying stakeholders
        $notificationsBeforeLeadWf = UserNotification::where('ticket_id', $triageTicket->id)->count();

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), [
                'status' => Ticket::STATUS_UNDER_REVIEW,
                'priority' => Ticket::PRIORITY_CRITICAL,
                'target_due_date' => '2026-12-15',
                'review_decision_notes' => 'Approved for Phase 2 triage.',
                'resolution_summary' => 'Initial workaround applied.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Ticket workflow updated.');

        foreach (['status_changed', 'priority_changed', 'due_date_updated', 'review_updated', 'resolution_updated'] as $actionType) {
            $this->assertDatabaseHas('ticket_activities', [
                'ticket_id' => $triageTicket->id,
                'user_id' => $leadEngineer->id,
                'action_type' => $actionType,
            ]);
        }
        $this->assertGreaterThan($notificationsBeforeLeadWf, UserNotification::where('ticket_id', $triageTicket->id)->count());

        $this->actingAs($collaboratorA)
            ->patch(route('tickets.workflow.update', $triageTicket), [
                'status' => Ticket::STATUS_IN_PROGRESS,
                'priority' => Ticket::PRIORITY_LOW,
                'target_due_date' => '2026-12-18',
                'review_decision_notes' => 'Collaborator updated review note.',
                'resolution_summary' => 'Collaborator updated resolution.',
            ])
            ->assertRedirect();

        foreach (['status_changed', 'priority_changed', 'due_date_updated', 'review_updated', 'resolution_updated'] as $actionType) {
            $this->assertDatabaseHas('ticket_activities', [
                'ticket_id' => $triageTicket->id,
                'user_id' => $collaboratorA->id,
                'action_type' => $actionType,
            ]);
        }

        // TC-08: Lifecycle timestamps: resolved stamps resolved_at; closed stamps closed_at; moving back to active clears closed_at while preserving resolved_at and first_responded_at
        $firstResponseAnchor = $triageTicket->fresh()->first_responded_at;
        $this->assertNotNull($firstResponseAnchor);

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), [
                'status' => Ticket::STATUS_RESOLVED,
            ])
            ->assertRedirect();

        $triageTicket->refresh();
        $resolvedAnchor = $triageTicket->resolved_at;
        $this->assertNotNull($resolvedAnchor);
        $this->assertNull($triageTicket->closed_at);

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), [
                'status' => Ticket::STATUS_CLOSED,
            ])
            ->assertRedirect();

        $triageTicket->refresh();
        $this->assertNotNull($triageTicket->closed_at);

        foreach ([Ticket::STATUS_OPEN, Ticket::STATUS_UNDER_REVIEW, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_WAITING_ON_USER] as $activeStatus) {
            $this->actingAs($leadEngineer)
                ->patch(route('tickets.workflow.update', $triageTicket), [
                    'status' => Ticket::STATUS_CLOSED,
                ])
                ->assertRedirect();
            $this->assertNotNull($triageTicket->fresh()->closed_at);

            $this->actingAs($leadEngineer)
                ->patch(route('tickets.workflow.update', $triageTicket), [
                    'status' => $activeStatus,
                ])
                ->assertRedirect();

            $triageTicket->refresh();
            $this->assertSame($activeStatus, $triageTicket->status);
            $this->assertNull($triageTicket->closed_at);
            $this->assertEquals($resolvedAnchor->timestamp, $triageTicket->resolved_at?->timestamp);
            $this->assertEquals($firstResponseAnchor->timestamp, $triageTicket->first_responded_at?->timestamp);
        }

        // TC-09: Target due date parity: setting 2026-12-20 logs "Target due date updated to 2026-12-20."; clearing with null or "" logs "Target due date updated (cleared)."
        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), [
                'target_due_date' => '2026-12-20',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $triageTicket->id,
            'action_type' => 'due_date_updated',
            'description' => 'Target due date updated to 2026-12-20.',
            'new_value' => '2026-12-20',
        ]);

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), [
                'target_due_date' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $triageTicket->id,
            'action_type' => 'due_date_updated',
            'description' => 'Target due date updated (cleared).',
            'old_value' => '2026-12-20',
            'new_value' => null,
        ]);

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), [
                'target_due_date' => '2026-12-22',
            ])
            ->assertRedirect();

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), [
                'target_due_date' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $triageTicket->id,
            'action_type' => 'due_date_updated',
            'description' => 'Target due date updated (cleared).',
            'old_value' => '2026-12-22',
            'new_value' => null,
        ]);

        // TC-10: Staff workflow idempotency: unchanged values record zero activities and zero notifications
        $triageTicket->refresh();
        $actCountBeforeIdempotentWf = TicketActivity::where('ticket_id', $triageTicket->id)->count();
        $notifCountBeforeIdempotentWf = UserNotification::where('ticket_id', $triageTicket->id)->count();

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), [
                'status' => $triageTicket->status,
                'priority' => $triageTicket->priority,
                'target_due_date' => $triageTicket->target_due_date?->toDateString(),
                'review_decision_notes' => $triageTicket->review_decision_notes,
                'resolution_summary' => $triageTicket->resolution_summary,
            ])
            ->assertRedirect();

        $this->assertSame($actCountBeforeIdempotentWf, TicketActivity::where('ticket_id', $triageTicket->id)->count());
        $this->assertSame($notifCountBeforeIdempotentWf, UserNotification::where('ticket_id', $triageTicket->id)->count());

        // TC-11: Staff workflow validation & length limits
        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), ['status' => ''])
            ->assertSessionHasErrors('status');
        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), ['status' => 'RESOLVED'])
            ->assertSessionHasErrors('status');

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), ['priority' => 'urgent'])
            ->assertSessionHasErrors('priority');
        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), ['priority' => 'HIGH'])
            ->assertSessionHasErrors('priority');

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), ['target_due_date' => 'not-a-date'])
            ->assertSessionHasErrors('target_due_date');

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), ['review_decision_notes' => str_repeat('r', 10001)])
            ->assertSessionHasErrors('review_decision_notes');
        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), ['resolution_summary' => str_repeat('s', 10001)])
            ->assertSessionHasErrors('resolution_summary');

        $maxReview = str_repeat('R', 10000);
        $maxResolution = str_repeat('S', 10000);
        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $triageTicket), [
                'review_decision_notes' => $maxReview,
                'resolution_summary' => $maxResolution,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $triageTicket->refresh();
        $this->assertSame(10000, strlen((string) $triageTicket->review_decision_notes));
        $this->assertSame(10000, strlen((string) $triageTicket->resolution_summary));

        // TC-12: Requester lifecycle action: confirm_close succeeds in resolved & waiting_on_user; fails in all other statuses
        foreach ([Ticket::STATUS_RESOLVED, Ticket::STATUS_WAITING_ON_USER] as $closeableStatus) {
            $closeTicket = Ticket::create([
                'ticket_number' => 'ISS-2026-'.($closeableStatus === Ticket::STATUS_RESOLVED ? '2010' : '2011'),
                'type' => Ticket::TYPE_ISSUE,
                'internal_system_id' => $system->id,
                'requester_id' => $requester->id,
                'lead_engineer_id' => $leadEngineer->id,
                'title' => "Closeable {$closeableStatus}",
                'description' => 'Confirm close test.',
                'priority' => Ticket::PRIORITY_MEDIUM,
                'status' => $closeableStatus,
            ]);
            $closeTicket->collaborators()->attach($collaboratorA->id, ['added_by_id' => $superadmin->id]);

            $this->actingAs($requester)
                ->patch(route('tickets.workflow.update', $closeTicket), ['action' => 'confirm_close'])
                ->assertRedirect()
                ->assertSessionHas('success', 'Ticket confirmed and closed.');

            $closeTicket->refresh();
            $this->assertSame(Ticket::STATUS_CLOSED, $closeTicket->status);
            $this->assertNotNull($closeTicket->closed_at);
            $this->assertDatabaseHas('ticket_activities', [
                'ticket_id' => $closeTicket->id,
                'user_id' => $requester->id,
                'action_type' => 'status_changed',
                'old_value' => $closeableStatus,
                'new_value' => Ticket::STATUS_CLOSED,
            ]);
            $this->assertDatabaseHas('user_notifications', [
                'ticket_id' => $closeTicket->id,
                'user_id' => $leadEngineer->id,
            ]);
            $this->assertDatabaseHas('user_notifications', [
                'ticket_id' => $closeTicket->id,
                'user_id' => $collaboratorA->id,
            ]);
        }

        foreach ([
            Ticket::STATUS_OPEN,
            Ticket::STATUS_UNDER_REVIEW,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_CLOSED,
            Ticket::STATUS_REJECTED,
            Ticket::STATUS_CANCELLED,
        ] as $idx => $invalidCloseStatus) {
            $blockedCloseTicket = Ticket::create([
                'ticket_number' => 'ISS-2026-21'.str_pad((string) $idx, 2, '0', STR_PAD_LEFT),
                'type' => Ticket::TYPE_ISSUE,
                'requester_id' => $requester->id,
                'title' => "Blocked close {$invalidCloseStatus}",
                'description' => 'Should fail confirm_close.',
                'priority' => Ticket::PRIORITY_LOW,
                'status' => $invalidCloseStatus,
            ]);

            $this->actingAs($requester)
                ->patch(route('tickets.workflow.update', $blockedCloseTicket), ['action' => 'confirm_close'])
                ->assertSessionHasErrors('action')
                ->assertSessionHas('error', 'This ticket cannot be confirmed closed in its current status.');
        }

        // TC-13: Requester lifecycle action: reopen
        // Resolved + lead assigned -> in_progress, clears closed_at, empty reason creates NO comment
        $reopenLeadTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-2020',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Reopen With Lead',
            'description' => 'Reopen test with lead.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_RESOLVED,
            'resolved_at' => now()->subDay(),
            'closed_at' => now()->subHour(),
        ]);

        $this->actingAs($requester)
            ->patch(route('tickets.workflow.update', $reopenLeadTicket), [
                'action' => 'reopen',
                'reopen_reason' => '',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Ticket reopened.');

        $reopenLeadTicket->refresh();
        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $reopenLeadTicket->status);
        $this->assertNull($reopenLeadTicket->closed_at);
        $this->assertSame(0, TicketComment::where('ticket_id', $reopenLeadTicket->id)->count());

        // Closed + no lead -> open, with 5000-char reason creates public comment; 5001 chars rejected
        $reopenNoLeadTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-2021',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => null,
            'title' => 'Reopen Without Lead',
            'description' => 'Reopen test without lead.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_CLOSED,
            'closed_at' => now()->subHour(),
        ]);

        $this->actingAs($requester)
            ->patch(route('tickets.workflow.update', $reopenNoLeadTicket), [
                'action' => 'reopen',
                'reopen_reason' => str_repeat('x', 5001),
            ])
            ->assertSessionHasErrors('reopen_reason');

        $exactReason = str_repeat('Z', 5000);
        $this->actingAs($requester)
            ->patch(route('tickets.workflow.update', $reopenNoLeadTicket), [
                'action' => 'reopen',
                'reopen_reason' => $exactReason,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reopenNoLeadTicket->refresh();
        $this->assertSame(Ticket::STATUS_OPEN, $reopenNoLeadTicket->status);
        $this->assertNull($reopenNoLeadTicket->closed_at);
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $reopenNoLeadTicket->id,
            'user_id' => $requester->id,
            'body' => $exactReason,
            'is_internal' => false,
        ]);

        foreach ([
            Ticket::STATUS_OPEN,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_CANCELLED,
            Ticket::STATUS_REJECTED,
        ] as $idx => $invalidReopenStatus) {
            $blockedReopenTicket = Ticket::create([
                'ticket_number' => 'ISS-2026-22'.str_pad((string) $idx, 2, '0', STR_PAD_LEFT),
                'type' => Ticket::TYPE_ISSUE,
                'requester_id' => $requester->id,
                'title' => "Blocked reopen {$invalidReopenStatus}",
                'description' => 'Should fail reopen.',
                'priority' => Ticket::PRIORITY_LOW,
                'status' => $invalidReopenStatus,
            ]);

            $this->actingAs($requester)
                ->patch(route('tickets.workflow.update', $blockedReopenTicket), ['action' => 'reopen'])
                ->assertSessionHasErrors('action')
                ->assertSessionHas('error', 'Only resolved or closed tickets can be reopened.');
        }

        // TC-14: Requester lifecycle action: cancel succeeds only when status === open
        $cancellableTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-2030',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Cancellable Open Ticket',
            'description' => 'Cancel test.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($requester)
            ->patch(route('tickets.workflow.update', $cancellableTicket), ['action' => 'cancel'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Ticket cancelled.');

        $this->assertSame(Ticket::STATUS_CANCELLED, $cancellableTicket->fresh()->status);

        foreach ([
            Ticket::STATUS_UNDER_REVIEW,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_WAITING_ON_USER,
            Ticket::STATUS_RESOLVED,
            Ticket::STATUS_CLOSED,
            Ticket::STATUS_REJECTED,
            Ticket::STATUS_CANCELLED,
        ] as $idx => $invalidCancelStatus) {
            $blockedCancelTicket = Ticket::create([
                'ticket_number' => 'ISS-2026-23'.str_pad((string) $idx, 2, '0', STR_PAD_LEFT),
                'type' => Ticket::TYPE_ISSUE,
                'requester_id' => $requester->id,
                'title' => "Blocked cancel {$invalidCancelStatus}",
                'description' => 'Should fail cancel.',
                'priority' => Ticket::PRIORITY_LOW,
                'status' => $invalidCancelStatus,
            ]);

            $this->actingAs($requester)
                ->patch(route('tickets.workflow.update', $blockedCancelTicket), ['action' => 'cancel'])
                ->assertSessionHasErrors('action')
                ->assertSessionHas('error', 'Only open tickets can be cancelled.');
        }

        // TC-15: Staff self-requester first response guard
        $selfSubmittedTicket = Ticket::create([
            'ticket_number' => 'REQ-2026-2040',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $superadmin->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Superadmin Self Submitted',
            'description' => 'First response guard test.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($superadmin)
            ->patch(route('tickets.workflow.update', $selfSubmittedTicket), [
                'priority' => Ticket::PRIORITY_HIGH,
            ])
            ->assertRedirect();

        $this->assertNull($selfSubmittedTicket->fresh()->first_responded_at);

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $selfSubmittedTicket), [
                'status' => Ticket::STATUS_IN_PROGRESS,
            ])
            ->assertRedirect();

        $this->assertNotNull($selfSubmittedTicket->fresh()->first_responded_at);

        // TC-16: Attachment download: authorized user downloads existing file (200 OK); missing disk file returns 404 Not Found
        $this->actingAs($requester)
            ->get(route('attachments.download', $publicAttachment))
            ->assertOk();

        $missingAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'uploaded_by_id' => $requester->id,
            'original_name' => 'missing-file.txt',
            'file_path' => 'tickets/does-not-exist.txt',
            'mime_type' => 'text/plain',
            'file_size' => 10,
            'is_internal' => false,
        ]);

        $this->actingAs($requester)
            ->get(route('attachments.download', $missingAttachment))
            ->assertNotFound();

        // TC-17: Attachment download RBAC & internal attachment isolation
        $this->actingAs($otherRequester)
            ->get(route('attachments.download', $publicAttachment))
            ->assertForbidden();
        $this->actingAs($unassignedEngineer)
            ->get(route('attachments.download', $publicAttachment))
            ->assertForbidden();

        $this->actingAs($requester)
            ->get(route('attachments.download', $internalAttachment))
            ->assertForbidden();
        $this->actingAs($leadEngineer)
            ->get(route('attachments.download', $internalAttachment))
            ->assertOk();
        $this->actingAs($superadmin)
            ->get(route('attachments.download', $internalAttachment))
            ->assertOk();

        // TC-18: RBAC isolation on workflow & assignment endpoints
        $this->actingAs($unassignedEngineer)
            ->patch(route('tickets.workflow.update', $ticket), ['status' => Ticket::STATUS_IN_PROGRESS])
            ->assertForbidden();
        $this->actingAs($otherRequester)
            ->patch(route('tickets.workflow.update', $ticket), ['action' => 'cancel'])
            ->assertForbidden();

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.assignment.update', $ticket), ['lead_engineer_id' => $collaboratorA->id])
            ->assertForbidden();
        $this->actingAs($requester)
            ->patch(route('tickets.assignment.update', $ticket), ['lead_engineer_id' => $collaboratorA->id])
            ->assertForbidden();

        // TC-19: UI state & accessibility in TicketDetailWorkspace.vue
        $workspaceVue = (string) file_get_contents(resource_path('js/Components/TicketDetailWorkspace.vue'));
        $showVue = (string) file_get_contents(resource_path('js/Pages/Tickets/Show.vue'));

        foreach ([
            'for="wf-status"',
            'id="wf-status"',
            'for="wf-priority"',
            'id="wf-priority"',
            'for="wf-due"',
            'id="wf-due"',
            'for="wf-review"',
            'id="wf-review"',
            'for="wf-resolution"',
            'id="wf-resolution"',
            'for="lead_engineer_id"',
            'id="lead_engineer_id"',
            'for="reopen_reason"',
            'id="reopen_reason"',
            'maxlength="5000"',
            'maxlength="10000"',
            ':disabled="String(eng.id) === assignmentForm.lead_engineer_id"',
            ':disabled="workflowForm.processing"',
            ':disabled="assignmentForm.processing"',
            ':disabled="requesterActionProcessing"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workspaceVue);
        }

        // TC-20: Security & escaping: XSS payloads stored raw in DB/Inertia props and rendered without v-html
        $xssReview = '<script>window.__detailXss=1</script>';
        $xssResolution = '<img src=x onerror=alert(1)>';
        $xssReopen = '<script>window.__reopenXss=1</script>';

        $xssTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-2099',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'XSS Detail Workspace Test',
            'description' => 'XSS escaping verification.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $xssTicket), [
                'status' => Ticket::STATUS_RESOLVED,
                'review_decision_notes' => $xssReview,
                'resolution_summary' => $xssResolution,
            ])
            ->assertRedirect();

        $this->actingAs($requester)
            ->patch(route('tickets.workflow.update', $xssTicket), [
                'action' => 'reopen',
                'reopen_reason' => $xssReopen,
            ])
            ->assertRedirect();

        $xssTicket->refresh();
        $this->assertSame($xssReview, $xssTicket->review_decision_notes);
        $this->assertSame($xssResolution, $xssTicket->resolution_summary);
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $xssTicket->id,
            'body' => $xssReopen,
            'is_internal' => false,
        ]);

        $this->actingAs($superadmin)
            ->get(route('tickets.show', $xssTicket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ticket.review_decision_notes', $xssReview)
                ->where('ticket.resolution_summary', $xssResolution)
                ->where('ticket.comments.0.body', $xssReopen)
            );

        $this->assertStringNotContainsString('v-html', $workspaceVue);
        $this->assertStringNotContainsString('v-html', $showVue);
    }

    public function test_phase3_subtasks_discussion_internal_notes_and_audit_log_matrix(): void
    {
        Storage::fake((string) config('filesystems.attachments_disk'));

        $superadmin = User::factory()->create([
            'name' => 'Phase3 Superadmin',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $leadEngineer = User::factory()->create([
            'name' => 'Phase3 Lead',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $collaborator = User::factory()->create([
            'name' => 'Phase3 Collaborator',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $unassignedEngineer = User::factory()->create([
            'name' => 'Phase3 Unassigned',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $inactiveEngineer = User::factory()->create([
            'name' => 'Phase3 Inactive',
            'role' => User::ROLE_ENGINEER,
            'is_active' => false,
        ]);

        $requester = User::factory()->create([
            'name' => 'Phase3 Requester',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $otherRequester = User::factory()->create([
            'name' => 'Phase3 Other Requester',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-3001',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Phase 3 Subtask & Discussion Ticket',
            'description' => 'Primary ticket for Phase 3 verification.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);
        $ticket->collaborators()->attach($collaborator->id, ['added_by_id' => $superadmin->id]);

        $otherTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-3002',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => $unassignedEngineer->id,
            'title' => 'Phase 3 Secondary Ticket',
            'description' => 'Separate ticket for isolation tests.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        // TC-01: Superadmin, Lead, or Collaborator creates a subtask with assigned_engineer_id;
        // sets is_completed = false, stamps first_responded_at, and logs subtask_added.
        $this->assertNull($ticket->first_responded_at);

        $this->actingAs($leadEngineer)
            ->post(route('tickets.subtasks.store', $ticket), [
                'title' => 'Lead verification step',
                'assigned_engineer_id' => $collaborator->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Subtask added.');

        $leadSubtask = TicketSubtask::query()
            ->where('ticket_id', $ticket->id)
            ->where('title', 'Lead verification step')
            ->firstOrFail();

        $this->assertFalse($leadSubtask->is_completed);
        $this->assertNull($leadSubtask->completed_at);
        $this->assertSame($collaborator->id, $leadSubtask->assigned_engineer_id);
        $this->assertSame($leadEngineer->id, $leadSubtask->created_by_id);
        $this->assertNotNull($ticket->fresh()->first_responded_at);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'user_id' => $leadEngineer->id,
            'action_type' => 'subtask_added',
            'old_value' => null,
            'new_value' => 'Lead verification step',
            'description' => 'Added subtask: Lead verification step',
        ]);

        // Superadmin and Collaborator can also create subtasks
        $superadminSubtaskTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-3003',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Superadmin Subtask First Response Ticket',
            'description' => 'Checks superadmin first response on subtask create.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($superadmin)
            ->post(route('tickets.subtasks.store', $superadminSubtaskTicket), [
                'title' => 'Superadmin triage step',
                'assigned_engineer_id' => $leadEngineer->id,
            ])
            ->assertRedirect();

        $this->assertNotNull($superadminSubtaskTicket->fresh()->first_responded_at);
        $this->assertDatabaseHas('ticket_subtasks', [
            'ticket_id' => $superadminSubtaskTicket->id,
            'title' => 'Superadmin triage step',
            'assigned_engineer_id' => $leadEngineer->id,
            'is_completed' => false,
        ]);

        $this->actingAs($collaborator)
            ->post(route('tickets.subtasks.store', $ticket), [
                'title' => 'Collaborator check step',
                'assigned_engineer_id' => $leadEngineer->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_subtasks', [
            'ticket_id' => $ticket->id,
            'title' => 'Collaborator check step',
            'assigned_engineer_id' => $leadEngineer->id,
            'is_completed' => false,
            'created_by_id' => $collaborator->id,
        ]);

        // TC-02: Creating a subtask with assigned_engineer_id: null or "" succeeds with assigned_engineer_id = null.
        $this->actingAs($leadEngineer)
            ->post(route('tickets.subtasks.store', $ticket), [
                'title' => 'Unassigned null step',
                'assigned_engineer_id' => null,
            ])
            ->assertRedirect();

        $this->actingAs($collaborator)
            ->post(route('tickets.subtasks.store', $ticket), [
                'title' => 'Unassigned empty string step',
                'assigned_engineer_id' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_subtasks', [
            'ticket_id' => $ticket->id,
            'title' => 'Unassigned null step',
            'assigned_engineer_id' => null,
        ]);
        $this->assertDatabaseHas('ticket_subtasks', [
            'ticket_id' => $ticket->id,
            'title' => 'Unassigned empty string step',
            'assigned_engineer_id' => null,
        ]);

        // TC-03: Toggling subtask marks is_completed = true, stamps completed_at, and logs subtask_toggled (pending -> completed);
        // toggling again marks is_completed = false, clears completed_at, and logs completed -> pending.
        $this->actingAs($collaborator)
            ->patch(route('tickets.subtasks.toggle', [$ticket, $leadSubtask]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Subtask status updated.');

        $leadSubtask->refresh();
        $this->assertTrue($leadSubtask->is_completed);
        $this->assertNotNull($leadSubtask->completed_at);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'user_id' => $collaborator->id,
            'action_type' => 'subtask_toggled',
            'old_value' => 'pending',
            'new_value' => 'completed',
            'description' => 'Completed subtask: Lead verification step',
        ]);

        $this->actingAs($superadmin)
            ->patch(route('tickets.subtasks.toggle', [$ticket, $leadSubtask]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Subtask status updated.');

        $leadSubtask->refresh();
        $this->assertFalse($leadSubtask->is_completed);
        $this->assertNull($leadSubtask->completed_at);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'user_id' => $superadmin->id,
            'action_type' => 'subtask_toggled',
            'old_value' => 'completed',
            'new_value' => 'pending',
            'description' => 'Reopened subtask: Lead verification step',
        ]);

        // TC-04: DELETE /tickets/{ticket}/subtasks/{subtask} removes the subtask, stamps first_responded_at if not yet set, and logs subtask_removed.
        $deleteFirstRespTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-3004',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Subtask Delete First Response Ticket',
            'description' => 'Verifies destroy stamps first_responded_at.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $seededSubtask = TicketSubtask::create([
            'ticket_id' => $deleteFirstRespTicket->id,
            'title' => 'Subtask to remove',
            'assigned_engineer_id' => $leadEngineer->id,
            'is_completed' => false,
            'created_by_id' => $superadmin->id,
        ]);

        $this->assertNull($deleteFirstRespTicket->first_responded_at);

        $this->actingAs($leadEngineer)
            ->delete(route('tickets.subtasks.destroy', [$deleteFirstRespTicket, $seededSubtask]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Subtask removed.');

        $this->assertDatabaseMissing('ticket_subtasks', ['id' => $seededSubtask->id]);
        $this->assertNotNull($deleteFirstRespTicket->fresh()->first_responded_at);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $deleteFirstRespTicket->id,
            'user_id' => $leadEngineer->id,
            'action_type' => 'subtask_removed',
            'old_value' => 'Subtask to remove',
            'new_value' => null,
            'description' => 'Removed subtask: Subtask to remove',
        ]);

        // TC-05: Subtask title validation: title: "" and "   " fail validation; 255-char title succeeds;
        // 256-char title fails validation without creating a subtask or activity.
        $subtaskCountBefore = TicketSubtask::query()->where('ticket_id', $ticket->id)->count();
        $activityCountBefore = TicketActivity::query()->where('ticket_id', $ticket->id)->count();

        foreach (['', '   '] as $invalidTitle) {
            $this->actingAs($leadEngineer)
                ->post(route('tickets.subtasks.store', $ticket), [
                    'title' => $invalidTitle,
                ])
                ->assertSessionHasErrors('title');
        }

        $title256 = str_repeat('a', 256);
        $this->actingAs($leadEngineer)
            ->post(route('tickets.subtasks.store', $ticket), [
                'title' => $title256,
            ])
            ->assertSessionHasErrors('title');

        $this->assertSame($subtaskCountBefore, TicketSubtask::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame($activityCountBefore, TicketActivity::query()->where('ticket_id', $ticket->id)->count());

        $title255 = str_repeat('b', 255);
        $this->actingAs($leadEngineer)
            ->post(route('tickets.subtasks.store', $ticket), [
                'title' => $title255,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_subtasks', [
            'ticket_id' => $ticket->id,
            'title' => $title255,
        ]);

        // TC-06: Subtask assignee validation guards: assigned_engineer_id rejects non-existent ID (999999),
        // Superadmin ID, Standard User ID, and inactive Engineer ID.
        foreach ([999999, $superadmin->id, $requester->id, $inactiveEngineer->id] as $invalidAssigneeId) {
            $this->actingAs($superadmin)
                ->post(route('tickets.subtasks.store', $ticket), [
                    'title' => "Invalid assignee {$invalidAssigneeId}",
                    'assigned_engineer_id' => $invalidAssigneeId,
                ])
                ->assertSessionHasErrors('assigned_engineer_id');
        }

        // TC-07: Cross-ticket isolation & RBAC precedence: toggle and destroy with a subtask belonging
        // to a different ticket return 404 Not Found for authorized staff and 403 Forbidden first for unauthorized actors.
        $otherSubtask = TicketSubtask::create([
            'ticket_id' => $otherTicket->id,
            'title' => 'Other ticket subtask',
            'assigned_engineer_id' => $unassignedEngineer->id,
            'is_completed' => false,
            'created_by_id' => $superadmin->id,
        ]);

        $this->actingAs($leadEngineer)
            ->patch(route('tickets.subtasks.toggle', [$ticket, $otherSubtask]))
            ->assertNotFound();

        $this->actingAs($leadEngineer)
            ->delete(route('tickets.subtasks.destroy', [$ticket, $otherSubtask]))
            ->assertNotFound();

        foreach ([$requester, $unassignedEngineer] as $unauthorizedActor) {
            $this->actingAs($unauthorizedActor)
                ->patch(route('tickets.subtasks.toggle', [$ticket, $otherSubtask]))
                ->assertForbidden();

            $this->actingAs($unauthorizedActor)
                ->delete(route('tickets.subtasks.destroy', [$ticket, $otherSubtask]))
                ->assertForbidden();
        }

        // TC-08: Subtask RBAC: Requester and unassigned engineer receive 403 Forbidden on subtask store, toggle, and destroy.
        foreach ([$requester, $unassignedEngineer] as $unauthorizedActor) {
            $this->actingAs($unauthorizedActor)
                ->post(route('tickets.subtasks.store', $ticket), ['title' => 'Blocked subtask'])
                ->assertForbidden();

            $this->actingAs($unauthorizedActor)
                ->patch(route('tickets.subtasks.toggle', [$ticket, $leadSubtask]))
                ->assertForbidden();

            $this->actingAs($unauthorizedActor)
                ->delete(route('tickets.subtasks.destroy', [$ticket, $leadSubtask]))
                ->assertForbidden();
        }

        // TC-09: Public comment with multiple attachments: stores attachments (is_internal = false), logs comment_added,
        // and notifies lead, collaborators, and requester (excluding author).
        UserNotification::query()->delete();

        $fileOne = UploadedFile::fake()->create('trace.log', 64, 'text/plain');
        $fileTwo = UploadedFile::fake()->create('screenshot.png', 128, 'image/png');

        $this->actingAs($leadEngineer)
            ->post(route('tickets.comments.store', $ticket), [
                'body' => 'Public investigation update with two files.',
                'is_internal' => false,
                'attachments' => [$fileOne, $fileTwo],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Comment posted.');

        $publicComment = TicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->where('body', 'Public investigation update with two files.')
            ->firstOrFail();

        $this->assertFalse($publicComment->is_internal);

        $publicCommentAttachments = TicketAttachment::query()
            ->where('ticket_comment_id', $publicComment->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $publicCommentAttachments);
        foreach ($publicCommentAttachments as $att) {
            $this->assertFalse($att->is_internal);
            $this->assertSame($ticket->id, $att->ticket_id);
            $this->assertSame($leadEngineer->id, $att->uploaded_by_id);
            Storage::disk((string) config('filesystems.attachments_disk'))->assertExists($att->file_path);
        }

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'user_id' => $leadEngineer->id,
            'action_type' => 'comment_added',
            'description' => "{$leadEngineer->name} added a comment.",
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $collaborator->id,
            'ticket_id' => $ticket->id,
            'title' => "New Comment on {$ticket->ticket_number}",
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $ticket->id,
            'title' => "New Comment on {$ticket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $leadEngineer->id,
            'ticket_id' => $ticket->id,
            'title' => "New Comment on {$ticket->ticket_number}",
        ]);

        // TC-10: Staff internal note (is_internal: true) with attachment: both comment and attachment have is_internal = true,
        // zero comment_added activity is logged, and only assigned staff (excluding author) receive Internal Note on {ticket_number}
        // notifications (requester does not).
        UserNotification::query()->delete();
        $commentActivitiesBeforeInternal = TicketActivity::query()
            ->where('ticket_id', $ticket->id)
            ->where('action_type', 'comment_added')
            ->count();

        $internalFile = UploadedFile::fake()->create('internal-dump.txt', 32, 'text/plain');

        $this->actingAs($leadEngineer)
            ->post(route('tickets.comments.store', $ticket), [
                'body' => 'Internal staff root cause note.',
                'is_internal' => true,
                'attachments' => [$internalFile],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Internal engineering note posted.');

        $internalComment = TicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->where('body', 'Internal staff root cause note.')
            ->firstOrFail();

        $this->assertTrue($internalComment->is_internal);

        $internalAttachment = TicketAttachment::query()
            ->where('ticket_comment_id', $internalComment->id)
            ->firstOrFail();
        $this->assertTrue($internalAttachment->is_internal);
        Storage::disk((string) config('filesystems.attachments_disk'))->assertExists($internalAttachment->file_path);

        $this->assertSame(
            $commentActivitiesBeforeInternal,
            TicketActivity::query()
                ->where('ticket_id', $ticket->id)
                ->where('action_type', 'comment_added')
                ->count()
        );

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $collaborator->id,
            'ticket_id' => $ticket->id,
            'title' => "Internal Note on {$ticket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $leadEngineer->id,
            'ticket_id' => $ticket->id,
            'title' => "Internal Note on {$ticket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $ticket->id,
        ]);

        // TC-11: Privilege escalation guard: Standard user submitting is_internal: true with an attachment
        // has is_internal forced to false on both the comment and the attachment.
        $requesterAttemptFile = UploadedFile::fake()->create('requester-upload.txt', 16, 'text/plain');

        $this->actingAs($requester)
            ->post(route('tickets.comments.store', $ticket), [
                'body' => 'Requester attempting internal note.',
                'is_internal' => true,
                'attachments' => [$requesterAttemptFile],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Comment posted.');

        $requesterComment = TicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->where('body', 'Requester attempting internal note.')
            ->firstOrFail();
        $this->assertFalse($requesterComment->is_internal);

        $requesterAttachment = TicketAttachment::query()
            ->where('ticket_comment_id', $requesterComment->id)
            ->firstOrFail();
        $this->assertFalse($requesterAttachment->is_internal);

        // TC-12: Visibility filtering on GET /tickets/{ticket}: Standard user sees only public comments
        // and public attachments; staff sees both public and internal items.
        $ticketLevelInternalAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_comment_id' => null,
            'uploaded_by_id' => $leadEngineer->id,
            'original_name' => 'ticket-internal-only.txt',
            'file_path' => 'tickets/internal-only.txt',
            'mime_type' => 'text/plain',
            'file_size' => 100,
            'is_internal' => true,
        ]);

        $this->actingAs($requester)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ticket.comments', 2)
                ->where('ticket.comments.0.id', $publicComment->id)
                ->where('ticket.comments.0.is_internal', false)
                ->where('ticket.comments.1.id', $requesterComment->id)
                ->where('ticket.comments.1.is_internal', false)
                ->has('ticket.attachments', 3)
                ->where('ticket.attachments.0.is_internal', false)
                ->where('ticket.attachments.1.is_internal', false)
                ->where('ticket.attachments.2.is_internal', false)
            );

        $this->actingAs($leadEngineer)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ticket.comments', 3)
                ->where('ticket.comments.0.id', $publicComment->id)
                ->where('ticket.comments.1.id', $internalComment->id)
                ->where('ticket.comments.1.is_internal', true)
                ->has('ticket.comments.1.attachments', 1)
                ->where('ticket.comments.1.attachments.0.id', $internalAttachment->id)
                ->where('ticket.comments.2.id', $requesterComment->id)
                ->has('ticket.attachments', 5)
                ->where('ticket.attachments.2.id', $internalAttachment->id)
                ->where('ticket.attachments.4.id', $ticketLevelInternalAttachment->id)
            );

        // TC-13: Comment body validation: body: "" and "   " fail validation; 10000-char body succeeds;
        // 10001-char body fails validation with zero side effects (no comment, no activity, no notification).
        UserNotification::query()->delete();
        $commentsBeforeValidation = TicketComment::query()->where('ticket_id', $ticket->id)->count();
        $activitiesBeforeValidation = TicketActivity::query()->where('ticket_id', $ticket->id)->count();

        foreach (['', '   '] as $invalidBody) {
            $this->actingAs($requester)
                ->post(route('tickets.comments.store', $ticket), [
                    'body' => $invalidBody,
                ])
                ->assertSessionHasErrors('body');
        }

        $this->actingAs($requester)
            ->post(route('tickets.comments.store', $ticket), [
                'body' => str_repeat('c', 10001),
            ])
            ->assertSessionHasErrors('body');

        $this->assertSame($commentsBeforeValidation, TicketComment::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame($activitiesBeforeValidation, TicketActivity::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(0, UserNotification::query()->count());

        $body10000 = str_repeat('d', 10000);
        $this->actingAs($requester)
            ->post(route('tickets.comments.store', $ticket), [
                'body' => $body10000,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'body' => $body10000,
        ]);

        // TC-14: Comment attachment size & type boundaries: 10240 KB file succeeds;
        // 10241 KB file and non-file payload (['not-a-file']) fail validation on attachments.0 with zero comments persisted.
        $boundaryTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-3005',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Attachment Boundary Ticket',
            'description' => 'Tests comment attachment validation boundaries.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $oversizedCommentFile = UploadedFile::fake()->create('too-big.bin', 10241, 'application/octet-stream');
        $this->actingAs($requester)
            ->post(route('tickets.comments.store', $boundaryTicket), [
                'body' => 'Should fail oversized file',
                'attachments' => [$oversizedCommentFile],
            ])
            ->assertSessionHasErrors('attachments.0');

        $this->actingAs($requester)
            ->post(route('tickets.comments.store', $boundaryTicket), [
                'body' => 'Should fail non-file payload',
                'attachments' => ['not-a-file'],
            ])
            ->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, TicketComment::query()->where('ticket_id', $boundaryTicket->id)->count());

        $maxSizeCommentFile = UploadedFile::fake()->create('max-allowed.bin', 10240, 'application/octet-stream');
        $this->actingAs($requester)
            ->post(route('tickets.comments.store', $boundaryTicket), [
                'body' => 'Exact 10240 KB boundary upload',
                'attachments' => [$maxSizeCommentFile],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, TicketComment::query()->where('ticket_id', $boundaryTicket->id)->count());

        // TC-15: Comment RBAC: unrelated requester and unassigned engineer receive 403 Forbidden on POST /tickets/{ticket}/comments.
        foreach ([$otherRequester, $unassignedEngineer] as $unauthorizedCommenter) {
            $this->actingAs($unauthorizedCommenter)
                ->post(route('tickets.comments.store', $ticket), [
                    'body' => 'Unauthorized comment attempt',
                ])
                ->assertForbidden();
        }

        // TC-16: Comment first-response timestamp logic: requester commenting leaves first_responded_at null;
        // staff commenting or posting an internal note stamps first_responded_at (unless staff is the ticket requester).
        $firstRespCommentTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-3006',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'First Response Comment Ticket',
            'description' => 'Tests requester vs staff comment first response.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($requester)
            ->post(route('tickets.comments.store', $firstRespCommentTicket), [
                'body' => 'Requester follow-up before staff response',
            ])
            ->assertRedirect();
        $this->assertNull($firstRespCommentTicket->fresh()->first_responded_at);

        $this->actingAs($leadEngineer)
            ->post(route('tickets.comments.store', $firstRespCommentTicket), [
                'body' => 'Staff internal note sets first_responded_at',
                'is_internal' => true,
            ])
            ->assertRedirect();
        $this->assertNotNull($firstRespCommentTicket->fresh()->first_responded_at);

        $staffSelfTicket = Ticket::create([
            'ticket_number' => 'REQ-2026-3007',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $superadmin->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Staff Self Requester Comment Ticket',
            'description' => 'Staff requester commenting must not stamp first_responded_at.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($superadmin)
            ->post(route('tickets.comments.store', $staffSelfTicket), [
                'body' => 'Superadmin requester adding own note',
            ])
            ->assertRedirect();
        $this->assertNull($staffSelfTicket->fresh()->first_responded_at);

        $this->actingAs($leadEngineer)
            ->post(route('tickets.comments.store', $staffSelfTicket), [
                'body' => 'Lead engineer responding to superadmin request',
            ])
            ->assertRedirect();
        $this->assertNotNull($staffSelfTicket->fresh()->first_responded_at);

        // TC-17: Audit log ordering & TicketService::recordActivity truncation:
        // activities load in created_at DESC, id DESC order; old_value and new_value truncate to 250 chars
        // and description truncates at 250 chars with '...'.
        $auditTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-3008',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Audit Ordering & Truncation Ticket',
            'description' => 'Tests activity sorting and string limits.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        /** @var TicketService $ticketService */
        $ticketService = app(TicketService::class);

        $sameTimestamp = Carbon::parse('2026-11-19 04:00:00');
        Carbon::setTestNow($sameTimestamp);

        $actA = $ticketService->recordActivity(
            $auditTicket,
            $superadmin,
            'review_updated',
            str_repeat('x', 300),
            str_repeat('o', 300),
            str_repeat('n', 300)
        );

        $actB = $ticketService->recordActivity(
            $auditTicket,
            $leadEngineer,
            'resolution_updated',
            'Second entry at same timestamp'
        );

        Carbon::setTestNow($sameTimestamp->copy()->addMinutes(5));
        $actC = $ticketService->recordActivity(
            $auditTicket,
            $leadEngineer,
            'status_changed',
            'Newest timestamp entry',
            Ticket::STATUS_OPEN,
            Ticket::STATUS_IN_PROGRESS
        );
        Carbon::setTestNow();

        $this->assertSame(str_repeat('o', 250), $actA->old_value);
        $this->assertSame(str_repeat('n', 250), $actA->new_value);
        $this->assertSame(str_repeat('x', 250).'...', $actA->description);

        $this->actingAs($leadEngineer)
            ->get(route('tickets.show', $auditTicket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ticket.activities', 3)
                ->where('ticket.activities.0.id', $actC->id)
                ->where('ticket.activities.1.id', $actB->id)
                ->where('ticket.activities.2.id', $actA->id)
            );

        // TC-18: Audit log action type coverage: verify all 13 action_type values are recorded accurately.
        $system = InternalSystem::create([
            'name' => 'Phase3 Audit System',
            'category' => 'Core',
            'is_active' => true,
        ]);

        $this->actingAs($requester)
            ->post(route('tickets.store'), [
                'type' => Ticket::TYPE_ISSUE,
                'internal_system_id' => $system->id,
                'title' => 'All 13 Audit Action Types Ticket',
                'description' => 'Lifecycle test for all 13 activity types.',
                'priority' => Ticket::PRIORITY_LOW,
                'environment' => 'production',
                'impact_scope' => 'department',
                'attachments' => [UploadedFile::fake()->create('lifecycle.log', 16, 'text/plain')],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $lifecycleTicket = Ticket::query()
            ->where('title', 'All 13 Audit Action Types Ticket')
            ->firstOrFail();

        // lead_assigned + collaborators_updated
        $this->actingAs($superadmin)
            ->patch(route('tickets.assignment.update', $lifecycleTicket), [
                'lead_engineer_id' => $leadEngineer->id,
                'collaborator_ids' => [$collaborator->id],
            ])
            ->assertRedirect();

        // status_changed + priority_changed + due_date_updated + review_updated + resolution_updated
        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $lifecycleTicket), [
                'status' => Ticket::STATUS_RESOLVED,
                'priority' => Ticket::PRIORITY_HIGH,
                'target_due_date' => '2026-12-15',
                'review_decision_notes' => 'Approved for deployment',
                'resolution_summary' => 'Applied patch v3',
            ])
            ->assertRedirect();

        // subtask_added + subtask_toggled + subtask_removed
        $this->actingAs($leadEngineer)
            ->post(route('tickets.subtasks.store', $lifecycleTicket), [
                'title' => 'Verify patch',
                'assigned_engineer_id' => $collaborator->id,
            ])
            ->assertRedirect();

        $lifecycleSubtask = TicketSubtask::query()
            ->where('ticket_id', $lifecycleTicket->id)
            ->firstOrFail();

        $this->actingAs($collaborator)
            ->patch(route('tickets.subtasks.toggle', [$lifecycleTicket, $lifecycleSubtask]))
            ->assertRedirect();

        $this->actingAs($leadEngineer)
            ->delete(route('tickets.subtasks.destroy', [$lifecycleTicket, $lifecycleSubtask]))
            ->assertRedirect();

        // comment_added
        $this->actingAs($leadEngineer)
            ->post(route('tickets.comments.store', $lifecycleTicket), [
                'body' => 'Public update for audit log verification.',
            ])
            ->assertRedirect();

        // reopened
        $this->actingAs($requester)
            ->patch(route('tickets.workflow.update', $lifecycleTicket), [
                'action' => 'reopen',
                'reopen_reason' => 'Reopening to verify reopened audit event.',
            ])
            ->assertRedirect();

        $recordedActionTypes = TicketActivity::query()
            ->where('ticket_id', $lifecycleTicket->id)
            ->pluck('action_type')
            ->unique()
            ->values()
            ->all();

        $expectedActionTypes = [
            'ticket_created',
            'lead_assigned',
            'collaborators_updated',
            'status_changed',
            'priority_changed',
            'due_date_updated',
            'review_updated',
            'resolution_updated',
            'subtask_added',
            'subtask_toggled',
            'subtask_removed',
            'comment_added',
            'reopened',
        ];

        foreach ($expectedActionTypes as $expectedActionType) {
            $this->assertContains($expectedActionType, $recordedActionTypes);
        }

        // TC-19: UI state & accessibility in TicketDetailWorkspace.vue
        $workspaceVue = (string) file_get_contents(resource_path('js/Components/TicketDetailWorkspace.vue'));
        $showVue = (string) file_get_contents(resource_path('js/Pages/Tickets/Show.vue'));

        foreach ([
            'for="subtask-title"',
            'id="subtask-title"',
            'maxlength="255"',
            'for="subtask-assigned-engineer"',
            'id="subtask-assigned-engineer"',
            'id="comment-body"',
            'maxlength="10000"',
            'for="comment-attachments"',
            'id="comment-attachments"',
            'for="comment-is-internal"',
            'id="comment-is-internal"',
            'role="tablist"',
            'role="tab"',
            ':aria-selected="activeActivityTab === \'comments\'"',
            ':aria-selected="activeActivityTab === \'audit\'"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $workspaceVue);
        }

        // TC-20: Security & escaping: XSS payloads in subtask titles, comment bodies, and activity descriptions
        // are stored raw and rendered without v-html in TicketDetailWorkspace.vue.
        $xssSubtaskTitle = '<script>window.__p3Xss=1</script>';
        $xssCommentBody = '<img src=x onerror=alert(3)>';

        $xssTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-3099',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Phase 3 XSS Escaping Ticket',
            'description' => 'Verifies raw storage and safe Vue text interpolation.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        $this->actingAs($leadEngineer)
            ->post(route('tickets.subtasks.store', $xssTicket), [
                'title' => $xssSubtaskTitle,
            ])
            ->assertRedirect();

        $this->actingAs($requester)
            ->post(route('tickets.comments.store', $xssTicket), [
                'body' => $xssCommentBody,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ticket_subtasks', [
            'ticket_id' => $xssTicket->id,
            'title' => $xssSubtaskTitle,
        ]);
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $xssTicket->id,
            'body' => $xssCommentBody,
        ]);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $xssTicket->id,
            'action_type' => 'subtask_added',
            'description' => "Added subtask: {$xssSubtaskTitle}",
        ]);

        $this->actingAs($leadEngineer)
            ->get(route('tickets.show', $xssTicket))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ticket.subtasks.0.title', $xssSubtaskTitle)
                ->where('ticket.comments.0.body', $xssCommentBody)
                ->where('ticket.activities.1.description', "Added subtask: {$xssSubtaskTitle}")
            );

        $this->assertStringNotContainsString('v-html', $workspaceVue);
        $this->assertStringNotContainsString('v-html', $showVue);
    }

    public function test_phase4_notifications_in_app_and_email_matrix(): void
    {
        Storage::fake((string) config('filesystems.attachments_disk'));

        $superAdmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
            'email' => 'p4-admin@calltek.local',
        ]);
        $secondSuperAdmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
            'email' => 'p4-admin2@calltek.local',
        ]);
        $inactiveSuperAdmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => false,
            'email' => 'p4-admin-inactive@calltek.local',
        ]);
        $leadEngineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
            'email' => 'p4-lead@calltek.local',
        ]);
        $collabEngineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
            'email' => 'p4-collab@calltek.local',
        ]);
        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email' => 'p4-requester@calltek.local',
        ]);
        $otherUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email' => 'p4-other@calltek.local',
        ]);

        $system = InternalSystem::create([
            'name' => 'CallTek Notification Gateway',
            'description' => 'Phase 4 test system',
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-4001',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $system->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Phase 4 Notification Ticket',
            'description' => 'Ticket used for notification matrix verification.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        // TC-01 & TC-02: GET /notifications pagination, scoping, ordering & HandleInertiaRequests shared props
        $baseTime = Carbon::parse('2026-11-19 08:00:00');
        $requesterNotifications = [];
        for ($i = 1; $i <= 25; $i++) {
            // Make notifications 24 and 25 share the exact same created_at to test id DESC tie-breaker
            $timestamp = $i >= 24 ? $baseTime->copy()->addMinutes(25) : $baseTime->copy()->addMinutes($i);
            $notif = UserNotification::create([
                'user_id' => $requester->id,
                'ticket_id' => $ticket->id,
                'title' => "Notification {$i}",
                'message' => "Message body {$i}",
                'action_url' => "/tickets/{$ticket->id}",
                'is_read' => $i <= 13, // 12 unread (14..25), 13 read (1..13)
                'read_at' => $i <= 13 ? $timestamp->copy()->addSeconds(30) : null,
            ]);
            UserNotification::whereKey($notif->id)->update([
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            $requesterNotifications[$i] = $notif->fresh();
        }

        for ($j = 1; $j <= 3; $j++) {
            UserNotification::create([
                'user_id' => $otherUser->id,
                'ticket_id' => $ticket->id,
                'title' => "Other User Notification {$j}",
                'message' => "Private message {$j}",
                'action_url' => "/tickets/{$ticket->id}",
                'is_read' => false,
            ]);
        }

        // Page 1: 20 items ordered by created_at DESC, id DESC
        $this->actingAs($requester)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications/Index')
                ->has('notifications.data', 20)
                ->where('notifications.total', 25)
                ->where('notifications.per_page', 20)
                ->where('notifications.data.0.id', $requesterNotifications[25]->id)
                ->where('notifications.data.1.id', $requesterNotifications[24]->id)
                ->where('notifications.data.19.id', $requesterNotifications[6]->id)
                ->where('notifications.data.0.user_id', $requester->id)
                ->where('notifications.data.0.ticket.id', $ticket->id)
                ->where('notifications.data.0.ticket.ticket_number', $ticket->ticket_number)
                ->where('notifications.data.0.ticket.title', $ticket->title)
            );

        // Page 2: remaining 5 items
        $this->actingAs($requester)
            ->get(route('notifications.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('notifications.data', 5)
                ->where('notifications.data.0.id', $requesterNotifications[5]->id)
                ->where('notifications.data.4.id', $requesterNotifications[1]->id)
            );

        // TC-02: HandleInertiaRequests shares unread_notifications_count (12) and up to 8 recent_notifications
        $this->actingAs($requester)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.unread_notifications_count', 12)
                ->has('auth.recent_notifications', 8)
                ->where('auth.recent_notifications.0.id', $requesterNotifications[25]->id)
                ->where('auth.recent_notifications.1.id', $requesterNotifications[24]->id)
                ->where('auth.recent_notifications.7.id', $requesterNotifications[18]->id)
            );

        // TC-03: PATCH /notifications/{notification}/read marks read, stamps read_at, decrements unread count, flashes
        $unreadTarget = $requesterNotifications[25];
        $this->actingAs($requester)
            ->from(route('notifications.index'))
            ->patch(route('notifications.read', $unreadTarget))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('success', 'Notification marked as read.');

        $unreadTarget->refresh();
        $this->assertTrue($unreadTarget->is_read);
        $this->assertNotNull($unreadTarget->read_at);

        $this->actingAs($requester)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.unread_notifications_count', 11)
            );

        // TC-04: PATCH /notifications/{notification}/read?redirect=1 with internal & same-origin action_url
        $internalRedirectNotif = $requesterNotifications[24];
        $this->actingAs($requester)
            ->patch(route('notifications.read', ['notification' => $internalRedirectNotif->id, 'redirect' => 1]))
            ->assertRedirect("/tickets/{$ticket->id}");
        $this->assertTrue($internalRedirectNotif->fresh()->is_read);

        $sameOriginUrl = rtrim((string) config('app.url'), '/')."/tickets/{$ticket->id}";
        $sameOriginNotif = $requesterNotifications[23];
        $sameOriginNotif->update(['action_url' => $sameOriginUrl]);
        $this->actingAs($requester)
            ->patch(route('notifications.read', ['notification' => $sameOriginNotif->id, 'redirect' => 1]))
            ->assertRedirect($sameOriginUrl);
        $this->assertTrue($sameOriginNotif->fresh()->is_read);

        // TC-05: When action_url is null and ticket_id is present, redirect=1 goes to tickets.show
        $nullActionTicketNotif = $requesterNotifications[22];
        $nullActionTicketNotif->update(['action_url' => null]);
        $this->actingAs($requester)
            ->patch(route('notifications.read', ['notification' => $nullActionTicketNotif->id, 'redirect' => 1]))
            ->assertRedirect(route('tickets.show', $ticket->id));
        $this->assertTrue($nullActionTicketNotif->fresh()->is_read);

        // TC-06: Open-redirect guard blocks https://evil.example.com and //evil.example.com
        $externalHttpsNotif = $requesterNotifications[21];
        $externalHttpsNotif->update(['action_url' => 'https://evil.example.com/phish']);
        $this->actingAs($requester)
            ->patch(route('notifications.read', ['notification' => $externalHttpsNotif->id, 'redirect' => 1]))
            ->assertRedirect(route('tickets.show', $ticket->id));
        $this->assertTrue($externalHttpsNotif->fresh()->is_read);

        $protocolRelativeNoTicketNotif = UserNotification::create([
            'user_id' => $requester->id,
            'ticket_id' => null,
            'title' => 'Protocol-relative external URL',
            'message' => 'Should fall back to back().',
            'action_url' => '//evil.example.com/steal',
            'is_read' => false,
        ]);
        $this->actingAs($requester)
            ->from(route('notifications.index'))
            ->patch(route('notifications.read', ['notification' => $protocolRelativeNoTicketNotif->id, 'redirect' => 1]))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('success', 'Notification marked as read.');
        $this->assertTrue($protocolRelativeNoTicketNotif->fresh()->is_read);

        // TC-07: Idempotency on already-read notification preserves original read_at timestamp
        $alreadyReadNotif = $requesterNotifications[1];
        $originalReadAt = $alreadyReadNotif->read_at->copy();
        Carbon::setTestNow($originalReadAt->copy()->addHours(2));
        try {
            $this->actingAs($requester)
                ->patch(route('notifications.read', $alreadyReadNotif))
                ->assertRedirect()
                ->assertSessionHas('success', 'Notification marked as read.');
        } finally {
            Carbon::setTestNow();
        }
        $this->assertTrue($originalReadAt->equalTo($alreadyReadNotif->fresh()->read_at));

        // TC-08: POST /notifications/read-all marks current user's unread notifications without altering already-read read_at or other users
        $otherUnreadNotif = UserNotification::where('user_id', $otherUser->id)->firstOrFail();
        Carbon::setTestNow(Carbon::parse('2026-11-19 12:30:00'));
        try {
            $this->actingAs($requester)
                ->from(route('notifications.index'))
                ->post(route('notifications.readAll'))
                ->assertRedirect(route('notifications.index'))
                ->assertSessionHas('success', 'All notifications marked as read.');
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(0, UserNotification::where('user_id', $requester->id)->where('is_read', false)->count());
        $this->assertTrue($originalReadAt->equalTo($alreadyReadNotif->fresh()->read_at));
        $this->assertFalse($otherUnreadNotif->fresh()->is_read);
        $this->assertNull($otherUnreadNotif->fresh()->read_at);

        // TC-09: POST /notifications/read-all when 0 unread notifications exist still succeeds and flashes
        $this->actingAs($requester)
            ->from(route('notifications.index'))
            ->post(route('notifications.readAll'))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('success', 'All notifications marked as read.');

        // TC-10: Cross-user RBAC isolation returns 403 Forbidden and leaves notification unread
        $this->actingAs($requester)
            ->patch(route('notifications.read', $otherUnreadNotif))
            ->assertForbidden();
        $this->assertFalse($otherUnreadNotif->fresh()->is_read);

        // TC-11: Non-existent notification returns 404; guest requests redirect to /login
        $this->actingAs($requester)
            ->patch('/notifications/999999/read')
            ->assertNotFound();

        Auth::logout();
        Auth::forgetGuards();
        $this->flushSession();
        $this->assertGuest();

        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->patch(route('notifications.read', $otherUnreadNotif))->assertRedirect(route('login'));
        $this->post(route('notifications.readAll'))->assertRedirect(route('login'));

        // TC-12: Deactivated user mid-session guard logs out and redirects to /login
        $deactivatedUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email' => 'p4-deactivated@calltek.local',
        ]);
        $deactivatedUser->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->actingAs($deactivatedUser)
            ->get(route('notifications.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
        $this->assertGuest();

        Auth::forgetGuards();
        $this->actingAs($deactivatedUser)
            ->post(route('notifications.readAll'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
        $this->assertGuest();

        // TC-13: Email delivery via NotificationService::notifyUser
        $notificationService = app(NotificationService::class);
        $directNotif = $notificationService->notifyUser(
            $requester,
            $ticket,
            'Direct Notification Subject',
            'Direct notification message body.',
            "/tickets/{$ticket->id}"
        );

        $this->assertDatabaseHas('user_notifications', [
            'id' => $directNotif->id,
            'user_id' => $requester->id,
            'ticket_id' => $ticket->id,
            'title' => 'Direct Notification Subject',
            'is_read' => false,
        ]);

        Mail::assertQueued(TicketNotificationMail::class, function (TicketNotificationMail $mail) use ($requester, $directNotif): bool {
            return $mail->hasTo($requester->email)
                && $mail->envelope()->subject === $directNotif->title;
        });

        // TC-14: NotificationService::notifyUsers skips null, skips inactive, excludes actor, deduplicates
        Mail::fake();
        $notificationService->notifyUsers(
            [null, $inactiveSuperAdmin, $requester, $leadEngineer, $leadEngineer, $collabEngineer],
            $ticket,
            'Deduplicated Broadcast',
            'Should reach lead and collab once each.',
            "/tickets/{$ticket->id}",
            $requester->id
        );

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $inactiveSuperAdmin->id,
            'title' => 'Deduplicated Broadcast',
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $requester->id,
            'title' => 'Deduplicated Broadcast',
        ]);
        $this->assertSame(1, UserNotification::where('user_id', $leadEngineer->id)->where('title', 'Deduplicated Broadcast')->count());
        $this->assertSame(1, UserNotification::where('user_id', $collabEngineer->id)->where('title', 'Deduplicated Broadcast')->count());
        Mail::assertQueued(TicketNotificationMail::class, 2);

        // TC-15: Email transport failure resilience logs warning and still persists UserNotification
        Log::spy();
        $previousMailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')
            ->once()
            ->with($requester->email)
            ->andThrow(new \RuntimeException('SMTP failure'));

        try {
            $resilientNotif = $notificationService->notifyUser(
                $requester,
                $ticket,
                'Resilient Notification Title',
                'Persisted even if SMTP fails.',
                "/tickets/{$ticket->id}"
            );
        } finally {
            Mail::swap($previousMailFake);
            Mail::fake();
        }

        $this->assertInstanceOf(UserNotification::class, $resilientNotif);
        $this->assertDatabaseHas('user_notifications', [
            'id' => $resilientNotif->id,
            'user_id' => $requester->id,
            'title' => 'Resilient Notification Title',
        ]);
        Log::shouldHaveReceived('warning')->once();

        // TC-16: TicketNotificationMail envelope & Blade view rendering with and without ticket/actionUrl
        $mailableWithTicket = new TicketNotificationMail($directNotif, $ticket);
        $this->assertSame($directNotif->title, $mailableWithTicket->envelope()->subject);
        $renderedWithTicket = $mailableWithTicket->render();
        $this->assertStringContainsString($directNotif->title, $renderedWithTicket);
        $this->assertStringContainsString($ticket->ticket_number, $renderedWithTicket);
        $this->assertStringContainsString($ticket->title, $renderedWithTicket);
        $this->assertStringContainsString($directNotif->message, $renderedWithTicket);
        $this->assertStringContainsString(url("/tickets/{$ticket->id}"), $renderedWithTicket);

        $standaloneNotif = UserNotification::create([
            'user_id' => $requester->id,
            'ticket_id' => null,
            'title' => 'Standalone Notice',
            'message' => 'No ticket or link attached.',
            'action_url' => null,
            'is_read' => false,
        ]);
        $mailableWithoutTicket = new TicketNotificationMail($standaloneNotif, null);
        $renderedWithoutTicket = $mailableWithoutTicket->render();
        $this->assertStringContainsString('Standalone Notice', $renderedWithoutTicket);
        $this->assertStringContainsString('No ticket or link attached.', $renderedWithoutTicket);
        $this->assertStringNotContainsString('Ticket Reference:', $renderedWithoutTicket);
        $this->assertStringNotContainsString('View Ticket Details', $renderedWithoutTicket);
        $this->assertStringNotContainsString('Direct link:', $renderedWithoutTicket);

        // TC-17: Lifecycle triggers on ticket creation & assignment
        Mail::fake();
        $this->actingAs($superAdmin)
            ->post(route('tickets.store'), [
                'type' => Ticket::TYPE_ISSUE,
                'internal_system_id' => $system->id,
                'title' => 'Lifecycle Trigger Issue',
                'description' => 'Created by superAdmin to test creation and assignment notifications.',
                'priority' => Ticket::PRIORITY_HIGH,
                'environment' => 'production',
                'impact_scope' => 'department',
                'attachments' => [UploadedFile::fake()->create('trace.log', 12)],
            ])
            ->assertRedirect();

        $createdTicket = Ticket::where('title', 'Lifecycle Trigger Issue')->firstOrFail();

        // Active superadmins except creator ($superAdmin) are notified
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $secondSuperAdmin->id,
            'ticket_id' => $createdTicket->id,
            'title' => "New Ticket: {$createdTicket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $superAdmin->id,
            'ticket_id' => $createdTicket->id,
            'title' => "New Ticket: {$createdTicket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $inactiveSuperAdmin->id,
            'ticket_id' => $createdTicket->id,
        ]);

        // Also test requester-submitted ticket creation notifies both active superadmins
        $this->actingAs($requester)
            ->post(route('tickets.store'), [
                'type' => Ticket::TYPE_ISSUE,
                'internal_system_id' => $system->id,
                'title' => 'Requester Lifecycle Issue',
                'description' => 'Created by requester to test assignment and workflow notifications.',
                'priority' => Ticket::PRIORITY_MEDIUM,
                'environment' => 'staging',
                'impact_scope' => 'single_user',
                'attachments' => [UploadedFile::fake()->create('screenshot.png', 16)],
            ])
            ->assertRedirect();

        $lifecycleTicket = Ticket::where('title', 'Requester Lifecycle Issue')->firstOrFail();
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $superAdmin->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "New Ticket: {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $secondSuperAdmin->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "New Ticket: {$lifecycleTicket->ticket_number}",
        ]);

        // Assignment by superAdmin notifies new lead, added collaborators, and requester (excluding actor)
        $this->actingAs($superAdmin)
            ->patch(route('tickets.assignment.update', $lifecycleTicket), [
                'lead_engineer_id' => $leadEngineer->id,
                'collaborator_ids' => [$collabEngineer->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $leadEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Assigned as Lead Engineer: {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $collabEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Added as Collaborator: {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Assignment Updated: {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $superAdmin->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Assignment Updated: {$lifecycleTicket->ticket_number}",
        ]);

        // TC-18: Lifecycle triggers on workflow, reopen/close/cancel & comments
        // Staff workflow update by leadEngineer notifies requester & collaborator, excludes leadEngineer
        $this->actingAs($leadEngineer)
            ->patch(route('tickets.workflow.update', $lifecycleTicket), [
                'status' => Ticket::STATUS_RESOLVED,
                'priority' => Ticket::PRIORITY_CRITICAL,
                'resolution_summary' => 'Fixed root cause.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Ticket Updated: {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $collabEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Ticket Updated: {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $leadEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Ticket Updated: {$lifecycleTicket->ticket_number}",
        ]);

        // Requester confirm_close notifies assigned staff
        $this->actingAs($requester)
            ->patch(route('tickets.workflow.update', $lifecycleTicket), [
                'action' => 'confirm_close',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $leadEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Ticket Closed: {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $collabEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Ticket Closed: {$lifecycleTicket->ticket_number}",
        ]);

        // Requester reopen notifies assigned staff
        $this->actingAs($requester)
            ->patch(route('tickets.workflow.update', $lifecycleTicket), [
                'action' => 'reopen',
                'reopen_reason' => 'Issue recurred after deployment.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $leadEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Ticket Reopened: {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $collabEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Ticket Reopened: {$lifecycleTicket->ticket_number}",
        ]);

        // Requester cancel on an open ticket notifies assigned staff
        $cancellableTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-4099',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $system->id,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Cancellable Lifecycle Issue',
            'description' => 'Used to test cancel notification.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);
        $cancellableTicket->collaborators()->attach($collabEngineer->id, ['added_by_id' => $superAdmin->id]);

        $this->actingAs($requester)
            ->patch(route('tickets.workflow.update', $cancellableTicket), [
                'action' => 'cancel',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $leadEngineer->id,
            'ticket_id' => $cancellableTicket->id,
            'title' => "Ticket Cancelled: {$cancellableTicket->ticket_number}",
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $collabEngineer->id,
            'ticket_id' => $cancellableTicket->id,
            'title' => "Ticket Cancelled: {$cancellableTicket->ticket_number}",
        ]);

        // Public comment by collabEngineer notifies leadEngineer and requester, excludes collabEngineer
        $this->actingAs($collabEngineer)
            ->post(route('tickets.comments.store', $lifecycleTicket), [
                'body' => 'Public update for all stakeholders.',
                'is_internal' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $leadEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "New Comment on {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "New Comment on {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $collabEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "New Comment on {$lifecycleTicket->ticket_number}",
        ]);

        // Internal note by leadEngineer notifies collabEngineer only (not requester, not author)
        $this->actingAs($leadEngineer)
            ->post(route('tickets.comments.store', $lifecycleTicket), [
                'body' => 'Internal staff-only diagnostic note.',
                'is_internal' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $collabEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Internal Note on {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Internal Note on {$lifecycleTicket->ticket_number}",
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $leadEngineer->id,
            'ticket_id' => $lifecycleTicket->id,
            'title' => "Internal Note on {$lifecycleTicket->ticket_number}",
        ]);

        // TC-19: UI state & accessibility checks in Notifications/Index.vue and AppLayout.vue
        $notificationsIndexVue = (string) file_get_contents(resource_path('js/Pages/Notifications/Index.vue'));
        $appLayoutVue = (string) file_get_contents(resource_path('js/Layouts/AppLayout.vue'));

        $this->assertStringContainsString('busyNotificationId', $notificationsIndexVue);
        $this->assertStringContainsString('markAllProcessing', $notificationsIndexVue);
        $this->assertStringContainsString('99+', $appLayoutVue);
        $this->assertStringContainsString(':aria-expanded="notificationsOpen"', $appLayoutVue);
        $this->assertStringContainsString("event.key === 'Escape'", $appLayoutVue);

        // TC-20: Security & escaping in in-app & email templates
        $xssTitle = '<script>window.__notifXss=1</script>';
        $xssMessage = '<img src=x onerror=alert(4)>';

        $xssNotif = UserNotification::create([
            'user_id' => $requester->id,
            'ticket_id' => $ticket->id,
            'title' => $xssTitle,
            'message' => $xssMessage,
            'action_url' => "/tickets/{$ticket->id}",
            'is_read' => false,
        ]);

        $renderedXssHtml = (new TicketNotificationMail($xssNotif, $ticket))->render();
        $this->assertStringNotContainsString($xssTitle, $renderedXssHtml);
        $this->assertStringNotContainsString('<img src=x', $renderedXssHtml);
        $this->assertStringContainsString('&lt;script&gt;window.__notifXss=1&lt;/script&gt;', $renderedXssHtml);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(4)&gt;', $renderedXssHtml);

        $bladeTemplate = (string) file_get_contents(resource_path('views/emails/ticket-notification.blade.php'));
        $this->assertStringNotContainsString('{!!', $bladeTemplate);
        $this->assertStringNotContainsString('v-html', $notificationsIndexVue);
        $this->assertStringNotContainsString('v-html', $appLayoutVue);
    }

    public function test_phase5_analytics_reports_and_csv_exports_matrix(): void
    {
        $superadmin = User::factory()->create([
            'name' => 'Super Admin',
            'role' => User::ROLE_SUPERADMIN,
            'department' => 'Executive',
        ]);
        $engAlpha = User::factory()->create([
            'name' => 'Alpha Engineer',
            'role' => User::ROLE_ENGINEER,
            'department' => 'Platform Engineering',
        ]);
        $engBeta = User::factory()->create([
            'name' => 'Beta Engineer',
            'role' => User::ROLE_ENGINEER,
            'department' => 'Core Systems',
        ]);
        $engIdle = User::factory()->create([
            'name' => 'Idle Engineer',
            'role' => User::ROLE_ENGINEER,
            'department' => 'QA',
        ]);
        $reqFinance = User::factory()->create([
            'name' => 'Finance Requester',
            'role' => User::ROLE_USER,
            'department' => 'Finance',
        ]);
        $reqOps = User::factory()->create([
            'name' => 'Ops Requester',
            'role' => User::ROLE_USER,
            'department' => 'Operations',
        ]);
        $reqNoDept = User::factory()->create([
            'name' => 'No Dept Requester',
            'role' => User::ROLE_USER,
            'department' => null,
        ]);
        $reqEmptyDept = User::factory()->create([
            'name' => 'Empty Dept Requester',
            'role' => User::ROLE_USER,
            'department' => '',
        ]);

        // Systems: lowercase 'alpha gateway' must sort before 'Zeta Core'
        $sysZeta = InternalSystem::create(['name' => 'Zeta Core', 'is_active' => true]);
        $sysAlpha = InternalSystem::create(['name' => 'alpha gateway', 'is_active' => true]);
        $sysEmpty = InternalSystem::create(['name' => 'Empty System', 'is_active' => true]);

        $baseCreated = Carbon::create(2026, 6, 15, 10, 0, 0);

        $makeTicket = function (array $attrs) use ($baseCreated): Ticket {
            $createdAt = $attrs['created_at'] ?? $baseCreated->copy();
            unset($attrs['created_at']);

            $ticket = Ticket::create($attrs);
            DB::table('tickets')->where('id', $ticket->id)->update([
                'created_at' => $createdAt->toDateTimeString(),
                'updated_at' => $createdAt->toDateTimeString(),
            ]);

            return $ticket->fresh();
        };

        // 1. Open, Critical, Alpha, overdue (past due date), Finance, led by Alpha, collab Beta
        // Turnaround: first_responded +90m (1.5h), resolved null
        $t1 = $makeTicket([
            'ticket_number' => 'ISS-2026-5001',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $reqFinance->id,
            'lead_engineer_id' => $engAlpha->id,
            'title' => 'Alpha Gateway Timeout',
            'description' => 'Timeout on gateway.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_OPEN,
            'target_due_date' => today()->subDays(2),
            'first_responded_at' => $baseCreated->copy()->addMinutes(90),
            'created_at' => $baseCreated->copy(),
        ]);
        $t1->collaborators()->attach($engBeta->id, ['added_by_id' => $superadmin->id]);

        // 2. Under review, High, Alpha, due TODAY (NOT overdue), Finance, led by Alpha
        $t2 = $makeTicket([
            'ticket_number' => 'REQ-2026-5002',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $reqFinance->id,
            'lead_engineer_id' => $engAlpha->id,
            'title' => 'Add Webhook Retry',
            'description' => 'Need retries.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_UNDER_REVIEW,
            'target_due_date' => today(),
            'created_at' => $baseCreated->copy(),
        ]);

        // 3. In progress, Medium, Zeta, future due date (NOT overdue), Finance, led by Beta
        $t3 = $makeTicket([
            'ticket_number' => 'ISS-2026-5003',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $sysZeta->id,
            'requester_id' => $reqFinance->id,
            'lead_engineer_id' => $engBeta->id,
            'title' => 'Zeta Sync Delay',
            'description' => 'Delay in sync.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'target_due_date' => today()->addDays(3),
            'created_at' => $baseCreated->copy(),
        ]);

        // 4. Waiting on user, Low, Zeta, null due date (NOT overdue), Ops, led by Beta
        $t4 = $makeTicket([
            'ticket_number' => 'REQ-2026-5004',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $sysZeta->id,
            'requester_id' => $reqOps->id,
            'lead_engineer_id' => $engBeta->id,
            'title' => 'Zeta Export Column',
            'description' => 'Add column.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_WAITING_ON_USER,
            'target_due_date' => null,
            'created_at' => $baseCreated->copy(),
        ]);

        // 5. Resolved, Critical, Alpha, past due date (NOT overdue because terminal), Ops, led by Alpha
        // Negative clock skew on first_responded_at (-30m -> 0) and resolved_at (-15m -> 0)
        $t5 = $makeTicket([
            'ticket_number' => 'ISS-2026-5005',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $reqOps->id,
            'lead_engineer_id' => $engAlpha->id,
            'title' => 'Skewed Clock Issue',
            'description' => 'Tests negative skew clamp.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_RESOLVED,
            'target_due_date' => today()->subDays(5),
            'first_responded_at' => $baseCreated->copy()->subMinutes(30),
            'resolved_at' => $baseCreated->copy()->subMinutes(15),
            'created_at' => $baseCreated->copy(),
        ]);

        // 6. Closed, High, Unassigned system (null), past due date (NOT overdue because terminal), NoDept, led by Alpha
        // Turnaround: first_responded +120m (2.0h), resolved +180m (3.0h), closed +240m
        $t6 = $makeTicket([
            'ticket_number' => 'ISS-2026-5006',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => null,
            'requester_id' => $reqNoDept->id,
            'lead_engineer_id' => $engAlpha->id,
            'title' => 'General VPN Access',
            'description' => 'VPN issue.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_CLOSED,
            'target_due_date' => today()->subDay(),
            'first_responded_at' => $baseCreated->copy()->addMinutes(120),
            'resolved_at' => $baseCreated->copy()->addMinutes(180),
            'closed_at' => $baseCreated->copy()->addMinutes(240),
            'created_at' => $baseCreated->copy(),
        ]);

        // 7. Rejected, Medium, Zeta, past due date (NOT overdue), EmptyDept, no lead engineer (null)
        $t7 = $makeTicket([
            'ticket_number' => 'REQ-2026-5007',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $sysZeta->id,
            'requester_id' => $reqEmptyDept->id,
            'lead_engineer_id' => null,
            'title' => 'Rejected Feature',
            'description' => 'Out of scope.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_REJECTED,
            'target_due_date' => today()->subDays(4),
            'created_at' => $baseCreated->copy(),
        ]);

        // 8. Cancelled, Low, Unassigned system (null), past due date (NOT overdue), Finance, led by Alpha
        // Turnaround: resolved +240m (4.0h) on t8? Let's put resolved_at +240m on t8? Cancelled usually has no resolved_at,
        // so let's keep t8 without resolved_at and add resolved_at +240m on t9 (a resolved issue).
        $t8 = $makeTicket([
            'ticket_number' => 'ISS-2026-5008',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => null,
            'requester_id' => $reqFinance->id,
            'lead_engineer_id' => $engAlpha->id,
            'title' => 'Cancelled Duplicate',
            'description' => 'Duplicate ticket.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_CANCELLED,
            'target_due_date' => today()->subDays(3),
            'created_at' => $baseCreated->copy(),
        ]);

        // 9. Open, Low, Alpha, overdue (past due date), Finance, led by Beta, collab Alpha
        // Wait: to test avg_resolution_hours with 3 data points: t5 (0h), t6 (3.0h), t9 (4.0h) -> (0+3+4)/3 = 2.333 -> 2.3
        // Let's make t9 open or resolved? If t9 is resolved, let's verify active/resolved counts.
        // Instead, let's make t9 status=resolved with resolved_at=+240m, and overdue is checked on t1 (1 overdue active ticket).
        $t9 = $makeTicket([
            'ticket_number' => 'REQ-2026-5009',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $reqFinance->id,
            'lead_engineer_id' => $engBeta->id,
            'title' => 'Resolved Beta Request',
            'description' => 'Completed request.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_RESOLVED,
            'target_due_date' => today()->subDays(1),
            'resolved_at' => $baseCreated->copy()->addMinutes(240),
            'created_at' => $baseCreated->copy(),
        ]);
        $t9->collaborators()->attach($engAlpha->id, ['added_by_id' => $superadmin->id]);

        // Subtasks on t1 (Alpha: 2 completed, 1 incomplete) and t3 (Beta: 1 completed)
        TicketSubtask::create([
            'ticket_id' => $t1->id,
            'title' => 'Check gateway logs',
            'assigned_engineer_id' => $engAlpha->id,
            'is_completed' => true,
            'completed_at' => now(),
            'created_by_id' => $engAlpha->id,
            'sort_order' => 1,
        ]);
        TicketSubtask::create([
            'ticket_id' => $t1->id,
            'title' => 'Restart gateway worker',
            'assigned_engineer_id' => $engAlpha->id,
            'is_completed' => true,
            'completed_at' => now(),
            'created_by_id' => $engAlpha->id,
            'sort_order' => 2,
        ]);
        TicketSubtask::create([
            'ticket_id' => $t1->id,
            'title' => 'Verify metrics',
            'assigned_engineer_id' => $engAlpha->id,
            'is_completed' => false,
            'completed_at' => null,
            'created_by_id' => $engAlpha->id,
            'sort_order' => 3,
        ]);
        TicketSubtask::create([
            'ticket_id' => $t3->id,
            'title' => 'Inspect Zeta queue',
            'assigned_engineer_id' => $engBeta->id,
            'is_completed' => true,
            'completed_at' => now(),
            'created_by_id' => $engBeta->id,
            'sort_order' => 1,
        ]);

        // Totals across t1..t9 (9 tickets):
        // Types: issues = t1, t3, t5, t6, t8 (5); requests = t2, t4, t7, t9 (4)
        // Active: t1 (open), t2 (under_review), t3 (in_progress), t4 (waiting_on_user) => 4
        // Resolved/Closed: t5 (resolved), t6 (closed), t9 (resolved) => 3
        // Terminal non-resolved: t7 (rejected), t8 (cancelled) => 2
        // Resolution rate: round((3 / 9) * 100, 1) = 33.3
        // Overdue: only t1 (active + target_due_date < today) => 1
        // First response hours: t1 (1.5), t5 (0.0 clamped), t6 (2.0) => avg = 3.5 / 3 = 1.1666 -> 1.2
        // Resolution hours: t5 (0.0 clamped), t6 (3.0), t9 (4.0) => avg = 7.0 / 3 = 2.333 -> 2.3

        // TC-01, TC-02, TC-04, TC-05, TC-06, TC-07: Superadmin global report assertions
        $this->actingAs($superadmin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                // TC-01: summary counts & overdue
                ->where('summary.total_tickets', 9)
                ->where('summary.issues_count', 5)
                ->where('summary.engineering_requests_count', 4)
                ->where('summary.open_active_count', 4)
                ->where('summary.resolved_closed_count', 3)
                ->where('summary.resolution_rate_percent', 33.3)
                ->where('summary.overdue_count', 1)
                // TC-02: SLA turnaround averages & negative skew clamping
                ->where('summary.avg_first_response_hours', 1.2)
                ->where('summary.avg_resolution_hours', 2.3)
                // TC-04: all 8 status counts & all 4 priority counts
                ->where('by_status', [
                    Ticket::STATUS_PENDING_APPROVAL => 0,
                    Ticket::STATUS_OPEN => 1,
                    Ticket::STATUS_UNDER_REVIEW => 1,
                    Ticket::STATUS_IN_PROGRESS => 1,
                    Ticket::STATUS_WAITING_ON_USER => 1,
                    Ticket::STATUS_RESOLVED => 2,
                    Ticket::STATUS_CLOSED => 1,
                    Ticket::STATUS_REJECTED => 1,
                    Ticket::STATUS_CANCELLED => 1,
                ])
                ->where('by_priority', [
                    Ticket::PRIORITY_CRITICAL => 2,
                    Ticket::PRIORITY_HIGH => 2,
                    Ticket::PRIORITY_MEDIUM => 2,
                    Ticket::PRIORITY_LOW => 3,
                ])
                // TC-05: systems sorted case-insensitively ('alpha gateway', 'Empty System', 'Zeta Core', then 'Unassigned / General')
                ->has('systems', 3)
                ->where('systems.0.name', 'alpha gateway')
                ->where('systems.1.name', 'Empty System')
                ->where('systems.2.name', 'Zeta Core')
                ->has('by_system', 4)
                ->where('by_system.0', [
                    'system_name' => 'alpha gateway',
                    'total' => 4,
                    'issues' => 2,
                    'requests' => 2,
                    'active' => 2,
                    'resolved_closed' => 2,
                    'critical' => 2,
                ])
                ->where('by_system.1', [
                    'system_name' => 'Empty System',
                    'total' => 0,
                    'issues' => 0,
                    'requests' => 0,
                    'active' => 0,
                    'resolved_closed' => 0,
                    'critical' => 0,
                ])
                ->where('by_system.2', [
                    'system_name' => 'Zeta Core',
                    'total' => 3,
                    'issues' => 1,
                    'requests' => 2,
                    'active' => 2,
                    'resolved_closed' => 0,
                    'critical' => 0,
                ])
                ->where('by_system.3', [
                    'system_name' => 'Unassigned / General',
                    'total' => 2,
                    'issues' => 2,
                    'requests' => 0,
                    'active' => 0,
                    'resolved_closed' => 1,
                    'critical' => 0,
                ])
                // TC-06: engineer workload (only role=engineer, sorted by name)
                ->has('by_engineer', 3)
                ->where('by_engineer.0', [
                    'id' => $engAlpha->id,
                    'name' => 'Alpha Engineer',
                    'department' => 'Platform Engineering',
                    'led_total' => 5,
                    'collaborating_total' => 1,
                    'active_led' => 2,
                    'resolved_closed_led' => 2,
                    'avg_resolution_hours' => 1.5, // t5 (0.0) + t6 (3.0) => 1.5
                    'subtasks_completed' => 2,
                ])
                ->where('by_engineer.1', [
                    'id' => $engBeta->id,
                    'name' => 'Beta Engineer',
                    'department' => 'Core Systems',
                    'led_total' => 3,
                    'collaborating_total' => 1,
                    'active_led' => 2,
                    'resolved_closed_led' => 1,
                    'avg_resolution_hours' => 4, // t9 (4.0)
                    'subtasks_completed' => 1,
                ])
                ->where('by_engineer.2', [
                    'id' => $engIdle->id,
                    'name' => 'Idle Engineer',
                    'department' => 'QA',
                    'led_total' => 0,
                    'collaborating_total' => 0,
                    'active_led' => 0,
                    'resolved_closed_led' => 0,
                    'avg_resolution_hours' => null,
                    'subtasks_completed' => 0,
                ])
                // TC-07: requester departments sorted by total DESC (Finance=5, Operations=2, Unassigned=2)
                ->has('by_department', 3)
                ->where('by_department.0', [
                    'department' => 'Finance',
                    'total' => 5,
                    'issues' => 3,
                    'requests' => 2,
                    'resolved_closed' => 1,
                ])
                ->where('by_department.1.total', 2)
                ->where('by_department.2.total', 2)
                ->where('by_department', fn ($rows) => collect($rows)->pluck('department')->all() === ['Finance', 'Operations', 'Unassigned']
                    || collect($rows)->pluck('department')->all() === ['Finance', 'Unassigned', 'Operations'])
            );

        // TC-03: Zero-ticket baseline state (filtered to Empty System)
        $this->actingAs($superadmin)
            ->get(route('reports.index', ['internal_system_id' => $sysEmpty->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_tickets', 0)
                ->where('summary.issues_count', 0)
                ->where('summary.engineering_requests_count', 0)
                ->where('summary.open_active_count', 0)
                ->where('summary.resolved_closed_count', 0)
                ->where('summary.resolution_rate_percent', 0)
                ->where('summary.avg_first_response_hours', null)
                ->where('summary.avg_resolution_hours', null)
                ->where('summary.overdue_count', 0)
                ->where('by_department', [])
                // TC-05: Unassigned / General omitted when internal_system_id filter is active
                ->has('by_system', 1)
                ->where('by_system.0.system_name', 'Empty System')
                ->where('by_system.0.total', 0)
                ->where('by_status', [
                    Ticket::STATUS_PENDING_APPROVAL => 0,
                    Ticket::STATUS_OPEN => 0,
                    Ticket::STATUS_UNDER_REVIEW => 0,
                    Ticket::STATUS_IN_PROGRESS => 0,
                    Ticket::STATUS_WAITING_ON_USER => 0,
                    Ticket::STATUS_RESOLVED => 0,
                    Ticket::STATUS_CLOSED => 0,
                    Ticket::STATUS_REJECTED => 0,
                    Ticket::STATUS_CANCELLED => 0,
                ])
                ->where('by_priority', [
                    Ticket::PRIORITY_CRITICAL => 0,
                    Ticket::PRIORITY_HIGH => 0,
                    Ticket::PRIORITY_MEDIUM => 0,
                    Ticket::PRIORITY_LOW => 0,
                ])
            );

        // TC-05 extra: When zero unassigned tickets exist (type=engineering_request has none unassigned),
        // 'Unassigned / General' is omitted even without internal_system_id filter
        $this->actingAs($superadmin)
            ->get(route('reports.index', ['type' => Ticket::TYPE_ENGINEERING_REQUEST]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('by_system', fn ($rows) => ! collect($rows)->pluck('system_name')->contains('Unassigned / General'))
            );

        // TC-08: Engineer visibility scoping vs Superadmin global view
        // Beta Engineer is lead on t3, t4, t9 and collaborator on t1 => 4 tickets (t2, t5, t6, t7, t8 hidden)
        $this->actingAs($engBeta)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_tickets', 4)
                ->where('summary.issues_count', 2) // t1, t3
                ->where('summary.engineering_requests_count', 2) // t4, t9
                ->where('summary.open_active_count', 3) // t1, t3, t4
                ->where('summary.resolved_closed_count', 1) // t9
                ->where('summary.overdue_count', 1) // t1
            );

        $betaCsv = $this->actingAs($engBeta)
            ->get(route('reports.export', ['section' => 'tickets']))
            ->assertOk()
            ->streamedContent();
        $betaCsvLines = preg_split("/\r\n|\n|\r/", trim($betaCsv));
        $this->assertCount(5, $betaCsvLines); // 1 header + 4 visible tickets
        $this->assertStringContainsString('ISS-2026-5001', $betaCsv);
        $this->assertStringContainsString('ISS-2026-5003', $betaCsv);
        $this->assertStringContainsString('REQ-2026-5004', $betaCsv);
        $this->assertStringContainsString('REQ-2026-5009', $betaCsv);
        $this->assertStringNotContainsString('REQ-2026-5002', $betaCsv);
        $this->assertStringNotContainsString('ISS-2026-5006', $betaCsv);

        // TC-09: Date range filtering start & end of day boundaries
        $boundaryBefore = $makeTicket([
            'ticket_number' => 'ISS-2026-5010',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $reqOps->id,
            'lead_engineer_id' => $engAlpha->id,
            'title' => 'Before Window',
            'description' => '1 second before May 1.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
            'created_at' => Carbon::create(2026, 4, 30, 23, 59, 59),
        ]);
        $boundaryStart = $makeTicket([
            'ticket_number' => 'ISS-2026-5011',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $reqOps->id,
            'lead_engineer_id' => $engAlpha->id,
            'title' => 'Start Boundary',
            'description' => 'Exact 00:00:00 on May 1.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
            'created_at' => Carbon::create(2026, 5, 1, 0, 0, 0),
        ]);
        $boundaryEnd = $makeTicket([
            'ticket_number' => 'REQ-2026-5012',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $reqOps->id,
            'lead_engineer_id' => $engAlpha->id,
            'title' => 'End Boundary',
            'description' => 'Exact 23:59:59 on May 5.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_RESOLVED,
            'resolved_at' => Carbon::create(2026, 5, 6, 1, 59, 59),
            'created_at' => Carbon::create(2026, 5, 5, 23, 59, 59),
        ]);
        $boundaryAfter = $makeTicket([
            'ticket_number' => 'ISS-2026-5013',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $reqOps->id,
            'lead_engineer_id' => $engAlpha->id,
            'title' => 'After Window',
            'description' => '1 second after May 5.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
            'created_at' => Carbon::create(2026, 5, 6, 0, 0, 0),
        ]);

        $this->actingAs($superadmin)
            ->get(route('reports.index', ['date_from' => '2026-05-01', 'date_to' => '2026-05-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_tickets', 2)
                ->where('summary.issues_count', 1)
                ->where('summary.engineering_requests_count', 1)
            );

        $boundaryCsv = $this->actingAs($superadmin)
            ->get(route('reports.export', [
                'section' => 'tickets',
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-05',
            ]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString($boundaryStart->ticket_number, $boundaryCsv);
        $this->assertStringContainsString($boundaryEnd->ticket_number, $boundaryCsv);
        $this->assertStringNotContainsString($boundaryBefore->ticket_number, $boundaryCsv);
        $this->assertStringNotContainsString($boundaryAfter->ticket_number, $boundaryCsv);

        // TC-10: Date validation on /reports and /reports/export
        foreach (['reports.index', 'reports.export'] as $routeName) {
            $this->actingAs($superadmin)
                ->from(route('reports.index'))
                ->get(route($routeName, ['date_from' => 'not-a-date']))
                ->assertRedirect(route('reports.index'))
                ->assertSessionHasErrors('date_from');

            $this->actingAs($superadmin)
                ->from(route('reports.index'))
                ->get(route($routeName, ['date_from' => '2026-02-31']))
                ->assertRedirect(route('reports.index'))
                ->assertSessionHasErrors('date_from');

            $this->actingAs($superadmin)
                ->from(route('reports.index'))
                ->get(route($routeName, ['date_from' => '2026-12-10', 'date_to' => '2026-12-01']))
                ->assertRedirect(route('reports.index'))
                ->assertSessionHasErrors('date_to');
        }

        // TC-11: Combined type=issue&internal_system_id={alpha} + date range (2026-06-01..2026-06-30)
        // Matching tickets: t1 (open, critical, Finance, Alpha, 2 completed subtasks), t5 (resolved, critical, Ops, Alpha)
        $this->actingAs($superadmin)
            ->get(route('reports.index', [
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
                'type' => Ticket::TYPE_ISSUE,
                'internal_system_id' => $sysAlpha->id,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_tickets', 2)
                ->where('summary.issues_count', 2)
                ->where('summary.engineering_requests_count', 0)
                ->where('summary.open_active_count', 1)
                ->where('summary.resolved_closed_count', 1)
                ->where('summary.resolution_rate_percent', 50)
                ->where('summary.overdue_count', 1)
                ->where('by_priority.critical', 2)
                ->where('by_priority.high', 0)
                ->has('by_system', 1)
                ->where('by_system.0', [
                    'system_name' => 'alpha gateway',
                    'total' => 2,
                    'issues' => 2,
                    'requests' => 0,
                    'active' => 1,
                    'resolved_closed' => 1,
                    'critical' => 2,
                ])
                ->where('by_engineer.0.led_total', 2)
                ->where('by_engineer.0.subtasks_completed', 2)
                ->where('by_engineer.1.led_total', 0)
                ->where('by_engineer.1.collaborating_total', 1)
                ->where('by_engineer.1.subtasks_completed', 0) // t3 excluded by filter
                ->has('by_department', 2)
            );

        // TC-12: Invalid type and non-existent internal_system_id fail validation cleanly
        foreach (['reports.index', 'reports.export'] as $routeName) {
            $this->actingAs($superadmin)
                ->from(route('reports.index'))
                ->get(route($routeName, ['type' => 'bug_report']))
                ->assertRedirect(route('reports.index'))
                ->assertSessionHasErrors('type');

            $this->actingAs($superadmin)
                ->from(route('reports.index'))
                ->get(route($routeName, ['internal_system_id' => 999999]))
                ->assertRedirect(route('reports.index'))
                ->assertSessionHasErrors('internal_system_id');
        }

        // TC-13: CSV export section=tickets (13 headers, fallbacks 'Unassigned / General' and 'Unassigned')
        $ticketsExportResp = $this->actingAs($superadmin)
            ->get(route('reports.export', [
                'section' => 'tickets',
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk();
        $this->assertStringContainsString('text/csv', (string) $ticketsExportResp->headers->get('Content-Type'));
        $this->assertStringContainsString('UTF-8', (string) $ticketsExportResp->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression(
            '/calltek-report-tickets-\d{4}-\d{2}-\d{2}\.csv/',
            (string) $ticketsExportResp->headers->get('Content-Disposition')
        );

        $ticketRows = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($ticketsExportResp->streamedContent())));
        $this->assertSame([
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
        ], $ticketRows[0]);
        $this->assertCount(10, $ticketRows); // header + 9 June tickets
        $t6Row = collect($ticketRows)->first(fn ($row) => $row[0] === 'ISS-2026-5006');
        $this->assertNotNull($t6Row);
        $this->assertSame('Unassigned / General', $t6Row[3]);
        $t7Row = collect($ticketRows)->first(fn ($row) => $row[0] === 'REQ-2026-5007');
        $this->assertNotNull($t7Row);
        $this->assertSame('Unassigned', $t7Row[8]);

        // TC-14: CSV export section=systems (7 headers)
        $systemsExportResp = $this->actingAs($superadmin)
            ->get(route('reports.export', [
                'section' => 'systems',
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk();
        $systemRows = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($systemsExportResp->streamedContent())));
        $this->assertSame([
            'System Name',
            'Total',
            'Issues',
            'Engineering Requests',
            'Active',
            'Resolved/Closed',
            'Critical',
        ], $systemRows[0]);
        $this->assertSame(['alpha gateway', '4', '2', '2', '2', '2', '2'], $systemRows[1]);
        $this->assertSame(['Unassigned / General', '2', '2', '0', '0', '1', '0'], $systemRows[4]);

        // TC-15: CSV export section=engineers (9 headers & 'N/A' fallback)
        $engineersExportResp = $this->actingAs($superadmin)
            ->get(route('reports.export', [
                'section' => 'engineers',
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk();
        $engineerRows = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($engineersExportResp->streamedContent())));
        $this->assertSame([
            'Engineer ID',
            'Name',
            'Department',
            'Led Total',
            'Collaborating Total',
            'Active Led',
            'Resolved/Closed Led',
            'Avg Resolution Hours',
            'Subtasks Completed',
        ], $engineerRows[0]);
        $idleRow = collect($engineerRows)->first(fn ($row) => $row[1] === 'Idle Engineer');
        $this->assertNotNull($idleRow);
        $this->assertSame('N/A', $idleRow[7]);

        // TC-16: CSV export section=departments (5 headers) & unknown section=invalid fallback
        $deptExportResp = $this->actingAs($superadmin)
            ->get(route('reports.export', [
                'section' => 'departments',
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk();
        $deptRows = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($deptExportResp->streamedContent())));
        $this->assertSame([
            'Department',
            'Total',
            'Issues',
            'Engineering Requests',
            'Resolved/Closed',
        ], $deptRows[0]);
        $this->assertSame(['Finance', '5', '3', '2', '1'], $deptRows[1]);

        $invalidSectionResp = $this->actingAs($superadmin)
            ->get(route('reports.export', ['section' => 'invalid']))
            ->assertOk();
        $this->assertMatchesRegularExpression(
            '/calltek-report-tickets-\d{4}-\d{2}-\d{2}\.csv/',
            (string) $invalidSectionResp->headers->get('Content-Disposition')
        );
        $invalidRows = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($invalidSectionResp->streamedContent())));
        $this->assertCount(13, $invalidRows[0]);
        $this->assertSame('Ticket Number', $invalidRows[0][0]);

        // TC-17: CSV export filter parity across tickets, systems, engineers, departments
        $parityFilters = [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $sysAlpha->id,
        ];
        $parityTickets = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim(
            $this->actingAs($superadmin)->get(route('reports.export', $parityFilters + ['section' => 'tickets']))->assertOk()->streamedContent()
        )));
        $this->assertCount(3, $parityTickets); // header + t1 + t5

        $paritySystems = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim(
            $this->actingAs($superadmin)->get(route('reports.export', $parityFilters + ['section' => 'systems']))->assertOk()->streamedContent()
        )));
        $this->assertCount(2, $paritySystems); // header + alpha gateway
        $this->assertSame(['alpha gateway', '2', '2', '0', '1', '1', '2'], $paritySystems[1]);

        $parityEngineers = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim(
            $this->actingAs($superadmin)->get(route('reports.export', $parityFilters + ['section' => 'engineers']))->assertOk()->streamedContent()
        )));
        $alphaEngCsv = collect($parityEngineers)->first(fn ($row) => $row[1] === 'Alpha Engineer');
        $this->assertSame('2', $alphaEngCsv[3]); // Led Total
        $this->assertSame('2', $alphaEngCsv[8]); // Subtasks Completed

        $parityDepts = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim(
            $this->actingAs($superadmin)->get(route('reports.export', $parityFilters + ['section' => 'departments']))->assertOk()->streamedContent()
        )));
        $this->assertCount(3, $parityDepts); // header + Finance + Operations

        // TC-18: RBAC & active user guards
        Auth::logout();
        $this->get(route('reports.index'))->assertRedirect(route('login'));
        $this->get(route('reports.export'))->assertRedirect(route('login'));

        $this->actingAs($reqFinance)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($reqFinance)->get(route('reports.export'))->assertForbidden();

        // Mid-session deactivated engineer is logged out and redirected to /login
        $this->actingAs($engIdle)->get(route('reports.index'))->assertOk();
        $engIdle->update(['is_active' => false]);
        Auth::forgetGuards();
        $this->actingAs($engIdle)
            ->get(route('reports.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($engIdle)
            ->get(route('reports.export'))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        // TC-19: UI state & accessibility in Reports/Index.vue
        $reportsVue = (string) file_get_contents(resource_path('js/Pages/Reports/Index.vue'));
        $this->assertStringContainsString('for="report-date-from"', $reportsVue);
        $this->assertStringContainsString('id="report-date-from"', $reportsVue);
        $this->assertStringContainsString('for="report-date-to"', $reportsVue);
        $this->assertStringContainsString('id="report-date-to"', $reportsVue);
        $this->assertStringContainsString('for="report-type"', $reportsVue);
        $this->assertStringContainsString('id="report-type"', $reportsVue);
        $this->assertStringContainsString('for="report-system"', $reportsVue);
        $this->assertStringContainsString('id="report-system"', $reportsVue);
        $this->assertStringContainsString('function exportUrl(', $reportsVue);
        $this->assertStringContainsString('function resetFilters(', $reportsVue);

        // TC-20: Security, CSV RFC-4180 quoting & XSS protection
        $this->assertStringNotContainsString('v-html', $reportsVue);

        $xssTitle = 'Title, with "quotes" & <script>window.__rptXss=1</script>';
        $xssDepartment = 'Dept, "Ops" & <script>window.__rptXss=2</script>';
        $xssRequester = User::factory()->create([
            'name' => 'XSS Requester',
            'role' => User::ROLE_USER,
            'department' => $xssDepartment,
        ]);
        $xssTicket = $makeTicket([
            'ticket_number' => 'ISS-2026-5020',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $sysAlpha->id,
            'requester_id' => $xssRequester->id,
            'lead_engineer_id' => $engAlpha->id,
            'title' => $xssTitle,
            'description' => 'XSS & CSV quoting test.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
            'created_at' => Carbon::create(2026, 7, 1, 12, 0, 0),
        ]);

        // Raw Inertia props remain unmangled (Vue text interpolation escapes on render)
        $this->actingAs($superadmin)
            ->get(route('reports.index', ['date_from' => '2026-07-01', 'date_to' => '2026-07-01']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('by_department.0.department', $xssDepartment)
            );

        $xssTicketsCsv = $this->actingAs($superadmin)
            ->get(route('reports.export', [
                'section' => 'tickets',
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-01',
            ]))
            ->assertOk()
            ->streamedContent();

        $xssCsvRows = array_map('str_getcsv', preg_split("/\r\n|\n|\r/", trim($xssTicketsCsv)));
        $this->assertCount(2, $xssCsvRows);
        $this->assertSame($xssTicket->ticket_number, $xssCsvRows[1][0]);
        $this->assertSame($xssTitle, $xssCsvRows[1][2]);
        $this->assertSame($xssDepartment, $xssCsvRows[1][7]);
    }

    public function test_phase6_superadmin_administration_matrix(): void
    {
        $superadmin = User::factory()->create([
            'name' => 'Root Superadmin',
            'username' => 'root_admin',
            'email' => 'root_admin@calltek.local',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        // TC-01: Create active user, engineer, and superadmin with normalization & hashed passwords
        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Jane Requester',
                'username' => '  J_Doe-01  ',
                'email' => '  J.Doe01@CallTek.LOCAL  ',
                'password' => 'PassPhrase1!',
                'role' => User::ROLE_USER,
                'department' => 'Finance',
                'job_title' => 'Billing Analyst',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'User Jane Requester created successfully.');

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Evan Engineer',
                'username' => '  ENG_ALPHA  ',
                'email' => '  Eng.Alpha@CallTek.LOCAL  ',
                'password' => 'EngPass2!',
                'role' => User::ROLE_ENGINEER,
                'department' => 'Platform Engineering',
                'job_title' => 'Senior Engineer',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'User Evan Engineer created successfully.');

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Sonia Admin',
                'username' => '  ADMIN_TWO  ',
                'email' => '  Admin.Two@CallTek.LOCAL  ',
                'password' => 'AdminPass3!',
                'role' => User::ROLE_SUPERADMIN,
                'department' => 'IT Governance',
                'job_title' => 'System Administrator',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'User Sonia Admin created successfully.');

        $createdUser = User::where('username', 'j_doe-01')->firstOrFail();
        $createdEngineer = User::where('username', 'eng_alpha')->firstOrFail();
        $createdAdmin = User::where('username', 'admin_two')->firstOrFail();

        $this->assertSame('j.doe01@calltek.local', $createdUser->email);
        $this->assertSame(User::ROLE_USER, $createdUser->role);
        $this->assertTrue($createdUser->is_active);
        $this->assertNotSame('PassPhrase1!', $createdUser->password);
        $this->assertTrue(Hash::check('PassPhrase1!', $createdUser->password));

        $this->assertSame('eng.alpha@calltek.local', $createdEngineer->email);
        $this->assertSame(User::ROLE_ENGINEER, $createdEngineer->role);
        $this->assertTrue(Hash::check('EngPass2!', $createdEngineer->password));

        $this->assertSame('admin.two@calltek.local', $createdAdmin->email);
        $this->assertSame(User::ROLE_SUPERADMIN, $createdAdmin->role);
        $this->assertTrue(Hash::check('AdminPass3!', $createdAdmin->password));

        // Created user can authenticate with normalized credentials
        Auth::logout();
        $this->post(route('login.store'), [
            'login' => 'j_doe-01',
            'password' => 'PassPhrase1!',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($createdUser);
        Auth::logout();

        // TC-02: Update profile with blank & omitted password preserves hash; non-blank password updates hash
        $originalHash = $createdUser->password;

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $createdUser), [
                'name' => 'Jane Requester Updated',
                'username' => 'j_doe-01',
                'email' => 'j.doe01@calltek.local',
                'password' => '',
                'role' => User::ROLE_USER,
                'department' => 'Accounting',
                'job_title' => 'Lead Analyst',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'User Jane Requester Updated updated successfully.');

        $createdUser->refresh();
        $this->assertSame($originalHash, $createdUser->password);
        $this->assertSame('Accounting', $createdUser->department);

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $createdUser), [
                'name' => 'Jane Requester Omitted',
                'username' => 'j_doe-01',
                'email' => 'j.doe01@calltek.local',
                'role' => User::ROLE_USER,
                'department' => 'Accounting',
                'job_title' => 'Lead Analyst',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'));

        $createdUser->refresh();
        $this->assertSame($originalHash, $createdUser->password);

        Auth::logout();
        $this->post(route('login.store'), [
            'login' => 'j.doe01@calltek.local',
            'password' => 'PassPhrase1!',
        ])->assertRedirect(route('dashboard'));
        Auth::logout();

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $createdUser), [
                'name' => 'Jane Requester Omitted',
                'username' => 'j_doe-01',
                'email' => 'j.doe01@calltek.local',
                'password' => 'NewPass99!',
                'role' => User::ROLE_USER,
                'department' => 'Accounting',
                'job_title' => 'Lead Analyst',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'));

        $createdUser->refresh();
        $this->assertNotSame($originalHash, $createdUser->password);
        $this->assertTrue(Hash::check('NewPass99!', $createdUser->password));

        Auth::logout();
        $this->post(route('login.store'), [
            'login' => 'j_doe-01',
            'password' => 'PassPhrase1!',
        ])->assertSessionHasErrors('login');
        $this->post(route('login.store'), [
            'login' => 'j_doe-01',
            'password' => 'NewPass99!',
        ])->assertRedirect(route('dashboard'));
        Auth::logout();

        // TC-03: Creating user with null department/job_title and is_active: false persists nulls & blocks login
        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Inactive Nullable User',
                'username' => 'inactive_null',
                'email' => 'inactive.null@calltek.local',
                'password' => 'InactivePass1!',
                'role' => User::ROLE_USER,
                'department' => null,
                'job_title' => null,
                'is_active' => false,
            ])
            ->assertRedirect(route('admin.users.index'));

        $inactiveUser = User::where('username', 'inactive_null')->firstOrFail();
        $this->assertNull($inactiveUser->department);
        $this->assertNull($inactiveUser->job_title);
        $this->assertFalse($inactiveUser->is_active);

        Auth::logout();
        $this->post(route('login.store'), [
            'login' => 'inactive_null',
            'password' => 'InactivePass1!',
        ])->assertSessionHasErrors('login');
        $this->assertGuest();

        // TC-04: Case-insensitive uniqueness & self-update parity
        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Duplicate Exact',
                'username' => 'j_doe-01',
                'email' => 'j.doe01@calltek.local',
                'password' => 'Secret123!',
                'role' => User::ROLE_USER,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['username', 'email']);

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Duplicate Uppercase',
                'username' => 'J_DOE-01',
                'email' => 'J.DOE01@CALLTEK.LOCAL',
                'password' => 'Secret123!',
                'role' => User::ROLE_USER,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['username', 'email']);

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $createdEngineer), [
                'name' => 'Evan Engineer',
                'username' => 'J_Doe-01',
                'email' => 'J.Doe01@CallTek.Local',
                'role' => User::ROLE_ENGINEER,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['username', 'email']);

        // Self-update with re-cased username & email succeeds and normalizes
        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $createdUser), [
                'name' => 'Jane Requester Self-Case',
                'username' => 'J_DOE-01',
                'email' => 'J.DOE01@CALLTEK.LOCAL',
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $createdUser->refresh();
        $this->assertSame('j_doe-01', $createdUser->username);
        $this->assertSame('j.doe01@calltek.local', $createdUser->email);

        // TC-05: User format & password rules
        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Invalid Format User',
                'username' => 'bad user!',
                'email' => 'user@localhost',
                'password' => '12345',
                'role' => User::ROLE_USER,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['username', 'email', 'password']);

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Whitespace Password User',
                'username' => 'ws_pass_user',
                'email' => 'wspass@calltek.local',
                'password' => '      ',
                'role' => User::ROLE_USER,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['password']);

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => 'Valid Local Six Char',
                'username' => 'valid_6char',
                'email' => 'valid6@calltek.local',
                'password' => 'Abcdef',
                'role' => User::ROLE_USER,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        // TC-06: User field length boundaries (255 pass / 256 fail; email RFC max 254 pass / 256 fail)
        $str255 = str_repeat('a', 255);
        $str256 = str_repeat('a', 256);
        $email254 = str_repeat('e', 64).'@'.str_repeat('b', 50).'.'.str_repeat('c', 50).'.'.str_repeat('d', 50).'.'.str_repeat('f', 32).'.com';
        $email256 = str_repeat('e', 64).'@'.str_repeat('b', 50).'.'.str_repeat('c', 50).'.'.str_repeat('d', 50).'.'.str_repeat('f', 34).'.com';
        $this->assertSame(254, strlen($email254));
        $this->assertSame(256, strlen($email256));

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => $str255,
                'username' => $str255,
                'email' => $email254,
                'password' => 'Boundary255!',
                'role' => User::ROLE_USER,
                'department' => $str255,
                'job_title' => $str255,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), [
                'name' => $str256,
                'username' => $str256,
                'email' => $email256,
                'password' => 'Boundary256!',
                'role' => User::ROLE_USER,
                'department' => $str256,
                'job_title' => $str256,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['name', 'username', 'email', 'department', 'job_title']);

        // TC-07: Superadmin self-deactivation & self-demotion guards
        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $superadmin), [
                'name' => $superadmin->name,
                'username' => $superadmin->username,
                'email' => $superadmin->email,
                'role' => User::ROLE_SUPERADMIN,
                'is_active' => false,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['is_active']);

        $this->actingAs($superadmin)
            ->from(route('admin.users.index'))
            ->put(route('admin.users.update', $superadmin), [
                'name' => $superadmin->name,
                'username' => $superadmin->username,
                'email' => $superadmin->email,
                'role' => User::ROLE_ENGINEER,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['role']);

        $superadmin->refresh();
        $this->assertTrue($superadmin->is_active);
        $this->assertSame(User::ROLE_SUPERADMIN, $superadmin->role);

        // TC-08: User directory search, SQL LIKE wildcard escaping & filters + ticket counts
        $wildcardPercentMatch = User::factory()->create([
            'name' => '100% Reliability Lead',
            'username' => 'pct_match',
            'email' => 'pct.match@calltek.local',
            'role' => User::ROLE_ENGINEER,
            'department' => 'Core Ops',
            'job_title' => 'SRE',
            'is_active' => true,
        ]);
        User::factory()->create([
            'name' => '1000 Reliability Decoy',
            'username' => 'pct_decoy',
            'email' => 'pct.decoy@calltek.local',
            'role' => User::ROLE_ENGINEER,
            'department' => 'Core Ops',
            'job_title' => 'SRE',
            'is_active' => true,
        ]);
        $wildcardUnderscoreMatch = User::factory()->create([
            'name' => 'Underscore Target',
            'username' => 'ops_core_user',
            'email' => 'underscore@calltek.local',
            'role' => User::ROLE_USER,
            'department' => 'Ops',
            'job_title' => 'Operator',
            'is_active' => true,
        ]);
        User::factory()->create([
            'name' => 'Underscore Decoy',
            'username' => 'opsXcore_user',
            'email' => 'underscore.decoy@calltek.local',
            'role' => User::ROLE_USER,
            'department' => 'Ops',
            'job_title' => 'Operator',
            'is_active' => true,
        ]);
        $wildcardBackslashMatch = User::factory()->create([
            'name' => 'Backslash Target',
            'username' => 'bslash_target',
            'email' => 'bslash@calltek.local',
            'role' => User::ROLE_USER,
            'department' => 'Sec\\Ops',
            'job_title' => 'Auditor',
            'is_active' => false,
        ]);
        User::factory()->create([
            'name' => 'Backslash Decoy',
            'username' => 'bslash_decoy',
            'email' => 'bslash.decoy@calltek.local',
            'role' => User::ROLE_USER,
            'department' => 'SecOps',
            'job_title' => 'Auditor',
            'is_active' => false,
        ]);
        $jobTitleMatch = User::factory()->create([
            'name' => 'Title Search Target',
            'username' => 'title_target',
            'email' => 'title.target@calltek.local',
            'role' => User::ROLE_USER,
            'department' => 'Support',
            'job_title' => 'UniqueTitleToken99',
            'is_active' => true,
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-6001',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $wildcardUnderscoreMatch->id,
            'lead_engineer_id' => $wildcardPercentMatch->id,
            'title' => 'Counted Issue 1',
            'description' => 'Check submitted and led ticket counts.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);
        Ticket::create([
            'ticket_number' => 'REQ-2026-6002',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $wildcardUnderscoreMatch->id,
            'lead_engineer_id' => $wildcardPercentMatch->id,
            'title' => 'Counted Request 2',
            'description' => 'Check submitted and led ticket counts.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        // Search with literal '%'
        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['search' => '100%']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.id', $wildcardPercentMatch->id)
                ->where('users.data.0.led_tickets_count', 2)
                ->where('users.data.0.submitted_tickets_count', 0)
            );

        // Search with literal '_' across username
        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['search' => 'ops_core']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $wildcardUnderscoreMatch->id)
                ->where('users.data.0.submitted_tickets_count', 2)
                ->where('users.data.0.led_tickets_count', 0)
            );

        // Search with literal '\' across department
        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['search' => 'Sec\\Ops']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $wildcardBackslashMatch->id)
            );

        // Search across email and job_title
        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['search' => 'bslash@calltek.local']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $wildcardBackslashMatch->id)
            );

        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['search' => 'UniqueTitleToken99']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $jobTitleMatch->id)
            );

        // Role & is_active filters ('1' / '0')
        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['role' => User::ROLE_ENGINEER, 'is_active' => '1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.role', User::ROLE_ENGINEER)
                ->where('filters.is_active', '1')
                ->where('users.data', fn ($rows) => collect($rows)->every(
                    fn ($row) => $row['role'] === User::ROLE_ENGINEER && $row['is_active'] === true
                ))
            );

        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['is_active' => '0']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data', fn ($rows) => collect($rows)->count() >= 3 && collect($rows)->every(
                    fn ($row) => $row['is_active'] === false
                ))
            );

        // TC-09: User directory pagination (15 per page ordered by name ASC) & query string retention
        for ($i = 1; $i <= 18; $i++) {
            $padded = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            User::factory()->create([
                'name' => "PaginateUser {$padded}",
                'username' => "paginate_user_{$padded}",
                'email' => "paginate{$padded}@calltek.local",
                'role' => User::ROLE_USER,
                'is_active' => true,
            ]);
        }

        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['search' => 'PaginateUser', 'role' => User::ROLE_USER, 'is_active' => '1']))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->where('users.total', 18)
                    ->where('users.per_page', 15)
                    ->where('users.current_page', 1)
                    ->has('users.data', 15)
                    ->where('users.data.0.name', 'PaginateUser 01')
                    ->where('users.data.14.name', 'PaginateUser 15')
                    ->where('users.next_page_url', function ($url): bool {
                        $this->assertIsString($url);
                        $this->assertStringContainsString('search=PaginateUser', $url);
                        $this->assertStringContainsString('role=user', $url);
                        $this->assertStringContainsString('is_active=1', $url);
                        $this->assertStringContainsString('page=2', $url);

                        return true;
                    });
            });

        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['search' => 'PaginateUser', 'role' => User::ROLE_USER, 'is_active' => '1', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.current_page', 2)
                ->has('users.data', 3)
                ->where('users.data.0.name', 'PaginateUser 16')
                ->where('users.data.2.name', 'PaginateUser 18')
            );

        // TC-10: Create internal system; blank category -> 'Core Platform', omitted is_active -> true
        $this->actingAs($superadmin)
            ->from(route('admin.systems.index'))
            ->post(route('admin.systems.store'), [
                'name' => 'Billing Portal',
                'category' => '',
                'description' => 'Handles invoicing and settlements.',
            ])
            ->assertRedirect(route('admin.systems.index'))
            ->assertSessionHas('success', 'System Billing Portal created successfully.');

        $billingSystem = InternalSystem::where('name', 'Billing Portal')->firstOrFail();
        $this->assertSame('Core Platform', $billingSystem->category);
        $this->assertTrue($billingSystem->is_active);
        $this->assertSame('Handles invoicing and settlements.', $billingSystem->description);

        // TC-11: Update internal system; cleared category falls back to 'Core Platform'
        $this->actingAs($superadmin)
            ->from(route('admin.systems.index'))
            ->put(route('admin.systems.update', $billingSystem), [
                'name' => 'Billing Portal',
                'category' => 'Revenue Ops',
                'description' => 'Updated billing workflow.',
                'is_active' => false,
            ])
            ->assertRedirect(route('admin.systems.index'))
            ->assertSessionHas('success', 'System Billing Portal updated successfully.');

        $billingSystem->refresh();
        $this->assertSame('Revenue Ops', $billingSystem->category);
        $this->assertSame('Updated billing workflow.', $billingSystem->description);
        $this->assertFalse($billingSystem->is_active);

        $this->actingAs($superadmin)
            ->from(route('admin.systems.index'))
            ->put(route('admin.systems.update', $billingSystem), [
                'name' => 'Billing Portal',
                'category' => '',
                'description' => null,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.systems.index'));

        $billingSystem->refresh();
        $this->assertSame('Core Platform', $billingSystem->category);
        $this->assertNull($billingSystem->description);
        $this->assertTrue($billingSystem->is_active);

        // TC-12: Internal system case-insensitive name uniqueness & self-update parity
        $otherSystem = InternalSystem::create([
            'name' => 'Zeta System',
            'category' => 'Core Platform',
            'is_active' => true,
        ]);

        foreach (['Billing Portal', 'BILLING PORTAL', '  billing portal  '] as $duplicateName) {
            $this->actingAs($superadmin)
                ->from(route('admin.systems.index'))
                ->post(route('admin.systems.store'), [
                    'name' => $duplicateName,
                    'category' => 'Finance',
                    'is_active' => true,
                ])
                ->assertRedirect(route('admin.systems.index'))
                ->assertSessionHasErrors('name');

            $this->actingAs($superadmin)
                ->from(route('admin.systems.index'))
                ->put(route('admin.systems.update', $otherSystem), [
                    'name' => $duplicateName,
                    'category' => 'Core Platform',
                    'is_active' => true,
                ])
                ->assertRedirect(route('admin.systems.index'))
                ->assertSessionHasErrors('name');
        }

        // Same system can keep or re-case its own name
        $this->actingAs($superadmin)
            ->from(route('admin.systems.index'))
            ->put(route('admin.systems.update', $billingSystem), [
                'name' => 'billing portal',
                'category' => 'Core Platform',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.systems.index'))
            ->assertSessionHasNoErrors();

        $this->actingAs($superadmin)
            ->from(route('admin.systems.index'))
            ->put(route('admin.systems.update', $billingSystem), [
                'name' => 'Billing Portal',
                'category' => 'Core Platform',
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.systems.index'))
            ->assertSessionHasNoErrors();

        // TC-13: Internal system non-polluting uniqueness probe
        $systemsCountBefore = InternalSystem::count();
        $this->actingAs($superadmin)
            ->from(route('admin.systems.index'))
            ->post(route('admin.systems.store'), [
                'name' => 'BILLING PORTAL',
                'category' => $str256,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.systems.index'))
            ->assertSessionHasErrors(['name', 'category']);
        $this->assertSame($systemsCountBefore, InternalSystem::count());

        // TC-14: Internal system validation boundaries
        foreach (['', '   '] as $blankName) {
            $this->actingAs($superadmin)
                ->from(route('admin.systems.index'))
                ->post(route('admin.systems.store'), [
                    'name' => $blankName,
                    'category' => 'Ops',
                ])
                ->assertRedirect(route('admin.systems.index'))
                ->assertSessionHasErrors('name');
        }

        $this->actingAs($superadmin)
            ->from(route('admin.systems.index'))
            ->post(route('admin.systems.store'), [
                'name' => $str255,
                'category' => $str255,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.systems.index'))
            ->assertSessionHasNoErrors();

        $this->actingAs($superadmin)
            ->from(route('admin.systems.index'))
            ->post(route('admin.systems.store'), [
                'name' => $str256,
                'category' => $str256,
                'is_active' => true,
            ])
            ->assertRedirect(route('admin.systems.index'))
            ->assertSessionHasErrors(['name', 'category']);

        $this->actingAs($superadmin)
            ->from(route('admin.systems.index'))
            ->put(route('admin.systems.update', $billingSystem), [
                'name' => 'Billing Portal',
                'category' => 'Core Platform',
            ])
            ->assertRedirect(route('admin.systems.index'))
            ->assertSessionHasErrors('is_active');

        $this->actingAs($superadmin)
            ->from(route('admin.systems.index'))
            ->put(route('admin.systems.update', $billingSystem), [
                'name' => 'Billing Portal',
                'category' => 'Core Platform',
                'is_active' => 'not-a-bool',
            ])
            ->assertRedirect(route('admin.systems.index'))
            ->assertSessionHasErrors('is_active');

        // TC-15 & TC-16: Deactivation impact, case-insensitive sorting & ticket counts
        $alphaSystem = InternalSystem::create([
            'name' => 'alpha system',
            'category' => 'Infrastructure',
            'is_active' => true,
        ]);

        Ticket::create([
            'ticket_number' => 'ISS-2026-6003',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $alphaSystem->id,
            'requester_id' => $createdUser->id,
            'lead_engineer_id' => $createdEngineer->id,
            'title' => 'Alpha System Ticket',
            'description' => 'For ticket count verification.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_OPEN,
        ]);
        Ticket::create([
            'ticket_number' => 'ISS-2026-6004',
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $billingSystem->id,
            'requester_id' => $createdUser->id,
            'lead_engineer_id' => $createdEngineer->id,
            'title' => 'Billing System Ticket',
            'description' => 'Retained in reports after deactivation.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        // Deactivate Billing Portal
        $this->actingAs($superadmin)
            ->put(route('admin.systems.update', $billingSystem), [
                'name' => 'Billing Portal',
                'category' => 'Core Platform',
                'is_active' => false,
            ])
            ->assertRedirect();

        // Removed from shared internalSystems & /tickets + /tickets/kanban filter dropdowns
        $this->actingAs($superadmin)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('internalSystems', fn ($items) => ! collect($items)->pluck('id')->contains($billingSystem->id)
                    && collect($items)->pluck('id')->contains($alphaSystem->id))
                ->where('systems', fn ($items) => ! collect($items)->pluck('id')->contains($billingSystem->id)
                    && collect($items)->pluck('id')->contains($alphaSystem->id))
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('systems', fn ($items) => ! collect($items)->pluck('id')->contains($billingSystem->id)
                    && collect($items)->pluck('id')->contains($alphaSystem->id))
            );

        // Retained in /reports and /admin/systems with case-insensitive sorting ('alpha system' before 'Zeta System') and ticket counts
        $this->actingAs($superadmin)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('systems', fn ($items) => collect($items)->pluck('id')->contains($billingSystem->id))
                ->where('by_system', fn ($rows) => collect($rows)->pluck('system_name')->contains('Billing Portal'))
            );

        $this->actingAs($superadmin)
            ->get(route('admin.systems.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($alphaSystem, $billingSystem, $otherSystem): void {
                $page->component('Admin/Systems/Index')
                    ->where('systems', function ($systems) use ($alphaSystem, $billingSystem, $otherSystem): bool {
                        $collection = collect($systems);
                        $names = $collection->pluck('name')->values()->all();

                        $alphaIndex = array_search('alpha system', $names, true);
                        $billingIndex = array_search('Billing Portal', $names, true);
                        $zetaIndex = array_search('Zeta System', $names, true);

                        $this->assertNotFalse($alphaIndex);
                        $this->assertNotFalse($billingIndex);
                        $this->assertNotFalse($zetaIndex);
                        $this->assertTrue($alphaIndex < $billingIndex && $billingIndex < $zetaIndex);

                        $alphaRow = $collection->firstWhere('id', $alphaSystem->id);
                        $billingRow = $collection->firstWhere('id', $billingSystem->id);
                        $zetaRow = $collection->firstWhere('id', $otherSystem->id);

                        $this->assertSame(1, $alphaRow['tickets_count']);
                        $this->assertSame(1, $billingRow['tickets_count']);
                        $this->assertFalse($billingRow['is_active']);
                        $this->assertSame(0, $zetaRow['tickets_count']);

                        return true;
                    });
            });

        // TC-17: RBAC isolation on all admin endpoints
        Auth::logout();
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->post(route('admin.users.store'), [])->assertRedirect(route('login'));
        $this->put(route('admin.users.update', $createdUser), [])->assertRedirect(route('login'));
        $this->get(route('admin.systems.index'))->assertRedirect(route('login'));
        $this->post(route('admin.systems.store'), [])->assertRedirect(route('login'));
        $this->put(route('admin.systems.update', $alphaSystem), [])->assertRedirect(route('login'));

        foreach ([$createdEngineer, $createdUser] as $nonAdmin) {
            $this->actingAs($nonAdmin)->get(route('admin.users.index'))->assertForbidden();
            $this->actingAs($nonAdmin)->post(route('admin.users.store'), [
                'name' => 'Blocked',
                'username' => 'blocked_user',
                'email' => 'blocked@calltek.local',
                'password' => 'Blocked123!',
                'role' => User::ROLE_USER,
            ])->assertForbidden();
            $this->actingAs($nonAdmin)->put(route('admin.users.update', $createdUser), [
                'name' => 'Blocked Edit',
                'username' => $createdUser->username,
                'email' => $createdUser->email,
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])->assertForbidden();

            $this->actingAs($nonAdmin)->get(route('admin.systems.index'))->assertForbidden();
            $this->actingAs($nonAdmin)->post(route('admin.systems.store'), [
                'name' => 'Blocked System',
            ])->assertForbidden();
            $this->actingAs($nonAdmin)->put(route('admin.systems.update', $alphaSystem), [
                'name' => 'Blocked Edit System',
                'is_active' => true,
            ])->assertForbidden();
        }

        // TC-18: Non-existent user & system 404 boundaries
        $this->actingAs($superadmin)->put('/admin/users/999999', [
            'name' => 'Missing User',
        ])->assertNotFound();
        $this->actingAs($superadmin)->put('/admin/systems/999999', [
            'name' => 'Missing System',
        ])->assertNotFound();

        // TC-19: UI state & accessibility on Admin/Users/Index.vue & Admin/Systems/Index.vue
        $adminUsersVue = (string) file_get_contents(resource_path('js/Pages/Admin/Users/Index.vue'));
        foreach ([
            'for="create-user-name"', 'id="create-user-name"',
            'for="create-user-username"', 'id="create-user-username"',
            'for="create-user-email"', 'id="create-user-email"',
            'for="create-user-password"', 'id="create-user-password"',
            'for="create-user-role"', 'id="create-user-role"',
            'for="create-user-department"', 'id="create-user-department"',
            'for="create-user-job-title"', 'id="create-user-job-title"',
            'for="create-user-active"', 'id="create-user-active"',
            'for="edit-user-name"', 'id="edit-user-name"',
            'for="edit-user-username"', 'id="edit-user-username"',
            'for="edit-user-email"', 'id="edit-user-email"',
            'for="edit-user-password"', 'id="edit-user-password"',
            'for="edit-user-role"', 'id="edit-user-role"',
            'for="edit-user-department"', 'id="edit-user-department"',
            'for="edit-user-job-title"', 'id="edit-user-job-title"',
            'for="edit-user-active"', 'id="edit-user-active"',
            ':readonly="!createUnlockedFields.name"',
            ':readonly="!createUnlockedFields.username"',
            ':readonly="!createUnlockedFields.email"',
            ':readonly="!createUnlockedFields.password"',
            ':readonly="!editPasswordUnlocked"',
            ':disabled="isEditingSelf"',
        ] as $expectedFragment) {
            $this->assertStringContainsString($expectedFragment, $adminUsersVue);
        }

        $adminSystemsVue = (string) file_get_contents(resource_path('js/Pages/Admin/Systems/Index.vue'));
        foreach ([
            'for="create-system-name"', 'id="create-system-name"',
            'for="create-system-category"', 'id="create-system-category"',
            'for="create-system-description"', 'id="create-system-description"',
            'for="create-system-active"', 'id="create-system-active"',
            'for="edit-system-name"', 'id="edit-system-name"',
            'for="edit-system-category"', 'id="edit-system-category"',
            'for="edit-system-description"', 'id="edit-system-description"',
            'for="edit-system-active"', 'id="edit-system-active"',
            'maxlength="255"',
        ] as $expectedFragment) {
            $this->assertStringContainsString($expectedFragment, $adminSystemsVue);
        }

        // TC-20: Security & XSS escaping (no v-html in admin templates; raw payloads returned in Inertia props)
        $this->assertStringNotContainsString('v-html', $adminUsersVue);
        $this->assertStringNotContainsString('v-html', $adminSystemsVue);

        $xssScript = '<script>window.__adminXss=1</script>';
        $xssImg = '<img src=x onerror=alert(6)>';

        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => $xssScript,
                'username' => 'xss_admin_probe',
                'email' => 'xss.probe@calltek.local',
                'password' => 'XssPass123!',
                'role' => User::ROLE_USER,
                'department' => $xssImg,
                'job_title' => $xssScript,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->actingAs($superadmin)
            ->post(route('admin.systems.store'), [
                'name' => $xssScript,
                'category' => $xssImg,
                'description' => $xssImg,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['search' => 'xss_admin_probe']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.name', $xssScript)
                ->where('users.data.0.department', $xssImg)
                ->where('users.data.0.job_title', $xssScript)
            );

        $this->actingAs($superadmin)
            ->get(route('admin.systems.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('systems', function ($systems) use ($xssScript, $xssImg): bool {
                    $xssSys = collect($systems)->firstWhere('name', $xssScript);
                    $this->assertNotNull($xssSys);
                    $this->assertSame($xssImg, $xssSys['category']);
                    $this->assertSame($xssImg, $xssSys['description']);

                    return true;
                })
            );
    }

    public function test_phase7_workspace_ux_and_global_utilities_matrix(): void
    {
        $superadmin = User::factory()->create([
            'name' => 'Phase7 Superadmin',
            'username' => 'p7_superadmin',
            'email' => 'p7.superadmin@calltek.local',
            'password' => 'Pass1234!',
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $engineer = User::factory()->create([
            'name' => 'Phase7 Engineer',
            'username' => 'p7_engineer',
            'email' => 'p7.engineer@calltek.local',
            'password' => 'Pass1234!',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $otherEngineer = User::factory()->create([
            'name' => 'Phase7 Other Engineer',
            'username' => 'p7_other_eng',
            'email' => 'p7.othereng@calltek.local',
            'password' => 'Pass1234!',
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $requester = User::factory()->create([
            'name' => 'Phase7 Requester',
            'username' => 'p7_requester',
            'email' => 'p7.requester@calltek.local',
            'password' => 'Pass1234!',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $otherRequester = User::factory()->create([
            'name' => 'Phase7 Other Requester',
            'username' => 'p7_other_req',
            'email' => 'p7.otherreq@calltek.local',
            'password' => 'Pass1234!',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $sysZeta = InternalSystem::create([
            'name' => 'zeta System',
            'category' => 'Core',
            'is_active' => true,
        ]);
        $sysAlpha = InternalSystem::create([
            'name' => 'Alpha System',
            'category' => 'Platform',
            'is_active' => true,
        ]);
        $sysInactive = InternalSystem::create([
            'name' => 'Beta Inactive',
            'category' => 'Legacy',
            'is_active' => false,
        ]);

        $ticketSeq = 1;
        $createTicket = function (array $overrides = []) use (&$ticketSeq, $requester, $sysAlpha): Ticket {
            $seq = $ticketSeq++;
            $defaults = [
                'ticket_number' => sprintf('ISS-2026-%04d', 7000 + $seq),
                'type' => Ticket::TYPE_ISSUE,
                'internal_system_id' => $sysAlpha->id,
                'requester_id' => $requester->id,
                'lead_engineer_id' => null,
                'title' => "Phase7 Ticket {$seq}",
                'description' => "Description for ticket {$seq}",
                'priority' => Ticket::PRIORITY_MEDIUM,
                'status' => Ticket::STATUS_OPEN,
                'environment' => 'production',
                'impact_scope' => 'single_user',
                'target_due_date' => null,
                'created_at' => Carbon::create(2026, 7, 1, 8, 0, 0)->addMinutes($seq),
                'updated_at' => Carbon::create(2026, 7, 1, 9, 0, 0)->addMinutes($seq),
            ];

            $ticket = new Ticket;
            $ticket->forceFill(array_merge($defaults, $overrides));
            $ticket->save(['timestamps' => false]);

            return $ticket;
        };

        // TC-01 & TC-02: Pagination (15/page), updated_at DESC + id DESC tie-breaker, statusCounts scoped to visibleTo
        $tieTimestamp = Carbon::create(2026, 7, 20, 12, 0, 0);
        $tieOlderId = $createTicket([
            'ticket_number' => 'ISS-2026-7101',
            'title' => 'Tie Breaker Lower ID',
            'status' => Ticket::STATUS_OPEN,
            'updated_at' => $tieTimestamp,
        ]);
        $tieNewerId = $createTicket([
            'ticket_number' => 'ISS-2026-7102',
            'title' => 'Tie Breaker Higher ID',
            'status' => Ticket::STATUS_UNDER_REVIEW,
            'lead_engineer_id' => $engineer->id,
            'updated_at' => $tieTimestamp,
        ]);

        // Add tickets across all 8 statuses for requester + 2 extra for otherRequester
        $statusFixtures = [
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_WAITING_ON_USER,
            Ticket::STATUS_RESOLVED,
            Ticket::STATUS_CLOSED,
            Ticket::STATUS_REJECTED,
            Ticket::STATUS_CANCELLED,
            Ticket::STATUS_OPEN,
            Ticket::STATUS_OPEN,
            Ticket::STATUS_OPEN,
            Ticket::STATUS_OPEN,
            Ticket::STATUS_OPEN,
            Ticket::STATUS_OPEN,
            Ticket::STATUS_OPEN,
        ];
        foreach ($statusFixtures as $idx => $status) {
            $createTicket([
                'status' => $status,
                'lead_engineer_id' => $status === Ticket::STATUS_IN_PROGRESS ? $engineer->id : null,
                'updated_at' => Carbon::create(2026, 7, 10, 10, 0, 0)->addMinutes($idx),
            ]);
        }

        // Other requester ticket (visible to superadmin and $otherEngineer, NOT $requester)
        $otherTicket = $createTicket([
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'updated_at' => Carbon::create(2026, 7, 5, 10, 0, 0),
        ]);

        // Superadmin sees all 17 tickets: page 1 has 15, tieNewerId before tieOlderId
        $this->actingAs($superadmin)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.per_page', 15)
                ->where('tickets.total', 17)
                ->where('tickets.last_page', 2)
                ->has('tickets.data', 15)
                ->where('tickets.data.0.id', $tieNewerId->id)
                ->where('tickets.data.1.id', $tieOlderId->id)
                ->where('statusCounts', [
                    'all' => 17,
                    'pending_approval' => 0,
                    'open' => 8,
                    'under_review' => 1,
                    'in_progress' => 3,
                    'waiting_on_user' => 1,
                    'resolved' => 1,
                    'closed' => 1,
                    'rejected' => 1,
                    'cancelled' => 1,
                ])
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 2)
                ->where('tickets.data.1.id', $otherTicket->id)
            );

        // Requester statusCounts are scoped to Ticket::visibleTo($requester) (16 tickets)
        $this->actingAs($requester)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', 16)
                ->where('statusCounts.all', 16)
                ->where('statusCounts.in_progress', 2)
            );

        // TC-02: Status filter scopes tickets.data while preserving all statusCounts
        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['status' => Ticket::STATUS_IN_PROGRESS]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', 3)
                ->has('tickets.data', 3)
                ->where('tickets.data.0.status', Ticket::STATUS_IN_PROGRESS)
                ->where('tickets.data.1.status', Ticket::STATUS_IN_PROGRESS)
                ->where('tickets.data.2.status', Ticket::STATUS_IN_PROGRESS)
                ->where('statusCounts.all', 17)
                ->where('statusCounts.open', 8)
                ->where('statusCounts.in_progress', 3)
            );

        // TC-03: Search trims whitespace, matches ticket_number/title/description, escapes %, _, \
        $percentMatch = $createTicket([
            'ticket_number' => 'ISS-2026-7901',
            'title' => 'CPU usage at 100% spike',
            'description' => 'Normal description',
        ]);
        $percentDecoy = $createTicket([
            'ticket_number' => 'ISS-2026-7902',
            'title' => 'CPU usage at 1000 spike',
            'description' => 'Normal description',
        ]);
        $underscoreMatch = $createTicket([
            'ticket_number' => 'ISS-2026-7903',
            'title' => 'Regular title',
            'description' => 'Flag foo_bar failed in worker',
        ]);
        $underscoreDecoy = $createTicket([
            'ticket_number' => 'ISS-2026-7904',
            'title' => 'Regular title',
            'description' => 'Flag fooXbar failed in worker',
        ]);
        $backslashMatch = $createTicket([
            'ticket_number' => 'ISS-2026-7905',
            'title' => 'Directory C:\\temp access error',
            'description' => 'Windows path issue',
        ]);
        $backslashDecoy = $createTicket([
            'ticket_number' => 'ISS-2026-7906',
            'title' => 'Directory C:temp access error',
            'description' => 'Windows path issue',
        ]);

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => '  100%  ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $percentMatch->id)
                ->where('filters.search', '100%')
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => '  FOO_BAR  ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $underscoreMatch->id)
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => '  C:\\temp  ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $backslashMatch->id)
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['search' => '  iss-2026-7902  ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $percentDecoy->id)
            );

        // Clean up decoy assertions reference so static analysis doesn't complain
        $this->assertNotSame($underscoreDecoy->id, $underscoreMatch->id);
        $this->assertNotSame($backslashDecoy->id, $backslashMatch->id);

        // TC-04: Combining type, priority, internal_system_id, lead_engineer_id, scope + query retention on pagination
        $overdueComboIds = [];
        for ($i = 1; $i <= 16; $i++) {
            $comboTicket = $createTicket([
                'type' => Ticket::TYPE_ENGINEERING_REQUEST,
                'priority' => Ticket::PRIORITY_CRITICAL,
                'internal_system_id' => $sysZeta->id,
                'lead_engineer_id' => $otherEngineer->id,
                'status' => Ticket::STATUS_IN_PROGRESS,
                'target_due_date' => today()->subDays(2)->toDateString(),
                'business_justification' => 'Need upgrade',
                'proposed_functionality' => 'Add feature',
                'expected_benefits' => 'Faster triage',
                'environment' => null,
                'impact_scope' => null,
                'updated_at' => Carbon::create(2026, 8, 1, 10, 0, 0)->addMinutes($i),
            ]);
            $overdueComboIds[] = $comboTicket->id;
        }

        // Non-overdue ticket with same type/priority/system/lead should be excluded by scope=overdue
        $createTicket([
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'priority' => Ticket::PRIORITY_CRITICAL,
            'internal_system_id' => $sysZeta->id,
            'lead_engineer_id' => $otherEngineer->id,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'target_due_date' => today()->addDays(3)->toDateString(),
            'business_justification' => 'Future due date',
            'proposed_functionality' => 'Add feature',
            'expected_benefits' => 'Faster triage',
            'environment' => null,
            'impact_scope' => null,
        ]);

        $comboQuery = [
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'priority' => Ticket::PRIORITY_CRITICAL,
            'internal_system_id' => $sysZeta->id,
            'lead_engineer_id' => $otherEngineer->id,
            'scope' => 'overdue',
        ];

        $this->actingAs($superadmin)
            ->get(route('tickets.index', $comboQuery))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', 16)
                ->has('tickets.data', 15)
                ->where('tickets.next_page_url', function (?string $url) use ($sysZeta, $otherEngineer): bool {
                    $this->assertNotNull($url);
                    $this->assertStringContainsString('type=engineering_request', $url);
                    $this->assertStringContainsString('priority=critical', $url);
                    $this->assertStringContainsString("internal_system_id={$sysZeta->id}", $url);
                    $this->assertStringContainsString("lead_engineer_id={$otherEngineer->id}", $url);
                    $this->assertStringContainsString('scope=overdue', $url);
                    $this->assertStringContainsString('page=2', $url);

                    return true;
                })
                ->where('tickets.links', function ($links) use ($sysZeta, $otherEngineer): bool {
                    $page2Link = collect($links)->first(fn ($l) => str_contains((string) ($l['url'] ?? ''), 'page=2'));
                    $this->assertNotNull($page2Link);
                    $this->assertStringContainsString('type=engineering_request', $page2Link['url']);
                    $this->assertStringContainsString('scope=overdue', $page2Link['url']);
                    $this->assertStringContainsString("internal_system_id={$sysZeta->id}", $page2Link['url']);
                    $this->assertStringContainsString("lead_engineer_id={$otherEngineer->id}", $page2Link['url']);

                    return true;
                })
            );

        // TC-05: Role-based filter guards on GET /tickets
        // Setup collaborator relationship and superadmin-led / engineer-collaborating tickets
        $collabTicketForEng = $createTicket([
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => $otherEngineer->id,
            'status' => Ticket::STATUS_OPEN,
        ]);
        $collabTicketForEng->collaborators()->attach($engineer->id);

        $superadminLedTicket = $createTicket([
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => $superadmin->id,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $requesterCollabTicket = $createTicket([
            'requester_id' => $otherRequester->id,
            'lead_engineer_id' => null,
            'status' => Ticket::STATUS_OPEN,
        ]);
        $requesterCollabTicket->collaborators()->attach($requester->id);

        // Engineer: lead_engineer_id filter is ignored, scope=unassigned & scope=my_submitted are ignored
        $engineerTotalVisible = Ticket::visibleTo($engineer)->count();
        $this->assertGreaterThan(0, $engineerTotalVisible);

        $this->actingAs($engineer)
            ->get(route('tickets.index', ['lead_engineer_id' => $otherEngineer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', $engineerTotalVisible)
            );

        $this->actingAs($engineer)
            ->get(route('tickets.index', ['scope' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', $engineerTotalVisible)
            );

        $this->actingAs($engineer)
            ->get(route('tickets.index', ['scope' => 'my_submitted']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', $engineerTotalVisible)
            );

        // Engineer: scope=led_by_me and scope=collaborating work
        $this->actingAs($engineer)
            ->get(route('tickets.index', ['scope' => 'led_by_me']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', Ticket::visibleTo($engineer)->where('lead_engineer_id', $engineer->id)->count())
            );

        $this->actingAs($engineer)
            ->get(route('tickets.index', ['scope' => 'collaborating']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', 1)
                ->where('tickets.data.0.id', $collabTicketForEng->id)
            );

        // Requester: lead_engineer_id ignored; scope=unassigned & scope=my_submitted work
        $requesterTotalVisible = Ticket::visibleTo($requester)->count();
        $requesterUnassignedCount = Ticket::visibleTo($requester)->whereNull('lead_engineer_id')->count();
        $requesterSubmittedCount = Ticket::visibleTo($requester)->where('requester_id', $requester->id)->count();
        $this->assertSame($requesterTotalVisible - 1, $requesterSubmittedCount); // excludes $requesterCollabTicket

        $this->actingAs($requester)
            ->get(route('tickets.index', ['lead_engineer_id' => $otherEngineer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', $requesterTotalVisible)
            );

        $this->actingAs($requester)
            ->get(route('tickets.index', ['scope' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', $requesterUnassignedCount)
            );

        $this->actingAs($requester)
            ->get(route('tickets.index', ['scope' => 'my_submitted']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', $requesterSubmittedCount)
            );

        // Superadmin: lead_engineer_id=unassigned, scope=unassigned, scope=led_by_me, scope=my_submitted
        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['lead_engineer_id' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', Ticket::whereNull('lead_engineer_id')->count())
            );

        $this->actingAs($superadmin)
            ->get(route('tickets.index', ['scope' => 'led_by_me']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.total', 1)
                ->where('tickets.data.0.id', $superadminLedTicket->id)
            );

        // TC-06: Global quick search contract
        $appLayoutVue = (string) file_get_contents(resource_path('js/Layouts/AppLayout.vue'));
        foreach ([
            'function handleQuickSearch()',
            'const trimmed = searchQuery.value.trim();',
            "router.get('/tickets', trimmed ? { search: trimmed } : {});",
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $appLayoutVue);
        }

        $this->actingAs($superadmin)
            ->get('/tickets?search='.urlencode('ISS-2026-7102'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $tieNewerId->id)
            );

        $this->actingAs($superadmin)
            ->get('/tickets?search='.urlencode('Tie Breaker Lower ID'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $tieOlderId->id)
            );

        // TC-07: Role-aware navigation groups in AppLayout.vue & auth.user flags
        foreach ([
            "label: 'Dashboard'",
            "href: '/dashboard'",
            "group: 'Workspace'",
            "label: 'Tickets'",
            "href: '/tickets'",
            'if (user.value?.is_staff)',
            "label: 'Kanban Board'",
            "href: '/tickets/kanban'",
            "group: 'Engineering'",
            "label: 'Reports'",
            "href: '/reports'",
            'if (user.value?.is_super_admin)',
            "label: 'Systems'",
            "href: '/admin/systems'",
            "group: 'Administration'",
            "label: 'Users'",
            "href: '/admin/users'",
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $appLayoutVue);
        }

        $this->actingAs($superadmin)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.user.is_super_admin', true)
            ->where('auth.user.is_engineer', false)
            ->where('auth.user.is_staff', true)
        );
        $this->actingAs($engineer)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.user.is_super_admin', false)
            ->where('auth.user.is_engineer', true)
            ->where('auth.user.is_staff', true)
        );
        $this->actingAs($requester)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('auth.user.is_super_admin', false)
            ->where('auth.user.is_engineer', false)
            ->where('auth.user.is_staff', false)
        );

        // TC-08: Global flash toast contract & flash.id deduplication
        $this->actingAs($requester)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.id', null)
                ->where('flash.success', null)
                ->where('flash.error', null)
            );

        $this->actingAs($requester)
            ->withSession(['success' => 'Action succeeded.'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', 'Action succeeded.')
                ->where('flash.id', fn (?string $id): bool => is_string($id) && str_starts_with($id, 'flash_'))
            );

        $this->actingAs($requester)
            ->withSession(['error' => 'Action blocked.'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.error', 'Action blocked.')
                ->where('flash.id', fn (?string $id): bool => is_string($id) && str_starts_with($id, 'flash_'))
            );

        // Validation errors also trigger flash.id
        $this->actingAs($requester)
            ->from(route('dashboard'))
            ->followingRedirects()
            ->post(route('tickets.store'), [])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.id', fn (?string $id): bool => is_string($id) && str_starts_with($id, 'flash_'))
                ->has('errors.type')
                ->has('errors.title')
            );

        $useFeedbackJs = (string) file_get_contents(resource_path('js/composables/useFeedback.js'));
        foreach ([
            'if (signature === lastToastSignature && now - lastToastTime < 150)',
            'function markFlashProcessed(flashId)',
            'if (lastProcessedFlashId === flashId)',
            'lastProcessedFlashId = flashId;',
            'notifySuccess',
            'notifyError',
            'dismissToast',
            'clearToasts',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $useFeedbackJs);
        }

        // TC-09: Validation error toast aggregation & HTTP/network error listeners in AppLayout.vue
        foreach ([
            '`Submission failed: ${firstValidationError.value} (+${validationErrorCount.value - 1} more)`',
            '`Submission failed: ${firstValidationError.value}`',
            "router.on('httpException'",
            'if (status === 403)',
            "notifyError('You do not have permission to perform this action.')",
            'else if (status === 419)',
            "notifyError('Your session expired. Please refresh the page and try again.')",
            'else if (status === 413)',
            "notifyError('The uploaded files exceed the maximum allowed size.')",
            "router.on('networkError'",
            "notifyError('Network or server error occurred while submitting. Please try again.')",
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $appLayoutVue);
        }

        // TC-10: Global keyboard shortcuts in AppLayout.vue
        foreach ([
            'function handleGlobalKeydown(event)',
            'if (isNewTicketModalOpen.value)',
            "if (event.key === 'Escape')",
            'notificationsOpen.value = false;',
            'mobileMenuOpen.value = false;',
            "event.key === '/'",
            "tagName === 'INPUT'",
            "tagName === 'TEXTAREA'",
            "tagName === 'SELECT'",
            'active?.isContentEditable',
            'searchInputRef.value?.focus();',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $appLayoutVue);
        }

        // TC-11: useNewTicketModal.js & shared internalSystems across pages + empty for guests
        $useNewTicketModalJs = (string) file_get_contents(resource_path('js/composables/useNewTicketModal.js'));
        foreach ([
            'const isNewTicketModalOpen = ref(false);',
            'export function useNewTicketModal()',
            'function openNewTicketModal()',
            'function closeNewTicketModal()',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $useNewTicketModalJs);
        }

        Auth::logout();
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('internalSystems', [])
            );

        $expectedSystems = [
            ['id' => $sysAlpha->id, 'name' => 'Alpha System'],
            ['id' => $sysZeta->id, 'name' => 'zeta System'],
        ];
        foreach (['dashboard', 'tickets.index', 'notifications.index'] as $routeName) {
            $this->actingAs($requester)
                ->get(route($routeName))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('internalSystems', $expectedSystems)
                );
        }
        $this->assertFalse($sysInactive->is_active);

        // TC-12: Root redirect & intended URL redirect after login
        Auth::logout();
        $this->get('/')->assertRedirect(route('login'));

        $this->actingAs($requester)->get('/')->assertRedirect(route('dashboard'));

        Auth::logout();
        $this->get(route('tickets.index'))->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'login' => $requester->username,
            'password' => 'Pass1234!',
        ])->assertRedirect(route('tickets.index'));
        $this->assertAuthenticatedAs($requester);

        // TC-13: Mid-session deactivation via EnsureUserIsActive (GET and POST/PATCH)
        Auth::logout();
        $deactivatedViaGet = User::factory()->create([
            'username' => 'p7_deact_get',
            'password' => 'Pass1234!',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $this->post(route('login.store'), [
            'login' => 'p7_deact_get',
            'password' => 'Pass1234!',
        ])->assertRedirect(route('dashboard'));
        $deactivatedViaGet->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->get(route('tickets.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your account has been deactivated. Please contact the Superadmin.');
        $this->assertGuest();

        $deactivatedViaPost = User::factory()->create([
            'username' => 'p7_deact_post',
            'password' => 'Pass1234!',
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $this->post(route('login.store'), [
            'login' => 'p7_deact_post',
            'password' => 'Pass1234!',
        ])->assertRedirect(route('dashboard'));
        $deactivatedViaPost->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->post(route('notifications.readAll'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Your account has been deactivated. Please contact the Superadmin.');
        $this->assertGuest();

        // TC-14: Pagination.vue sanitization & boundary rendering
        $paginationVue = (string) file_get_contents(resource_path('js/Components/Pagination.vue'));
        foreach ([
            'v-if="links && links.length > 3"',
            'function formatLabel(rawLabel)',
            ".replace(/&laquo;\\s*/gi, '')",
            ".replace(/\\s*&raquo;/gi, '')",
            ".replace(/<[^>]+>/g, '')",
            'Showing',
            '{{ from }}–{{ to }}',
            '{{ total }}',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $paginationVue);
        }

        // TC-15: Modal.vue ARIA dialog & scroll lock contract
        $modalVue = (string) file_get_contents(resource_path('js/Components/Modal.vue'));
        foreach ([
            'role="dialog"',
            'aria-modal="true"',
            'aria-labelledby="modal-dialog-title"',
            'id="modal-dialog-title"',
            "document.body.style.overflow = isOpen ? 'hidden' : '';",
            "if (event.key === 'Escape' && props.show)",
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $modalVue);
        }

        // TC-16: formatters.js utilities contract
        $formattersJs = (string) file_get_contents(resource_path('js/utils/formatters.js'));
        foreach ([
            'export const STATUS_LABELS =',
            'export const PRIORITY_LABELS =',
            'export const TYPE_LABELS =',
            'export const ENVIRONMENT_LABELS =',
            'export const IMPACT_SCOPE_LABELS =',
            'export const ACTIVE_STATUSES =',
            'export function formatDateTime(value, fallback = \'Not set\')',
            'export function formatDate(value, fallback = \'Not set\')',
            'export function toDateInputValue(value)',
            'export function isTicketOverdue(targetDueDate, status)',
            'export function formatFileSize(bytes)',
            'if (size < 1024)',
            'return `${size} B`;',
            'if (size < 1024 * 1024)',
            'return `${(size / 1024).toFixed(1)} KB`;',
            'return `${(size / (1024 * 1024)).toFixed(2)} MB`;',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $formattersJs);
        }

        // TC-17: Badge components contract
        $statusBadgeVue = (string) file_get_contents(resource_path('js/Components/StatusBadge.vue'));
        $priorityBadgeVue = (string) file_get_contents(resource_path('js/Components/PriorityBadge.vue'));
        $typeBadgeVue = (string) file_get_contents(resource_path('js/Components/TypeBadge.vue'));
        $roleBadgeVue = (string) file_get_contents(resource_path('js/Components/RoleBadge.vue'));

        $this->assertStringContainsString('STATUS_LABELS[props.status] || props.status', $statusBadgeVue);
        $this->assertStringContainsString('PRIORITY_LABELS[props.priority] || props.priority', $priorityBadgeVue);
        $this->assertStringContainsString('TYPE_LABELS[props.type] || props.type', $typeBadgeVue);
        $this->assertStringContainsString('map[props.role] || props.role', $roleBadgeVue);
        foreach (["superadmin: 'Superadmin'", "engineer: 'Engineer'", "user: 'Requester'"] as $roleLabel) {
            $this->assertStringContainsString($roleLabel, $roleBadgeVue);
        }

        // TC-18 & TC-20: Zero Icons Rule audit & Global XSS / template escaping audit across all .vue and .blade.php files
        $vueFiles = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)
        );
        $scannedVueCount = 0;
        foreach ($vueFiles as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'vue') {
                $scannedVueCount++;
                $path = $fileInfo->getPathname();
                $content = (string) file_get_contents($path);

                $this->assertDoesNotMatchRegularExpression('/<i(\s|>)/i', $content, "Forbidden <i> tag in {$path}");
                $this->assertDoesNotMatchRegularExpression('/\bfa-[a-z0-9-]+/i', $content, "Forbidden fa- class in {$path}");
                $this->assertStringNotContainsString('fontawesome', mb_strtolower($content), "Forbidden fontawesome reference in {$path}");
                $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $content), "Forbidden emoji in {$path}");
                $this->assertStringNotContainsString('v-html', $content, "Forbidden v-html directive in {$path}");
            }
        }
        $this->assertGreaterThan(0, $scannedVueCount);

        $bladeFiles = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );
        $scannedBladeCount = 0;
        foreach ($bladeFiles as $fileInfo) {
            if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.blade.php')) {
                $scannedBladeCount++;
                $path = $fileInfo->getPathname();
                $content = (string) file_get_contents($path);

                $this->assertDoesNotMatchRegularExpression('/<i(\s|>)/i', $content, "Forbidden <i> tag in {$path}");
                $this->assertDoesNotMatchRegularExpression('/\bfa-[a-z0-9-]+/i', $content, "Forbidden fa- class in {$path}");
                $this->assertStringNotContainsString('fontawesome', mb_strtolower($content), "Forbidden fontawesome reference in {$path}");
                $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $content), "Forbidden emoji in {$path}");
                $this->assertStringNotContainsString('{!!', $content, "Forbidden unescaped Blade output in {$path}");
            }
        }
        $this->assertGreaterThan(0, $scannedBladeCount);

        // TC-19: Workspace filter control IDs & accessibility
        $ticketsIndexVue = (string) file_get_contents(resource_path('js/Pages/Tickets/Index.vue'));
        foreach ([
            'id="filter-search"',
            'id="filter-type"',
            'id="filter-priority"',
            'id="filter-system"',
            'id="filter-lead"',
            'id="filter-scope"',
        ] as $expectedId) {
            $this->assertStringContainsString($expectedId, $ticketsIndexVue);
        }

        $adminUsersVue = (string) file_get_contents(resource_path('js/Pages/Admin/Users/Index.vue'));
        foreach ([
            'id="admin-users-search"',
            'id="admin-users-role"',
            'id="admin-users-status"',
        ] as $expectedId) {
            $this->assertStringContainsString($expectedId, $adminUsersVue);
        }
    }
}
