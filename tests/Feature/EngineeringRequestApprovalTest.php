<?php

namespace Tests\Feature;

use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EngineeringRequestApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        Mail::fake();
        Storage::fake((string) config('filesystems.attachments_disk'));
    }

    public function test_non_director_engineering_request_enters_pending_approval_and_notifies_active_directors_with_superadmin_fallback(): void
    {
        $system = InternalSystem::create([
            'name' => 'Operations Portal',
            'category' => 'Internal Ops',
            'is_active' => true,
        ]);

        $standardUser = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER, 'is_active' => true]);
        $nonDirectorSuperadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN, 'is_director' => false, 'is_active' => true]);
        $userDirector = User::factory()->director()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $superadminDirector = User::factory()->director()->create(['role' => User::ROLE_SUPERADMIN, 'is_active' => true]);
        $inactiveDirector = User::factory()->director()->create(['role' => User::ROLE_USER, 'is_active' => false]);

        // Issue submitted by standard user still starts in open with null approval_status
        $issueResponse = $this->actingAs($standardUser)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => $system->id,
            'title' => 'Portal 502 error',
            'description' => 'Gateway returns 502 on login.',
            'priority' => Ticket::PRIORITY_HIGH,
            'environment' => 'production',
            'impact_scope' => 'department',
            'attachments' => [
                UploadedFile::fake()->create('error.log', 4, 'text/plain'),
            ],
        ]);

        $issue = Ticket::where('type', Ticket::TYPE_ISSUE)->firstOrFail();
        $issueResponse->assertRedirect(route('tickets.show', $issue))
            ->assertSessionHas('success', "Ticket {$issue->ticket_number} created successfully.");
        $this->assertSame(Ticket::STATUS_OPEN, $issue->status);
        $this->assertNull($issue->approval_status);

        // Engineering requests submitted by standard user, engineer, and non-director superadmin enter pending_approval
        foreach ([
            ['actor' => $standardUser, 'title' => 'User Request Export', 'expected_redirect' => 'show'],
            ['actor' => $engineer, 'title' => 'Engineer Request Tooling', 'expected_redirect' => 'index'],
            ['actor' => $nonDirectorSuperadmin, 'title' => 'Admin Request Automation', 'expected_redirect' => 'show'],
        ] as $case) {
            /** @var User $actor */
            $actor = $case['actor'];

            $response = $this->actingAs($actor)->post(route('tickets.store'), $this->engineeringRequestPayload($system->id, [
                'title' => $case['title'],
            ]));

            $ticket = Ticket::where('title', $case['title'])->firstOrFail();

            $expectedRedirect = $case['expected_redirect'] === 'show'
                ? route('tickets.show', $ticket)
                : route('tickets.index');

            $response->assertRedirect($expectedRedirect)
                ->assertSessionHas('success', "Ticket {$ticket->ticket_number} submitted and is pending director approval.");

            $this->assertSame(Ticket::STATUS_PENDING_APPROVAL, $ticket->status);
            $this->assertSame(Ticket::APPROVAL_STATUS_PENDING, $ticket->approval_status);
            $this->assertNull($ticket->approval_decided_by_id);
            $this->assertNull($ticket->approval_decided_at);

            $expectedNotificationTitle = "Approval Required: {$ticket->ticket_number}";

            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $userDirector->id,
                'ticket_id' => $ticket->id,
                'title' => $expectedNotificationTitle,
            ]);
            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $superadminDirector->id,
                'ticket_id' => $ticket->id,
                'title' => $expectedNotificationTitle,
            ]);
            $this->assertDatabaseMissing('user_notifications', [
                'user_id' => $nonDirectorSuperadmin->id,
                'ticket_id' => $ticket->id,
                'title' => $expectedNotificationTitle,
            ]);
            $this->assertDatabaseMissing('user_notifications', [
                'user_id' => $inactiveDirector->id,
                'ticket_id' => $ticket->id,
            ]);
        }

        // Fallback: when NO other active director exists, notification falls back to active superadmins
        $userDirector->update(['is_director' => false]);
        $superadminDirector->update(['is_director' => false]);

        $fallbackResponse = $this->actingAs($standardUser)->post(route('tickets.store'), $this->engineeringRequestPayload($system->id, [
            'title' => 'Fallback Notification Request',
        ]));

        $fallbackTicket = Ticket::where('title', 'Fallback Notification Request')->firstOrFail();
        $fallbackResponse->assertRedirect(route('tickets.show', $fallbackTicket))
            ->assertSessionHas('success', "Ticket {$fallbackTicket->ticket_number} submitted and is pending director approval.");

        $fallbackTitle = "Approval Required: {$fallbackTicket->ticket_number}";
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $nonDirectorSuperadmin->id,
            'ticket_id' => $fallbackTicket->id,
            'title' => $fallbackTitle,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $superadminDirector->id,
            'ticket_id' => $fallbackTicket->id,
            'title' => $fallbackTitle,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $userDirector->id,
            'ticket_id' => $fallbackTicket->id,
            'title' => $fallbackTitle,
        ]);
    }

    public function test_director_submitting_engineering_request_is_auto_approved_into_engineering_queue(): void
    {
        $system = InternalSystem::create([
            'name' => 'CRM Core',
            'category' => 'Sales',
            'is_active' => true,
        ]);

        $userDirector = User::factory()->director()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $superadminDirector = User::factory()->director()->create(['role' => User::ROLE_SUPERADMIN, 'is_active' => true]);
        $otherSuperadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN, 'is_director' => false, 'is_active' => true]);

        foreach ([$userDirector, $superadminDirector] as $index => $director) {
            $title = "Director Auto-Approved Request {$index}";

            $response = $this->actingAs($director)->post(route('tickets.store'), $this->engineeringRequestPayload($system->id, [
                'title' => $title,
            ]));

            $ticket = Ticket::where('title', $title)->firstOrFail();

            $response->assertRedirect(route('tickets.show', $ticket))
                ->assertSessionHas('success', "Ticket {$ticket->ticket_number} created and auto-approved for the engineering queue.");

            $this->assertSame(Ticket::STATUS_OPEN, $ticket->status);
            $this->assertSame(Ticket::APPROVAL_STATUS_APPROVED, $ticket->approval_status);
            $this->assertSame($director->id, $ticket->approval_decided_by_id);
            $this->assertNotNull($ticket->approval_decided_at);

            $this->assertDatabaseHas('ticket_activities', [
                'ticket_id' => $ticket->id,
                'user_id' => $director->id,
                'action_type' => 'request_auto_approved',
            ]);

            $expectedNotificationTitle = "New Approved Request: {$ticket->ticket_number}";

            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $otherSuperadmin->id,
                'ticket_id' => $ticket->id,
                'title' => $expectedNotificationTitle,
            ]);

            if ($director->id !== $superadminDirector->id) {
                $this->assertDatabaseHas('user_notifications', [
                    'user_id' => $superadminDirector->id,
                    'ticket_id' => $ticket->id,
                    'title' => $expectedNotificationTitle,
                ]);
            } else {
                $this->assertDatabaseMissing('user_notifications', [
                    'user_id' => $superadminDirector->id,
                    'ticket_id' => $ticket->id,
                    'title' => $expectedNotificationTitle,
                ]);
            }
        }
    }

    public function test_pending_approval_engineering_requests_are_blocked_from_kanban_assignment_workflow_and_subtasks(): void
    {
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN, 'is_active' => true]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER, 'is_active' => true]);
        $requester = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);

        $this->actingAs($requester)->post(route('tickets.store'), $this->engineeringRequestPayload(null, [
            'title' => 'Pending Request A',
        ]))->assertRedirect();

        $pendingTicket = Ticket::where('title', 'Pending Request A')->firstOrFail();
        $this->assertSame(Ticket::STATUS_PENDING_APPROVAL, $pendingTicket->status);

        // Kanban must not contain the pending ticket in any column
        $this->actingAs($superadmin)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Kanban')
                ->where('columns', function (Collection $columns) use ($pendingTicket): bool {
                    foreach ($columns as $columnTickets) {
                        foreach ($columnTickets as $item) {
                            if ((int) ($item['id'] ?? 0) === $pendingTicket->id) {
                                return false;
                            }
                        }
                    }

                    return true;
                })
            );

        // Assignment blocked while pending_approval
        $this->actingAs($superadmin)
            ->patch(route('tickets.assignment.update', $pendingTicket), [
                'lead_engineer_id' => $engineer->id,
            ])
            ->assertSessionHasErrors('lead_engineer_id')
            ->assertSessionHas('error');

        $pendingTicket->refresh();
        $this->assertNull($pendingTicket->lead_engineer_id);

        // Workflow transitions to operational/rejected statuses blocked while pending_approval
        foreach ([
            Ticket::STATUS_OPEN,
            Ticket::STATUS_UNDER_REVIEW,
            Ticket::STATUS_IN_PROGRESS,
            Ticket::STATUS_RESOLVED,
            Ticket::STATUS_REJECTED,
        ] as $blockedStatus) {
            $this->actingAs($superadmin)
                ->patch(route('tickets.workflow.update', $pendingTicket), [
                    'status' => $blockedStatus,
                ])
                ->assertSessionHasErrors('status')
                ->assertSessionHas('error');

            $pendingTicket->refresh();
            $this->assertSame(Ticket::STATUS_PENDING_APPROVAL, $pendingTicket->status);
        }

        // Superadmin can still cancel a pending_approval request via workflow
        $this->actingAs($superadmin)
            ->patch(route('tickets.workflow.update', $pendingTicket), [
                'status' => Ticket::STATUS_CANCELLED,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Ticket workflow updated.');

        $pendingTicket->refresh();
        $this->assertSame(Ticket::STATUS_CANCELLED, $pendingTicket->status);

        // On another pending request, superadmin calling POST /tickets/{ticket}/subtasks gets 403
        $secondPending = Ticket::create([
            'ticket_number' => 'REQ-2026-0998',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $requester->id,
            'title' => 'Pending Request B',
            'description' => 'Pending approval subtask check.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_PENDING_APPROVAL,
            'approval_status' => Ticket::APPROVAL_STATUS_PENDING,
        ]);

        $this->actingAs($superadmin)
            ->post(route('tickets.subtasks.store', $secondPending), [
                'title' => 'Blocked subtask',
            ])
            ->assertForbidden();

        // Attempting to move an open ticket back to pending_approval fails validation
        $openTicket = Ticket::create([
            'ticket_number' => 'REQ-2026-0999',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $requester->id,
            'title' => 'Already Open Request',
            'description' => 'Open request.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
            'approval_status' => Ticket::APPROVAL_STATUS_APPROVED,
        ]);

        $this->actingAs($superadmin)
            ->patch(route('tickets.workflow.update', $openTicket), [
                'status' => Ticket::STATUS_PENDING_APPROVAL,
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_user_role_director_visibility_and_isolation(): void
    {
        $director = User::factory()->director()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $otherUser = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $leadEngineer = User::factory()->create(['role' => User::ROLE_ENGINEER, 'is_active' => true]);

        $pendingRequest = Ticket::create([
            'ticket_number' => 'REQ-2026-1001',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $otherUser->id,
            'title' => 'Pending Engineering Request',
            'description' => 'Awaiting decision.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_PENDING_APPROVAL,
            'approval_status' => Ticket::APPROVAL_STATUS_PENDING,
        ]);

        $approvedRequest = Ticket::create([
            'ticket_number' => 'REQ-2026-1002',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $otherUser->id,
            'lead_engineer_id' => $leadEngineer->id,
            'title' => 'Approved Engineering Request',
            'description' => 'Already approved.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'approval_status' => Ticket::APPROVAL_STATUS_APPROVED,
        ]);

        TicketComment::create([
            'ticket_id' => $approvedRequest->id,
            'user_id' => $leadEngineer->id,
            'body' => 'Public update for requester and director.',
            'is_internal' => false,
        ]);
        TicketComment::create([
            'ticket_id' => $approvedRequest->id,
            'user_id' => $leadEngineer->id,
            'body' => 'Internal staff engineering note.',
            'is_internal' => true,
        ]);
        TicketAttachment::create([
            'ticket_id' => $approvedRequest->id,
            'uploaded_by_id' => $otherUser->id,
            'original_name' => 'public-spec.pdf',
            'file_path' => 'tickets/public-spec.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 128,
            'is_internal' => false,
        ]);
        TicketAttachment::create([
            'ticket_id' => $approvedRequest->id,
            'uploaded_by_id' => $leadEngineer->id,
            'original_name' => 'internal-trace.log',
            'file_path' => 'tickets/internal-trace.log',
            'mime_type' => 'text/plain',
            'file_size' => 64,
            'is_internal' => true,
        ]);

        $rejectedRequest = Ticket::create([
            'ticket_number' => 'REQ-2026-1003',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $otherUser->id,
            'title' => 'Rejected Engineering Request',
            'description' => 'Declined.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_REJECTED,
            'approval_status' => Ticket::APPROVAL_STATUS_REJECTED,
        ]);

        $privateIssue = Ticket::create([
            'ticket_number' => 'ISS-2026-1004',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $otherUser->id,
            'title' => 'Other User Private Issue',
            'description' => 'Must not be visible to user-role director.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
        ]);

        // Index shows all 3 engineering requests and excludes other user's private issue
        $this->actingAs($director)
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tickets/Index')
                ->where('tickets.total', 3)
                ->where('tickets.data', function ($rows) use ($pendingRequest, $approvedRequest, $rejectedRequest, $privateIssue): bool {
                    $ids = collect($rows)->pluck('id')->all();

                    return in_array($pendingRequest->id, $ids, true)
                        && in_array($approvedRequest->id, $ids, true)
                        && in_array($rejectedRequest->id, $ids, true)
                        && ! in_array($privateIssue->id, $ids, true);
                })
            );

        // Director can view pending request and receives can_approve_request = true
        $this->actingAs($director)
            ->get(route('tickets.show', $pendingRequest))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_approve_request', true)
                ->where('permissions.can_post_internal_note', false)
                ->where('permissions.can_assign', false)
                ->where('permissions.can_manage_workflow', false)
            );

        // Director can view approved request without internal comments/attachments and with can_approve_request = false
        $this->actingAs($director)
            ->get(route('tickets.show', $approvedRequest))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_approve_request', false)
                ->has('ticket.comments', 1)
                ->where('ticket.comments.0.is_internal', false)
                ->has('ticket.attachments', 1)
                ->where('ticket.attachments.0.is_internal', false)
            );

        // Director can view rejected request
        $this->actingAs($director)
            ->get(route('tickets.show', $rejectedRequest))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_approve_request', false)
            );

        // Director gets 403 on another user's private issue
        $this->actingAs($director)
            ->get(route('tickets.show', $privateIssue))
            ->assertForbidden();

        // Attempting to post an internal note as user-role director forces is_internal = false
        $this->actingAs($director)
            ->post(route('tickets.comments.store', $approvedRequest), [
                'body' => 'Director review comment.',
                'is_internal' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Comment posted.');

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $approvedRequest->id,
            'user_id' => $director->id,
            'body' => 'Director review comment.',
            'is_internal' => false,
        ]);

        // Director cannot access staff-only assignment or kanban endpoints
        $this->actingAs($director)
            ->patch(route('tickets.assignment.update', $approvedRequest), [
                'lead_engineer_id' => $leadEngineer->id,
            ])
            ->assertForbidden();

        $this->actingAs($director)
            ->get(route('tickets.kanban'))
            ->assertForbidden();
    }

    public function test_director_and_superadmin_fallback_can_approve_and_unlock_engineering_queue(): void
    {
        $director = User::factory()->director()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $superadmin = User::factory()->create(['role' => User::ROLE_SUPERADMIN, 'is_director' => false, 'is_active' => true]);
        $engineer = User::factory()->create(['role' => User::ROLE_ENGINEER, 'is_active' => true]);
        $standardUser = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $requester = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);

        $pendingTicket = Ticket::create([
            'ticket_number' => 'REQ-2026-2001',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $requester->id,
            'title' => 'Automated Invoice Reconciliation',
            'description' => 'Build scheduled reconciliation pipeline.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_PENDING_APPROVAL,
            'approval_status' => Ticket::APPROVAL_STATUS_PENDING,
        ]);

        // Non-director user and engineer receive 403
        $this->actingAs($standardUser)
            ->patch(route('tickets.approval.decide', $pendingTicket), ['decision' => 'approve'])
            ->assertForbidden();

        $this->actingAs($engineer)
            ->patch(route('tickets.approval.decide', $pendingTicket), ['decision' => 'approve'])
            ->assertForbidden();

        // Active user-role director approves with optional notes
        $this->actingAs($director)
            ->patch(route('tickets.approval.decide', $pendingTicket), [
                'decision' => 'approve',
                'decision_notes' => 'Approved for Q3 sprint capacity.',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Engineering request {$pendingTicket->ticket_number} approved and moved to the engineering queue.");

        $pendingTicket->refresh();
        $this->assertSame(Ticket::STATUS_OPEN, $pendingTicket->status);
        $this->assertSame(Ticket::APPROVAL_STATUS_APPROVED, $pendingTicket->approval_status);
        $this->assertSame($director->id, $pendingTicket->approval_decided_by_id);
        $this->assertNotNull($pendingTicket->approval_decided_at);
        $this->assertSame('Approved for Q3 sprint capacity.', $pendingTicket->review_decision_notes);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $pendingTicket->id,
            'user_id' => $director->id,
            'action_type' => 'request_approved',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $superadmin->id,
            'ticket_id' => $pendingTicket->id,
            'title' => "Approved Request Ready for Triage: {$pendingTicket->ticket_number}",
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $pendingTicket->id,
            'title' => "Request Approved: {$pendingTicket->ticket_number}",
        ]);

        // Ticket now appears on Kanban board
        $this->actingAs($superadmin)
            ->get(route('tickets.kanban'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.open', fn ($rows) => collect($rows)->contains('id', $pendingTicket->id))
            );

        // Superadmin can now assign a lead engineer
        $this->actingAs($superadmin)
            ->patch(route('tickets.assignment.update', $pendingTicket), [
                'lead_engineer_id' => $engineer->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Ticket assignment updated.');

        $pendingTicket->refresh();
        $this->assertSame($engineer->id, $pendingTicket->lead_engineer_id);

        // Calling approval again on already-approved ticket fails validation
        $this->actingAs($director)
            ->patch(route('tickets.approval.decide', $pendingTicket), [
                'decision' => 'approve',
            ])
            ->assertSessionHasErrors('decision');

        // Calling approval on an issue ticket fails validation
        $issueTicket = Ticket::create([
            'ticket_number' => 'ISS-2026-2002',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Production Login Issue',
            'description' => 'Users cannot log in.',
            'priority' => Ticket::PRIORITY_CRITICAL,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($director)
            ->patch(route('tickets.approval.decide', $issueTicket), [
                'decision' => 'approve',
            ])
            ->assertSessionHasErrors('decision');

        // Non-director superadmin can approve a pending request as fallback and logs (override)
        $fallbackPending = Ticket::create([
            'ticket_number' => 'REQ-2026-2003',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $requester->id,
            'title' => 'Urgent Compliance Report',
            'description' => 'Needed for audit.',
            'priority' => Ticket::PRIORITY_HIGH,
            'status' => Ticket::STATUS_PENDING_APPROVAL,
            'approval_status' => Ticket::APPROVAL_STATUS_PENDING,
        ]);

        $this->actingAs($superadmin)
            ->patch(route('tickets.approval.decide', $fallbackPending), [
                'decision' => 'approve',
            ])
            ->assertSessionHasNoErrors();

        $fallbackPending->refresh();
        $this->assertSame(Ticket::STATUS_OPEN, $fallbackPending->status);
        $this->assertSame(Ticket::APPROVAL_STATUS_APPROVED, $fallbackPending->approval_status);

        $overrideActivity = TicketActivity::where('ticket_id', $fallbackPending->id)
            ->where('action_type', 'request_approved')
            ->firstOrFail();
        $this->assertStringContainsString('(override)', $overrideActivity->description);
    }

    public function test_director_rejection_requires_reason_and_requester_can_cancel_pending_request(): void
    {
        $director = User::factory()->director()->create(['role' => User::ROLE_USER, 'is_active' => true]);
        $requester = User::factory()->create(['role' => User::ROLE_USER, 'is_active' => true]);

        $pendingTicket = Ticket::create([
            'ticket_number' => 'REQ-2026-3001',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $requester->id,
            'title' => 'Custom BI Connector',
            'description' => 'Build custom BI sync.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_PENDING_APPROVAL,
            'approval_status' => Ticket::APPROVAL_STATUS_PENDING,
        ]);

        // Rejecting without decision_notes or with whitespace-only fails validation
        foreach ([null, '   '] as $invalidNotes) {
            $this->actingAs($director)
                ->patch(route('tickets.approval.decide', $pendingTicket), [
                    'decision' => 'reject',
                    'decision_notes' => $invalidNotes,
                ])
                ->assertSessionHasErrors('decision_notes')
                ->assertSessionHas('error');

            $pendingTicket->refresh();
            $this->assertSame(Ticket::STATUS_PENDING_APPROVAL, $pendingTicket->status);
        }

        // Rejecting with valid reason moves status to rejected
        $this->actingAs($director)
            ->patch(route('tickets.approval.decide', $pendingTicket), [
                'decision' => 'reject',
                'decision_notes' => 'Duplicate of existing BI integration project.',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Engineering request {$pendingTicket->ticket_number} rejected.");

        $pendingTicket->refresh();
        $this->assertSame(Ticket::STATUS_REJECTED, $pendingTicket->status);
        $this->assertSame(Ticket::APPROVAL_STATUS_REJECTED, $pendingTicket->approval_status);
        $this->assertSame($director->id, $pendingTicket->approval_decided_by_id);
        $this->assertNotNull($pendingTicket->approval_decided_at);
        $this->assertSame('Duplicate of existing BI integration project.', $pendingTicket->review_decision_notes);

        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $pendingTicket->id,
            'user_id' => $director->id,
            'action_type' => 'request_rejected',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $requester->id,
            'ticket_id' => $pendingTicket->id,
            'title' => "Request Rejected: {$pendingTicket->ticket_number}",
        ]);

        // Requester cancelling their own pending_approval request succeeds, and subsequent approval fails validation
        $cancellableTicket = Ticket::create([
            'ticket_number' => 'REQ-2026-3002',
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'requester_id' => $requester->id,
            'title' => 'Withdrawn Automation Request',
            'description' => 'No longer needed.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_PENDING_APPROVAL,
            'approval_status' => Ticket::APPROVAL_STATUS_PENDING,
        ]);

        $this->actingAs($requester)
            ->patch(route('tickets.workflow.update', $cancellableTicket), [
                'action' => 'cancel',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Ticket cancelled.');

        $cancellableTicket->refresh();
        $this->assertSame(Ticket::STATUS_CANCELLED, $cancellableTicket->status);

        $this->actingAs($director)
            ->patch(route('tickets.approval.decide', $cancellableTicket), [
                'decision' => 'approve',
            ])
            ->assertSessionHasErrors('decision');
    }

    public function test_admin_users_can_manage_director_flag_with_engineer_guard_and_filter(): void
    {
        $superadmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_director' => false,
            'is_active' => true,
        ]);

        // Create user with is_director = true
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Finance Director',
                'username' => 'fin_director',
                'email' => 'fin.director@calltek.local',
                'password' => 'secret123',
                'role' => User::ROLE_USER,
                'is_director' => true,
                'department' => 'Finance',
                'job_title' => 'VP Finance',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $userDirector = User::where('username', 'fin_director')->firstOrFail();
        $this->assertTrue($userDirector->is_director);

        // Create superadmin with is_director = true
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'IT Director Admin',
                'username' => 'it_director_admin',
                'email' => 'it.director@calltek.local',
                'password' => 'secret123',
                'role' => User::ROLE_SUPERADMIN,
                'is_director' => true,
                'department' => 'Engineering',
                'job_title' => 'Engineering Director',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $superadminDirector = User::where('username', 'it_director_admin')->firstOrFail();
        $this->assertTrue($superadminDirector->is_director);

        // Creating an engineer with is_director = true fails validation
        $this->actingAs($superadmin)
            ->post(route('admin.users.store'), [
                'name' => 'Engineer Blocked Director',
                'username' => 'eng_blocked',
                'email' => 'eng.blocked@calltek.local',
                'password' => 'secret123',
                'role' => User::ROLE_ENGINEER,
                'is_director' => true,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('is_director');

        // Updating an engineer with is_director = true fails validation
        $engineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_director' => false,
            'is_active' => true,
        ]);

        $this->actingAs($superadmin)
            ->put(route('admin.users.update', $engineer), [
                'name' => $engineer->name,
                'username' => $engineer->username,
                'email' => $engineer->email,
                'role' => User::ROLE_ENGINEER,
                'is_director' => true,
                'is_active' => true,
            ])
            ->assertSessionHasErrors('is_director');

        $engineer->refresh();
        $this->assertFalse($engineer->is_director);

        // GET /admin/users?is_director=1 filters properly to directors only
        $this->actingAs($superadmin)
            ->get(route('admin.users.index', ['is_director' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Index')
                ->where('users.total', 2)
                ->where('users.data', function ($rows) use ($userDirector, $superadminDirector): bool {
                    $ids = collect($rows)->pluck('id')->all();

                    return count($ids) === 2
                        && in_array($userDirector->id, $ids, true)
                        && in_array($superadminDirector->id, $ids, true)
                        && collect($rows)->every(fn ($row) => (bool) ($row['is_director'] ?? false) === true);
                })
            );

        // Updating is_active with is_director: true preserves is_director === true
        $this->actingAs($superadmin)
            ->put(route('admin.users.update', $userDirector), [
                'name' => $userDirector->name,
                'username' => $userDirector->username,
                'email' => $userDirector->email,
                'role' => $userDirector->role,
                'is_director' => true,
                'department' => $userDirector->department,
                'job_title' => $userDirector->job_title,
                'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        $userDirector->refresh();
        $this->assertFalse($userDirector->is_active);
        $this->assertTrue($userDirector->is_director);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function engineeringRequestPayload(?int $systemId, array $overrides = []): array
    {
        return array_merge([
            'type' => Ticket::TYPE_ENGINEERING_REQUEST,
            'internal_system_id' => $systemId,
            'title' => 'Automated Weekly Reconciliation',
            'description' => 'Need scheduled report generation.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'business_justification' => 'Eliminates manual spreadsheet work.',
            'proposed_functionality' => 'Background job exporting CSV.',
            'expected_benefits' => 'Saves 4 hours per week.',
            'requested_deadline' => now()->addDays(10)->toDateString(),
            'attachments' => [
                UploadedFile::fake()->create('spec.pdf', 12, 'application/pdf'),
            ],
        ], $overrides);
    }
}
