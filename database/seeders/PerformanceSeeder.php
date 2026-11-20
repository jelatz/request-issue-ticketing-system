<?php

namespace Database\Seeders;

use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Large local dataset for before/after performance timing.
 *
 * This seeder is run manually and is never called from DatabaseSeeder:
 *   php artisan db:seed --class=PerformanceSeeder
 *
 * It uses chunked bulk inserts (not per-row factories) so it finishes in a
 * reasonable time on SQLite.
 */
class PerformanceSeeder extends Seeder
{
    private const TICKET_COUNT = 20000;

    private const ACTIVITY_COUNT = 100000;

    private const NOTIFICATION_COUNT = 50000;

    private const SUPERADMIN_COUNT = 3;

    private const ENGINEER_COUNT = 25;

    private const REQUESTER_COUNT = 400;

    private const CHUNK_SIZE = 1000;

    private const TICKET_CHUNK_SIZE = 500;

    /**
     * @var list<string>
     */
    private const DEPARTMENTS = [
        'Operations', 'Finance', 'Human Resources', 'Sales', 'Marketing',
        'Customer Support', 'Quality Assurance', 'Workforce Management',
        'Training', 'Compliance', 'Procurement', 'Legal',
    ];

    /**
     * @var list<array{0: string, 1: string}>
     */
    private const SYSTEMS = [
        ['Perf CRM Portal', 'Core Platform'],
        ['Perf Billing Engine', 'Finance'],
        ['Perf Payroll Suite', 'Human Resources'],
        ['Perf Dialer Platform', 'Telephony'],
        ['Perf Reporting Hub', 'Analytics'],
        ['Perf Knowledge Base', 'Support'],
        ['Perf Workforce Scheduler', 'Operations'],
        ['Perf QA Scorecard', 'Quality'],
        ['Perf Identity Service', 'Security'],
        ['Perf Inventory Tracker', 'Operations'],
        ['Perf Ticket Bridge', 'Integrations'],
        ['Perf Training LMS', 'Training'],
    ];

    /**
     * @var list<string>
     */
    private const ACTION_TYPES = [
        'status_changed', 'comment_added', 'lead_assigned', 'collaborators_updated',
        'subtask_added', 'subtask_toggled', 'subtask_removed', 'reopened',
    ];

    private CarbonImmutable $now;

    public function run(): void
    {
        mt_srand(20261120);
        $this->now = CarbonImmutable::now();

        DB::connection()->disableQueryLog();

        $started = microtime(true);

        DB::transaction(function (): void {
            [$superadmins, $engineers, $requesters] = $this->seedUsers();
            $this->command?->info('Users seeded.');

            $systemIds = $this->seedInternalSystems();
            $this->command?->info('Internal systems seeded.');

            $tickets = $this->seedTickets($requesters, $engineers, $systemIds);
            $this->command?->info('Tickets seeded: '.count($tickets));

            $collaborators = $this->seedCollaborators($tickets, $engineers, $superadmins);
            $this->command?->info('Collaborators seeded.');

            $this->seedComments($tickets, $engineers);
            $this->command?->info('Comments seeded.');

            $this->seedSubtasks($tickets, $engineers);
            $this->command?->info('Subtasks seeded.');

            $staff = array_merge($superadmins, $engineers);
            $this->seedActivities($tickets, $staff);
            $this->command?->info('Activities seeded.');

            $this->seedNotifications($tickets, $collaborators, $superadmins);
            $this->command?->info('Notifications seeded.');
        });

        $this->command?->info(sprintf('PerformanceSeeder finished in %.1f seconds.', microtime(true) - $started));
    }

    /**
     * @return array{0: list<int>, 1: list<int>, 2: list<int>}
     */
    private function seedUsers(): array
    {
        $password = Hash::make('password');
        $timestamp = $this->now->subYears(2)->toDateTimeString();
        $runTag = substr((string) $this->now->getTimestamp(), -6);

        $definitions = [
            [User::ROLE_SUPERADMIN, self::SUPERADMIN_COUNT, 'admin', 'IT Leadership', 'Engineering Manager'],
            [User::ROLE_ENGINEER, self::ENGINEER_COUNT, 'eng', 'Engineering', 'Software Engineer'],
            [User::ROLE_USER, self::REQUESTER_COUNT, 'req', null, 'Specialist'],
        ];

        $result = [];

        foreach ($definitions as [$role, $count, $slug, $department, $jobTitle]) {
            $rows = [];
            $usernames = [];

            for ($i = 1; $i <= $count; $i++) {
                $username = "perf_{$runTag}_{$slug}_{$i}";
                $usernames[] = $username;
                $rows[] = [
                    'name' => ucfirst($slug).' Perf '.$i,
                    'username' => $username,
                    'email' => $username.'@perf.calltek.local',
                    'email_verified_at' => $timestamp,
                    'password' => $password,
                    'role' => $role,
                    'department' => $department ?? self::DEPARTMENTS[array_rand(self::DEPARTMENTS)],
                    'job_title' => $jobTitle,
                    'is_active' => mt_rand(1, 100) <= 95,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            $this->bulkInsert('users', $rows);

            $result[] = DB::table('users')
                ->whereIn('username', $usernames)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    private function seedInternalSystems(): array
    {
        $timestamp = $this->now->subYears(2)->toDateTimeString();
        $ids = [];

        foreach (self::SYSTEMS as [$name, $category]) {
            $existing = DB::table('internal_systems')->where('name', $name)->value('id');

            if ($existing !== null) {
                $ids[] = (int) $existing;

                continue;
            }

            $ids[] = (int) DB::table('internal_systems')->insertGetId([
                'name' => $name,
                'category' => $category,
                'description' => "Performance dataset system: {$name}.",
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        return $ids;
    }

    /**
     * Insert tickets and return a compact descriptor per ticket.
     *
     * @param  list<int>  $requesters
     * @param  list<int>  $engineers
     * @param  list<int>  $systemIds
     * @return list<array{id: int, requester: int, lead: ?int, created: int, status: string}>
     */
    private function seedTickets(array $requesters, array $engineers, array $systemIds): array
    {
        $rangeStart = $this->now->subMonths(24)->getTimestamp();
        $rangeEnd = $this->now->subHour()->getTimestamp();

        $createdTimes = [];
        for ($i = 0; $i < self::TICKET_COUNT; $i++) {
            $createdTimes[] = mt_rand($rangeStart, $rangeEnd);
        }
        sort($createdTimes);

        $sequences = [];
        $statuses = [
            Ticket::STATUS_OPEN => 14, Ticket::STATUS_UNDER_REVIEW => 8, Ticket::STATUS_IN_PROGRESS => 18,
            Ticket::STATUS_WAITING_ON_USER => 6, Ticket::STATUS_RESOLVED => 22, Ticket::STATUS_CLOSED => 24,
            Ticket::STATUS_REJECTED => 4, Ticket::STATUS_CANCELLED => 4,
        ];
        $priorities = [
            Ticket::PRIORITY_LOW => 25, Ticket::PRIORITY_MEDIUM => 45,
            Ticket::PRIORITY_HIGH => 22, Ticket::PRIORITY_CRITICAL => 8,
        ];

        $descriptors = [];
        $rows = [];
        $numbers = [];

        foreach ($createdTimes as $createdTs) {
            $created = CarbonImmutable::createFromTimestamp($createdTs);
            $isIssue = mt_rand(1, 100) <= 60;
            $type = $isIssue ? Ticket::TYPE_ISSUE : Ticket::TYPE_ENGINEERING_REQUEST;
            $prefix = $isIssue ? 'ISS' : 'REQ';
            $year = $created->format('Y');
            $key = "{$prefix}-{$year}";

            if (! isset($sequences[$key])) {
                $sequences[$key] = $this->maxSequence("{$prefix}-{$year}-");
            }
            $sequences[$key]++;
            $ticketNumber = sprintf('%s-%s-%04d', $prefix, $year, $sequences[$key]);

            $status = $this->weighted($statuses);
            $priority = $this->weighted($priorities);
            $lead = ($status === Ticket::STATUS_OPEN && mt_rand(1, 100) <= 60)
                ? null
                : $engineers[array_rand($engineers)];
            $requester = $requesters[array_rand($requesters)];

            $firstResponded = $status === Ticket::STATUS_OPEN
                ? null
                : $this->clampToNow($created->addMinutes(mt_rand(5, 60 * 48)));
            $resolved = in_array($status, [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true)
                ? $this->clampToNow($created->addHours(mt_rand(2, 24 * 30)))
                : null;
            $closed = $status === Ticket::STATUS_CLOSED && $resolved !== null
                ? $this->clampToNow($resolved->addHours(mt_rand(1, 24 * 7)))
                : null;
            $updated = $closed ?? $resolved ?? $firstResponded ?? $created;

            $rows[] = [
                'ticket_number' => $ticketNumber,
                'type' => $type,
                'internal_system_id' => mt_rand(1, 100) <= 90 ? $systemIds[array_rand($systemIds)] : null,
                'requester_id' => $requester,
                'lead_engineer_id' => $lead,
                'title' => ($isIssue ? 'Issue' : 'Request').' '.$ticketNumber.' performance sample',
                'description' => 'Generated performance dataset ticket. Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
                'priority' => $priority,
                'status' => $status,
                'environment' => $isIssue ? (mt_rand(0, 1) ? 'Production' : 'Staging') : null,
                'steps_to_reproduce' => $isIssue ? 'Open the page, perform the action, observe the result.' : null,
                'expected_behavior' => $isIssue ? 'The action completes successfully.' : null,
                'actual_behavior' => $isIssue ? 'An error is shown.' : null,
                'impact_scope' => $isIssue ? (mt_rand(0, 1) ? 'Single user' : 'Department') : null,
                'business_justification' => $isIssue ? null : 'Reduces manual effort for the team.',
                'proposed_functionality' => $isIssue ? null : 'Add an automated workflow step.',
                'expected_benefits' => $isIssue ? null : 'Saves several hours per week.',
                'requested_deadline' => $isIssue ? null : $created->addDays(mt_rand(7, 90))->toDateString(),
                'review_decision_notes' => null,
                'target_due_date' => mt_rand(1, 100) <= 85 ? $created->addDays(mt_rand(1, 45))->toDateString() : null,
                'first_responded_at' => $firstResponded?->toDateTimeString(),
                'resolved_at' => $resolved?->toDateTimeString(),
                'closed_at' => $closed?->toDateTimeString(),
                'resolution_summary' => $resolved !== null ? 'Resolved as part of the performance dataset.' : null,
                'created_at' => $created->toDateTimeString(),
                'updated_at' => $updated->toDateTimeString(),
            ];

            $numbers[] = $ticketNumber;
            $descriptors[$ticketNumber] = [
                'id' => 0,
                'requester' => $requester,
                'lead' => $lead,
                'created' => $createdTs,
                'status' => $status,
            ];

            if (count($rows) >= self::TICKET_CHUNK_SIZE) {
                DB::table('tickets')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('tickets')->insert($rows);
        }

        foreach (array_chunk($numbers, self::CHUNK_SIZE) as $chunk) {
            $ids = DB::table('tickets')->whereIn('ticket_number', $chunk)->pluck('id', 'ticket_number');
            foreach ($ids as $number => $id) {
                $descriptors[$number]['id'] = (int) $id;
            }
        }

        if (Schema::hasTable('ticket_sequences') && $sequences !== []) {
            $timestamp = $this->now->toDateTimeString();
            $existingSequences = DB::table('ticket_sequences')
                ->get(['prefix', 'year', 'last_number'])
                ->keyBy(fn (object $row): string => "{$row->prefix}-{$row->year}");

            $sequenceRows = [];
            foreach ($sequences as $key => $lastNumber) {
                [$prefix, $year] = explode('-', $key, 2);
                $existing = $existingSequences->get($key);
                $resolvedLastNumber = $existing !== null
                    ? max((int) $existing->last_number, (int) $lastNumber)
                    : (int) $lastNumber;

                $sequenceRows[] = [
                    'prefix' => $prefix,
                    'year' => (int) $year,
                    'last_number' => $resolvedLastNumber,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            DB::table('ticket_sequences')->upsert(
                $sequenceRows,
                ['prefix', 'year'],
                ['last_number', 'updated_at']
            );
        }

        return array_values($descriptors);
    }

    /**
     * @param  list<array{id: int, requester: int, lead: ?int, created: int, status: string}>  $tickets
     * @param  list<int>  $engineers
     * @param  list<int>  $superadmins
     * @return array<int, list<int>> collaborator user ids keyed by ticket id
     */
    private function seedCollaborators(array $tickets, array $engineers, array $superadmins): array
    {
        $rows = [];
        $map = [];

        foreach ($tickets as $ticket) {
            if (mt_rand(1, 100) > 30) {
                continue;
            }

            $count = mt_rand(1, 3);
            $picked = [];
            while (count($picked) < $count) {
                $candidate = $engineers[array_rand($engineers)];
                if ($candidate !== $ticket['lead']) {
                    $picked[$candidate] = true;
                }
            }

            $timestamp = $this->randomAfter($ticket['created'], 72)->toDateTimeString();
            foreach (array_keys($picked) as $userId) {
                $rows[] = [
                    'ticket_id' => $ticket['id'],
                    'user_id' => $userId,
                    'added_by_id' => $ticket['lead'] ?? $superadmins[array_rand($superadmins)],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $map[$ticket['id']][] = $userId;
            }

            $this->flushIfFull('ticket_collaborators', $rows);
        }

        $this->bulkInsert('ticket_collaborators', $rows);

        return $map;
    }

    /**
     * @param  list<array{id: int, requester: int, lead: ?int, created: int, status: string}>  $tickets
     * @param  list<int>  $engineers
     */
    private function seedComments(array $tickets, array $engineers): void
    {
        $rows = [];

        foreach ($tickets as $ticket) {
            $count = mt_rand(0, 6);
            for ($i = 0; $i < $count; $i++) {
                $fromRequester = mt_rand(0, 1) === 1;
                $timestamp = $this->randomAfter($ticket['created'], 24 * 20)->toDateTimeString();
                $rows[] = [
                    'ticket_id' => $ticket['id'],
                    'user_id' => $fromRequester ? $ticket['requester'] : ($ticket['lead'] ?? $engineers[array_rand($engineers)]),
                    'body' => 'Performance dataset comment '.($i + 1).'. Lorem ipsum dolor sit amet.',
                    'is_internal' => ! $fromRequester && mt_rand(1, 100) <= 25,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            $this->flushIfFull('ticket_comments', $rows);
        }

        $this->bulkInsert('ticket_comments', $rows);
    }

    /**
     * @param  list<array{id: int, requester: int, lead: ?int, created: int, status: string}>  $tickets
     * @param  list<int>  $engineers
     */
    private function seedSubtasks(array $tickets, array $engineers): void
    {
        $rows = [];

        foreach ($tickets as $ticket) {
            if (mt_rand(1, 100) > 40) {
                continue;
            }

            $count = mt_rand(1, 4);
            for ($i = 0; $i < $count; $i++) {
                $created = $this->randomAfter($ticket['created'], 24 * 5);
                $completed = mt_rand(0, 1) === 1;
                $rows[] = [
                    'ticket_id' => $ticket['id'],
                    'title' => 'Subtask '.($i + 1),
                    'assigned_engineer_id' => mt_rand(1, 100) <= 85 ? $engineers[array_rand($engineers)] : null,
                    'is_completed' => $completed,
                    'completed_at' => $completed ? $this->clampToNow($created->addHours(mt_rand(1, 240)))->toDateTimeString() : null,
                    'created_by_id' => $ticket['lead'] ?? $engineers[array_rand($engineers)],
                    'created_at' => $created->toDateTimeString(),
                    'updated_at' => $created->toDateTimeString(),
                ];
            }

            $this->flushIfFull('ticket_subtasks', $rows);
        }

        $this->bulkInsert('ticket_subtasks', $rows);
    }

    /**
     * @param  list<array{id: int, requester: int, lead: ?int, created: int, status: string}>  $tickets
     * @param  list<int>  $staff
     */
    private function seedActivities(array $tickets, array $staff): void
    {
        $rows = [];
        $ticketCount = count($tickets);
        $perTicket = intdiv(self::ACTIVITY_COUNT, $ticketCount);
        $remainder = self::ACTIVITY_COUNT - ($perTicket * $ticketCount);

        foreach ($tickets as $index => $ticket) {
            $created = CarbonImmutable::createFromTimestamp($ticket['created'])->toDateTimeString();
            $rows[] = [
                'ticket_id' => $ticket['id'],
                'user_id' => $ticket['requester'],
                'action_type' => 'ticket_created',
                'old_value' => null,
                'new_value' => null,
                'description' => 'Ticket created.',
                'created_at' => $created,
                'updated_at' => $created,
            ];

            $extra = $perTicket - 1 + ($index < $remainder ? 1 : 0);
            for ($i = 0; $i < $extra; $i++) {
                $actionType = self::ACTION_TYPES[array_rand(self::ACTION_TYPES)];
                $timestamp = $this->randomAfter($ticket['created'], 24 * 30)->toDateTimeString();
                $isStatus = $actionType === 'status_changed';
                $rows[] = [
                    'ticket_id' => $ticket['id'],
                    'user_id' => $ticket['lead'] ?? $staff[array_rand($staff)],
                    'action_type' => $actionType,
                    'old_value' => $isStatus ? Ticket::STATUS_OPEN : null,
                    'new_value' => $isStatus ? Ticket::STATUS_IN_PROGRESS : null,
                    'description' => 'Performance dataset activity: '.str_replace('_', ' ', $actionType).'.',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            $this->flushIfFull('ticket_activities', $rows);
        }

        $this->bulkInsert('ticket_activities', $rows);
    }

    /**
     * @param  list<array{id: int, requester: int, lead: ?int, created: int, status: string}>  $tickets
     * @param  array<int, list<int>>  $collaborators
     * @param  list<int>  $superadmins
     */
    private function seedNotifications(array $tickets, array $collaborators, array $superadmins): void
    {
        $rows = [];
        $ticketCount = count($tickets);

        for ($i = 0; $i < self::NOTIFICATION_COUNT; $i++) {
            $ticket = $tickets[mt_rand(0, $ticketCount - 1)];
            $candidates = array_merge(
                [$ticket['requester']],
                $ticket['lead'] !== null ? [$ticket['lead']] : [],
                $collaborators[$ticket['id']] ?? [],
                [$superadmins[array_rand($superadmins)]],
            );
            $created = $this->randomAfter($ticket['created'], 24 * 30);
            $isRead = mt_rand(1, 100) <= 70;

            $rows[] = [
                'user_id' => $candidates[array_rand($candidates)],
                'ticket_id' => $ticket['id'],
                'title' => 'Ticket updated',
                'message' => 'A ticket you follow was updated in the performance dataset.',
                'action_url' => '/tickets/'.$ticket['id'],
                'is_read' => $isRead,
                'read_at' => $isRead ? $this->clampToNow($created->addHours(mt_rand(1, 72)))->toDateTimeString() : null,
                'created_at' => $created->toDateTimeString(),
                'updated_at' => $created->toDateTimeString(),
            ];

            $this->flushIfFull('user_notifications', $rows);
        }

        $this->bulkInsert('user_notifications', $rows);
    }

    private function maxSequence(string $basePrefix): int
    {
        $max = 0;

        DB::table('tickets')
            ->where('ticket_number', 'like', $basePrefix.'%')
            ->pluck('ticket_number')
            ->each(function (string $number) use ($basePrefix, &$max): void {
                $suffix = substr($number, strlen($basePrefix));
                if (ctype_digit($suffix)) {
                    $max = max($max, (int) $suffix);
                }
            });

        return $max;
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }

    private function randomAfter(int $timestamp, int $maxHours): CarbonImmutable
    {
        return $this->clampToNow(CarbonImmutable::createFromTimestamp($timestamp + mt_rand(60, $maxHours * 3600)));
    }

    private function clampToNow(CarbonImmutable $value): CarbonImmutable
    {
        return $value->greaterThan($this->now) ? $this->now : $value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flushIfFull(string $table, array &$rows): void
    {
        if (count($rows) >= self::CHUNK_SIZE) {
            DB::table($table)->insert($rows);
            $rows = [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function bulkInsert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
