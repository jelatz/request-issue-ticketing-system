<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketSequence;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TicketNumberingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sequential_numbering_is_independent_per_ticket_type(): void
    {
        Carbon::setTestNow('2026-05-15 10:00:00');
        $ticketService = app(TicketService::class);

        $this->assertSame('ISS-2026-0001', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));
        $this->assertSame('ISS-2026-0002', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));
        $this->assertSame('REQ-2026-0001', $ticketService->generateTicketNumber(Ticket::TYPE_ENGINEERING_REQUEST));
        $this->assertSame('REQ-2026-0002', $ticketService->generateTicketNumber(Ticket::TYPE_ENGINEERING_REQUEST));
        $this->assertSame('ISS-2026-0003', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));

        $this->assertDatabaseHas('ticket_sequences', [
            'prefix' => 'ISS',
            'year' => 2026,
            'last_number' => 3,
        ]);
        $this->assertDatabaseHas('ticket_sequences', [
            'prefix' => 'REQ',
            'year' => 2026,
            'last_number' => 2,
        ]);
    }

    public function test_year_rollover_resets_sequence_to_0001(): void
    {
        $ticketService = app(TicketService::class);

        try {
            Carbon::setTestNow('2026-12-31 23:59:59');
            $this->assertSame('ISS-2026-0001', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));
            $this->assertSame('ISS-2026-0002', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));
            $this->assertSame('REQ-2026-0001', $ticketService->generateTicketNumber(Ticket::TYPE_ENGINEERING_REQUEST));

            Carbon::setTestNow('2027-01-01 00:00:00');
            $this->assertSame('ISS-2027-0001', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));
            $this->assertSame('REQ-2027-0001', $ticketService->generateTicketNumber(Ticket::TYPE_ENGINEERING_REQUEST));

            Carbon::setTestNow('2026-12-31 23:59:59');
            $this->assertSame('ISS-2026-0003', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cold_start_continues_from_existing_tickets_and_skips_non_numeric_suffixes(): void
    {
        Carbon::setTestNow('2026-08-10 09:00:00');
        $requester = User::factory()->create();

        foreach ([
            ['ticket_number' => 'ISS-2026-0002', 'type' => Ticket::TYPE_ISSUE],
            ['ticket_number' => 'ISS-2026-0007', 'type' => Ticket::TYPE_ISSUE],
            ['ticket_number' => 'ISS-2026-ABCD', 'type' => Ticket::TYPE_ISSUE],
            ['ticket_number' => 'REQ-2026-0003', 'type' => Ticket::TYPE_ENGINEERING_REQUEST],
            ['ticket_number' => 'REQ-2026-XYZ', 'type' => Ticket::TYPE_ENGINEERING_REQUEST],
        ] as $seed) {
            Ticket::create([
                'ticket_number' => $seed['ticket_number'],
                'type' => $seed['type'],
                'requester_id' => $requester->id,
                'title' => "Seed {$seed['ticket_number']}",
                'description' => 'Cold-start sequence test.',
                'priority' => Ticket::PRIORITY_MEDIUM,
                'status' => Ticket::STATUS_OPEN,
            ]);
        }

        $this->assertDatabaseCount('ticket_sequences', 0);

        $ticketService = app(TicketService::class);
        $this->assertSame('ISS-2026-0008', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));
        $this->assertSame('REQ-2026-0004', $ticketService->generateTicketNumber(Ticket::TYPE_ENGINEERING_REQUEST));
        $this->assertSame('ISS-2026-0009', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));
    }

    public function test_migration_backfills_ticket_sequences_and_continues_without_collision(): void
    {
        $requester = User::factory()->create();
        $migration = require database_path('migrations/2026_11_20_003640_create_ticket_sequences_table.php');

        $migration->down();

        foreach ([
            ['ticket_number' => 'ISS-2025-0003', 'type' => Ticket::TYPE_ISSUE],
            ['ticket_number' => 'ISS-2025-0010', 'type' => Ticket::TYPE_ISSUE],
            ['ticket_number' => 'ISS-2025-ABCD', 'type' => Ticket::TYPE_ISSUE],
            ['ticket_number' => 'REQ-2026-0005', 'type' => Ticket::TYPE_ENGINEERING_REQUEST],
            ['ticket_number' => 'INVALID-FORMAT', 'type' => Ticket::TYPE_ISSUE],
        ] as $seed) {
            Ticket::create([
                'ticket_number' => $seed['ticket_number'],
                'type' => $seed['type'],
                'requester_id' => $requester->id,
                'title' => "Migration backfill {$seed['ticket_number']}",
                'description' => 'Migration backfill test.',
                'priority' => Ticket::PRIORITY_MEDIUM,
                'status' => Ticket::STATUS_OPEN,
            ]);
        }

        $migration->up();

        $this->assertDatabaseHas('ticket_sequences', [
            'prefix' => 'ISS',
            'year' => 2025,
            'last_number' => 10,
        ]);
        $this->assertDatabaseHas('ticket_sequences', [
            'prefix' => 'REQ',
            'year' => 2026,
            'last_number' => 5,
        ]);
        $this->assertDatabaseMissing('ticket_sequences', [
            'prefix' => 'INVALID',
        ]);

        $ticketService = app(TicketService::class);

        Carbon::setTestNow('2025-06-01 12:00:00');
        $this->assertSame('ISS-2025-0011', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));

        Carbon::setTestNow('2026-06-01 12:00:00');
        $this->assertSame('REQ-2026-0006', $ticketService->generateTicketNumber(Ticket::TYPE_ENGINEERING_REQUEST));
    }

    public function test_outer_transaction_rollback_does_not_permanently_advance_sequence(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $ticketService = app(TicketService::class);

        $this->assertSame('ISS-2026-0001', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));

        try {
            DB::transaction(function () use ($ticketService): void {
                $generated = $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE);
                $this->assertSame('ISS-2026-0002', $generated);

                throw new RuntimeException('Simulated outer transaction failure.');
            });
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // Expected rollback.
        }

        $this->assertSame(
            1,
            TicketSequence::query()->where('prefix', 'ISS')->where('year', 2026)->value('last_number')
        );
        $this->assertSame('ISS-2026-0002', $ticketService->generateTicketNumber(Ticket::TYPE_ISSUE));

        try {
            DB::transaction(function () use ($ticketService): void {
                $generated = $ticketService->generateTicketNumber(Ticket::TYPE_ENGINEERING_REQUEST);
                $this->assertSame('REQ-2026-0001', $generated);

                throw new RuntimeException('Simulated outer transaction failure on cold start.');
            });
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // Expected rollback.
        }

        $this->assertNull(
            TicketSequence::query()->where('prefix', 'REQ')->where('year', 2026)->first()
        );
        $this->assertSame('REQ-2026-0001', $ticketService->generateTicketNumber(Ticket::TYPE_ENGINEERING_REQUEST));
    }
}
