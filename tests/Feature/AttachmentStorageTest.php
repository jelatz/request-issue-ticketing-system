<?php

namespace Tests\Feature;

use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_attachments_upload_to_configured_private_disk_and_can_be_downloaded(): void
    {
        $this->assertSame('local', config('filesystems.attachments_disk'));

        Storage::fake('local');
        Storage::fake('public');

        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $engineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $system = InternalSystem::create([
            'name' => 'Billing Core',
            'category' => 'Core Systems',
            'is_active' => true,
        ]);

        $this->actingAs($requester)
            ->post(route('tickets.store'), [
                'type' => Ticket::TYPE_ISSUE,
                'internal_system_id' => $system->id,
                'title' => 'Private storage ticket',
                'description' => 'Testing configurable private attachment storage.',
                'priority' => Ticket::PRIORITY_HIGH,
                'environment' => 'production',
                'impact_scope' => 'department',
                'attachments' => [
                    UploadedFile::fake()->createWithContent('ticket-log.txt', 'private-ticket-bytes'),
                ],
            ])
            ->assertRedirect();

        $ticket = Ticket::query()->firstOrFail();
        $ticket->update(['lead_engineer_id' => $engineer->id]);

        $ticketAttachment = $ticket->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($ticketAttachment->file_path);
        Storage::disk('public')->assertMissing($ticketAttachment->file_path);

        $this->actingAs($engineer)
            ->post(route('tickets.comments.store', $ticket), [
                'body' => 'Adding diagnostic attachment on private disk.',
                'is_internal' => false,
                'attachments' => [
                    UploadedFile::fake()->createWithContent('comment-note.txt', 'private-comment-bytes'),
                ],
            ])
            ->assertRedirect();

        $commentAttachment = TicketAttachment::query()
            ->whereNotNull('ticket_comment_id')
            ->firstOrFail();

        Storage::disk('local')->assertExists($commentAttachment->file_path);
        Storage::disk('public')->assertMissing($commentAttachment->file_path);

        $this->actingAs($requester)
            ->get(route('attachments.download', $ticketAttachment))
            ->assertOk();

        $this->actingAs($requester)
            ->get(route('attachments.download', $commentAttachment))
            ->assertOk();

        $this->actingAs($engineer)
            ->get(route('attachments.download', $commentAttachment))
            ->assertOk();
    }

    public function test_unauthorized_users_and_requesters_on_internal_attachments_receive_forbidden(): void
    {
        Storage::fake('local');

        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $otherUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $assignedEngineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $unassignedEngineer = User::factory()->create([
            'role' => User::ROLE_ENGINEER,
            'is_active' => true,
        ]);

        $superadmin = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0901',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'lead_engineer_id' => $assignedEngineer->id,
            'title' => 'Private download authorization',
            'description' => 'Verify download authorization checks.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        Storage::disk('local')->put("tickets/{$ticket->id}/public.txt", 'public-attachment');
        $publicAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_comment_id' => null,
            'uploaded_by_id' => $requester->id,
            'original_name' => 'public.txt',
            'file_path' => "tickets/{$ticket->id}/public.txt",
            'mime_type' => 'text/plain',
            'file_size' => 17,
            'is_internal' => false,
        ]);

        $internalComment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $assignedEngineer->id,
            'body' => 'Internal staff-only investigation.',
            'is_internal' => true,
        ]);

        Storage::disk('local')->put("tickets/{$ticket->id}/comments/{$internalComment->id}/internal.log", 'internal-secret');
        $internalAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'ticket_comment_id' => $internalComment->id,
            'uploaded_by_id' => $assignedEngineer->id,
            'original_name' => 'internal.log',
            'file_path' => "tickets/{$ticket->id}/comments/{$internalComment->id}/internal.log",
            'mime_type' => 'text/plain',
            'file_size' => 15,
            'is_internal' => true,
        ]);

        $this->actingAs($otherUser)
            ->get(route('attachments.download', $publicAttachment))
            ->assertForbidden();

        $this->actingAs($unassignedEngineer)
            ->get(route('attachments.download', $publicAttachment))
            ->assertForbidden();

        $this->actingAs($requester)
            ->get(route('attachments.download', $internalAttachment))
            ->assertForbidden();

        $this->actingAs($assignedEngineer)
            ->get(route('attachments.download', $internalAttachment))
            ->assertOk();

        $this->actingAs($superadmin)
            ->get(route('attachments.download', $internalAttachment))
            ->assertOk();
    }

    public function test_migrate_attachments_disk_command_copies_skips_reports_missing_and_deletes_only_when_requested(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $requester = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'ISS-2026-0902',
            'type' => Ticket::TYPE_ISSUE,
            'requester_id' => $requester->id,
            'title' => 'Attachment migration command test',
            'description' => 'Verify disk migration command behavior.',
            'priority' => Ticket::PRIORITY_LOW,
            'status' => Ticket::STATUS_OPEN,
        ]);

        Storage::disk('public')->put("tickets/{$ticket->id}/one.txt", 'content-one');
        Storage::disk('public')->put("tickets/{$ticket->id}/two.txt", 'old-public-content');
        Storage::disk('local')->put("tickets/{$ticket->id}/two.txt", 'existing-local-content');

        TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'uploaded_by_id' => $requester->id,
            'original_name' => 'one.txt',
            'file_path' => "tickets/{$ticket->id}/one.txt",
            'mime_type' => 'text/plain',
            'file_size' => 11,
            'is_internal' => false,
        ]);

        TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'uploaded_by_id' => $requester->id,
            'original_name' => 'two.txt',
            'file_path' => "tickets/{$ticket->id}/two.txt",
            'mime_type' => 'text/plain',
            'file_size' => 18,
            'is_internal' => false,
        ]);

        $missingAttachment = TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'uploaded_by_id' => $requester->id,
            'original_name' => 'missing.txt',
            'file_path' => "tickets/{$ticket->id}/missing.txt",
            'mime_type' => 'text/plain',
            'file_size' => 0,
            'is_internal' => false,
        ]);

        $this->artisan('attachments:migrate-disk', ['from' => 'public', 'to' => 'local'])
            ->expectsOutputToContain("Missing source file for attachment #{$missingAttachment->id}: {$missingAttachment->file_path}")
            ->expectsOutputToContain('Attachment migration complete. Copied: 1, Skipped: 1, Missing: 1.')
            ->assertExitCode(0);

        $this->assertSame('content-one', Storage::disk('local')->get("tickets/{$ticket->id}/one.txt"));
        $this->assertSame('existing-local-content', Storage::disk('local')->get("tickets/{$ticket->id}/two.txt"));
        Storage::disk('public')->assertExists("tickets/{$ticket->id}/one.txt");
        Storage::disk('public')->assertExists("tickets/{$ticket->id}/two.txt");

        Storage::disk('public')->put("tickets/{$ticket->id}/four.txt", 'content-four');
        TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'uploaded_by_id' => $requester->id,
            'original_name' => 'four.txt',
            'file_path' => "tickets/{$ticket->id}/four.txt",
            'mime_type' => 'text/plain',
            'file_size' => 12,
            'is_internal' => false,
        ]);

        $this->artisan('attachments:migrate-disk', [
            'from' => 'public',
            'to' => 'local',
            '--delete-source' => true,
        ])
            ->expectsOutputToContain('Attachment migration complete. Copied: 1, Skipped: 2, Missing: 1.')
            ->assertExitCode(0);

        $this->assertSame('content-four', Storage::disk('local')->get("tickets/{$ticket->id}/four.txt"));
        Storage::disk('public')->assertMissing("tickets/{$ticket->id}/four.txt");
        Storage::disk('public')->assertExists("tickets/{$ticket->id}/one.txt");
        Storage::disk('public')->assertExists("tickets/{$ticket->id}/two.txt");
    }

    public function test_migrate_attachments_disk_command_fails_on_invalid_or_identical_disks(): void
    {
        $this->artisan('attachments:migrate-disk', ['from' => 'nonexistent', 'to' => 'local'])
            ->expectsOutputToContain('Source disk [nonexistent] is not configured in filesystems.disks.')
            ->assertExitCode(1);

        $this->artisan('attachments:migrate-disk', ['from' => 'public', 'to' => 'nonexistent'])
            ->expectsOutputToContain('Target disk [nonexistent] is not configured in filesystems.disks.')
            ->assertExitCode(1);

        $this->artisan('attachments:migrate-disk', ['from' => 'local', 'to' => 'local'])
            ->expectsOutputToContain('Source and target disks must be different.')
            ->assertExitCode(1);
    }
}
