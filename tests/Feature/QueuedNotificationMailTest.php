<?php

namespace Tests\Feature;

use App\Mail\TicketNotificationMail;
use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QueuedNotificationMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
        Mail::fake();
    }

    public function test_ticket_notification_mail_implements_should_queue_with_retry_and_after_commit(): void
    {
        $recipient = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $ticket = $this->createTicket($recipient);

        $notification = UserNotification::create([
            'user_id' => $recipient->id,
            'ticket_id' => $ticket->id,
            'title' => "New Ticket: {$ticket->ticket_number}",
            'message' => 'A ticket notification was queued.',
            'action_url' => "/tickets/{$ticket->id}",
            'is_read' => false,
        ]);

        $mail = new TicketNotificationMail($notification, $ticket);

        $this->assertInstanceOf(ShouldQueue::class, $mail);
        $this->assertSame(3, $mail->tries);
        $this->assertSame([30, 120, 600], $mail->backoff);
        $this->assertTrue($mail->afterCommit);
    }

    public function test_ticket_creation_queues_notification_emails_and_sends_nothing_synchronously(): void
    {
        Storage::fake((string) config('filesystems.attachments_disk'));

        $actingSuperAdmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
            'email' => 'acting-admin@calltek.local',
        ]);

        $otherSuperAdmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
            'email' => 'other-admin@calltek.local',
        ]);

        $inactiveSuperAdmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => false,
            'email' => 'inactive-admin@calltek.local',
        ]);

        $system = InternalSystem::create([
            'name' => 'Billing Portal',
            'category' => 'Core Systems',
            'is_active' => true,
        ]);

        $this->actingAs($actingSuperAdmin)
            ->post(route('tickets.store'), [
                'type' => Ticket::TYPE_ISSUE,
                'internal_system_id' => $system->id,
                'title' => 'Queued notification test issue',
                'description' => 'Verifying ticket creation queues notification emails.',
                'priority' => Ticket::PRIORITY_HIGH,
                'environment' => 'production',
                'impact_scope' => 'department',
                'attachments' => [
                    UploadedFile::fake()->create('evidence.log', 8, 'text/plain'),
                ],
            ])
            ->assertRedirect();

        Mail::assertNothingSent();
        Mail::assertQueued(TicketNotificationMail::class, 1);
        Mail::assertQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($otherSuperAdmin->email)
        );
        Mail::assertNotQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($actingSuperAdmin->email)
        );
        Mail::assertNotQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($inactiveSuperAdmin->email)
        );
    }

    public function test_comment_posting_queues_notification_emails_and_excludes_inactive_and_acting_users(): void
    {
        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email' => 'requester@calltek.local',
        ]);

        $leadEngineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
            'email' => 'lead@calltek.local',
        ]);

        $activeCollaborator = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
            'email' => 'active-collab@calltek.local',
        ]);

        $inactiveCollaborator = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => false,
            'email' => 'inactive-collab@calltek.local',
        ]);

        $ticket = $this->createTicket($requester, $leadEngineer);
        $ticket->collaborators()->attach([
            $activeCollaborator->id => ['added_by_id' => $leadEngineer->id],
            $inactiveCollaborator->id => ['added_by_id' => $leadEngineer->id],
        ]);

        $this->actingAs($requester)
            ->post(route('tickets.comments.store', $ticket), [
                'body' => 'Please review the attached details.',
            ])
            ->assertRedirect();

        Mail::assertNothingSent();
        Mail::assertQueued(TicketNotificationMail::class, 2);
        Mail::assertQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($leadEngineer->email)
        );
        Mail::assertQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($activeCollaborator->email)
        );
        Mail::assertNotQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($requester->email)
        );
        Mail::assertNotQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($inactiveCollaborator->email)
        );
    }

    public function test_collaborator_assignment_queues_notification_emails_and_excludes_inactive_and_acting_users(): void
    {
        $actingSuperAdmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
            'email' => 'superadmin@calltek.local',
        ]);

        $inactiveRequester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => false,
            'email' => 'inactive-requester@calltek.local',
        ]);

        $leadEngineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
            'email' => 'assigned-lead@calltek.local',
        ]);

        $collaboratorEngineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
            'email' => 'assigned-collab@calltek.local',
        ]);

        $ticket = $this->createTicket($inactiveRequester, $leadEngineer);

        $this->actingAs($actingSuperAdmin)
            ->patch(route('tickets.assignment.update', $ticket), [
                'lead_engineer_id' => $leadEngineer->id,
                'collaborator_ids' => [$collaboratorEngineer->id],
            ])
            ->assertRedirect();

        Mail::assertNothingSent();
        Mail::assertQueued(TicketNotificationMail::class, 1);
        Mail::assertQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($collaboratorEngineer->email)
        );
        Mail::assertNotQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($actingSuperAdmin->email)
        );
        Mail::assertNotQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($inactiveRequester->email)
        );
    }

    public function test_notification_service_excludes_inactive_and_acting_users_when_queueing(): void
    {
        $actingUser = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
            'email' => 'actor@calltek.local',
        ]);

        $inactiveUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => false,
            'email' => 'inactive@calltek.local',
        ]);

        $activeRecipient = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email' => 'active@calltek.local',
        ]);

        $ticket = $this->createTicket($activeRecipient);

        app(NotificationService::class)->notifyUsers(
            [$actingUser, $inactiveUser, $activeRecipient],
            $ticket,
            "Update on {$ticket->ticket_number}",
            'Only active non-acting recipients should be queued.',
            "/tickets/{$ticket->id}",
            $actingUser->id
        );

        Mail::assertNothingSent();
        Mail::assertQueued(TicketNotificationMail::class, 1);
        Mail::assertQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($activeRecipient->email)
        );
        Mail::assertNotQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($actingUser->email)
        );
        Mail::assertNotQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($inactiveUser->email)
        );
    }

    private function createTicket(User $requester, ?User $leadEngineer = null): Ticket
    {
        return Ticket::create([
            'ticket_number' => 'ISS-2026-'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT),
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => null,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $leadEngineer?->id,
            'title' => 'Sample Ticket for Notification Tests',
            'description' => 'Sample ticket description.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
            'environment' => 'production',
            'impact_scope' => 'single_user',
        ]);
    }
}
