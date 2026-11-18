<?php

namespace App\Http\Controllers;

use App\Models\InternalSystem;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    /**
     * @var list<string>
     */
    private const ACTIVE_STATUSES = [
        Ticket::STATUS_OPEN,
        Ticket::STATUS_UNDER_REVIEW,
        Ticket::STATUS_IN_PROGRESS,
        Ticket::STATUS_WAITING_ON_USER,
    ];

    /**
     * @var list<string>
     */
    private const ALL_STATUSES = [
        Ticket::STATUS_OPEN,
        Ticket::STATUS_UNDER_REVIEW,
        Ticket::STATUS_IN_PROGRESS,
        Ticket::STATUS_WAITING_ON_USER,
        Ticket::STATUS_RESOLVED,
        Ticket::STATUS_CLOSED,
        Ticket::STATUS_REJECTED,
        Ticket::STATUS_CANCELLED,
    ];

    /**
     * @var list<string>
     */
    private const KANBAN_STATUSES = [
        Ticket::STATUS_OPEN,
        Ticket::STATUS_UNDER_REVIEW,
        Ticket::STATUS_IN_PROGRESS,
        Ticket::STATUS_WAITING_ON_USER,
        Ticket::STATUS_RESOLVED,
        Ticket::STATUS_CLOSED,
        Ticket::STATUS_REJECTED,
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = Ticket::visibleTo($user)
            ->with(['internalSystem', 'requester', 'leadEngineer', 'collaborators']);

        $this->applyFilters($query, $request, $user, includeStatus: true);

        $tickets = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $rawStatusCounts = Ticket::visibleTo($user)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statusCounts = ['all' => 0];
        foreach (self::ALL_STATUSES as $status) {
            $count = (int) ($rawStatusCounts[$status] ?? 0);
            $statusCounts[$status] = $count;
            $statusCounts['all'] += $count;
        }

        return Inertia::render('Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $request->only([
                'search',
                'type',
                'status',
                'priority',
                'internal_system_id',
                'lead_engineer_id',
                'scope',
            ]),
            'systems' => InternalSystem::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'engineers' => $this->activeStaffList(),
            'statusCounts' => $statusCounts,
        ]);
    }

    public function kanban(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->isStaff(), 403);

        $query = Ticket::visibleTo($user)
            ->with(['internalSystem', 'requester', 'leadEngineer', 'collaborators'])
            ->withCount([
                'subtasks',
                'subtasks as completed_subtasks_count' => fn (Builder $subQuery) => $subQuery->where('is_completed', true),
            ])
            ->whereIn('status', self::KANBAN_STATUSES);

        $this->applyFilters($query, $request, $user, includeStatus: false);

        $tickets = $query
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END ASC")
            ->orderByDesc('updated_at')
            ->get();

        $columns = [];
        foreach (self::KANBAN_STATUSES as $status) {
            $columns[$status] = $tickets->where('status', $status)->values()->all();
        }

        $selectedTicketId = $request->integer('ticket');
        $selectedTicket = $selectedTicketId > 0
            ? Ticket::visibleTo($user)->find($selectedTicketId)
            : null;

        return Inertia::render('Tickets/Kanban', [
            'columns' => $columns,
            'filters' => $request->only([
                'search',
                'type',
                'priority',
                'internal_system_id',
                'lead_engineer_id',
                'scope',
            ]),
            'systems' => InternalSystem::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'engineers' => $this->activeStaffList(),
            'selectedTicket' => $selectedTicket
                ? $this->loadTicketDetails($selectedTicket, $user->isStaff())
                : null,
            'selectedTicketPermissions' => $selectedTicket
                ? $this->buildTicketPermissions($selectedTicket, $user)
                : null,
        ]);
    }

    public function store(
        Request $request,
        TicketService $ticketService,
        NotificationService $notificationService
    ): RedirectResponse {
        $type = $request->input('type');

        $rules = [
            'type' => ['required', 'in:issue,engineering_request'],
            'internal_system_id' => ['nullable', 'exists:internal_systems,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority' => ['required', 'in:low,medium,high,critical'],
            'attachments' => ['required', 'array', 'min:1'],
            'attachments.*' => ['file', 'max:10240'],
        ];

        if ($type === Ticket::TYPE_ISSUE) {
            $rules = array_merge($rules, [
                'environment' => ['required', 'in:production,staging,internal_network,other'],
                'impact_scope' => ['required', 'in:single_user,department,company_wide'],
            ]);
        } elseif ($type === Ticket::TYPE_ENGINEERING_REQUEST) {
            $rules = array_merge($rules, [
                'business_justification' => ['required', 'string'],
                'proposed_functionality' => ['required', 'string'],
                'expected_benefits' => ['required', 'string'],
                'requested_deadline' => ['nullable', 'date'],
            ]);
        }

        $validated = $request->validate($rules);
        $user = $request->user();

        try {
            $ticket = DB::transaction(function () use ($validated, $user, $ticketService, $request): Ticket {
                $ticket = Ticket::create([
                    'ticket_number' => $ticketService->generateTicketNumber($validated['type']),
                    'type' => $validated['type'],
                    'internal_system_id' => $validated['internal_system_id'] ?? null,
                    'requester_id' => $user->id,
                    'lead_engineer_id' => null,
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'priority' => $validated['priority'],
                    'status' => Ticket::STATUS_OPEN,
                    'environment' => $validated['environment'] ?? null,
                    'steps_to_reproduce' => null,
                    'expected_behavior' => null,
                    'actual_behavior' => null,
                    'impact_scope' => $validated['impact_scope'] ?? null,
                    'business_justification' => $validated['business_justification'] ?? null,
                    'proposed_functionality' => $validated['proposed_functionality'] ?? null,
                    'expected_benefits' => $validated['expected_benefits'] ?? null,
                    'requested_deadline' => $validated['requested_deadline'] ?? null,
                ]);

                foreach ($request->file('attachments', []) as $file) {
                    $storedPath = $file->store("tickets/{$ticket->id}", 'public');

                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'ticket_comment_id' => null,
                        'uploaded_by_id' => $user->id,
                        'original_name' => $file->getClientOriginalName(),
                        'file_path' => $storedPath,
                        'mime_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize() ?? 0,
                        'is_internal' => false,
                    ]);
                }

                $ticketService->recordActivity(
                    $ticket,
                    $user,
                    'ticket_created',
                    "Ticket {$ticket->ticket_number} submitted by {$user->name}.",
                    null,
                    Ticket::STATUS_OPEN
                );

                return $ticket;
            });

            $superadmins = User::query()
                ->where('role', User::ROLE_SUPERADMIN)
                ->where('is_active', true)
                ->get();

            $notificationService->notifyUsers(
                $superadmins,
                $ticket,
                "New Ticket: {$ticket->ticket_number}",
                "{$user->name} submitted \"{$ticket->title}\".",
                route('tickets.show', $ticket, false),
                $user->id
            );

            $redirectRoute = Ticket::visibleTo($user)->whereKey($ticket->id)->exists()
                ? route('tickets.show', $ticket)
                : route('tickets.index');

            return redirect()
                ->to($redirectRoute)
                ->with('success', "Ticket {$ticket->ticket_number} created successfully.");
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with('error', 'Failed to submit ticket. Please try again.');
        }
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $user = $request->user();

        if (!Ticket::visibleTo($user)->whereKey($ticket->id)->exists()) {
            abort(403, 'You do not have permission to view this ticket.');
        }

        return Inertia::render('Tickets/Show', [
            'ticket' => $this->loadTicketDetails($ticket, $user->isStaff()),
            'engineers' => $this->activeStaffList(),
            'permissions' => $this->buildTicketPermissions($ticket, $user),
        ]);
    }

    public function updateWorkflow(
        Request $request,
        Ticket $ticket,
        TicketService $ticketService,
        NotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();

        if ($this->canStaffWorkOnTicket($ticket, $user)) {
            return $this->handleStaffWorkflowUpdate($request, $ticket, $user, $ticketService, $notificationService);
        }

        if ($user->isEngineer()) {
            abort(403, 'You do not have permission to update this ticket workflow.');
        }

        if ($ticket->requester_id === $user->id) {
            return $this->handleRequesterWorkflowUpdate($request, $ticket, $user, $ticketService, $notificationService);
        }

        abort(403, 'You do not have permission to update this ticket workflow.');
    }

    public function updateAssignment(
        Request $request,
        Ticket $ticket,
        TicketService $ticketService,
        NotificationService $notificationService
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user->isSuperAdmin(), 403);

        $oldLeadId = $ticket->lead_engineer_id ? (int) $ticket->lead_engineer_id : null;
        $oldCollaboratorIds = $ticket->collaborators()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $activeEngineerRule = Rule::exists('users', 'id')->where(
            fn ($query) => $query
                ->where('is_active', true)
                ->where('role', User::ROLE_ENGINEER)
        );

        $validated = $request->validate([
            'lead_engineer_id' => ['nullable', $activeEngineerRule],
            'collaborator_ids' => ['nullable', 'array'],
            'collaborator_ids.*' => ['integer', $activeEngineerRule],
        ]);

        $newLeadId = isset($validated['lead_engineer_id']) ? (int) $validated['lead_engineer_id'] : null;
        $collaboratorIds = collect($validated['collaborator_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $newLeadId !== null && $id === $newLeadId)
            ->values()
            ->all();

        $ticket->lead_engineer_id = $newLeadId;
        $ticket->save();

        $syncPayload = [];
        foreach ($collaboratorIds as $collabId) {
            $syncPayload[$collabId] = ['added_by_id' => $user->id];
        }
        $ticket->collaborators()->sync($syncPayload);

        $ticketService->markFirstResponseIfNeeded($ticket, $user);

        $leadChanged = $oldLeadId !== $newLeadId;
        $sortedNewCollaboratorIds = collect($collaboratorIds)->sort()->values()->all();
        $collaboratorsChanged = $oldCollaboratorIds !== $sortedNewCollaboratorIds;

        if ($leadChanged) {
            $oldLeadName = $oldLeadId ? (User::find($oldLeadId)?->name ?? 'Unknown') : 'Unassigned';
            $newLeadName = $newLeadId ? (User::find($newLeadId)?->name ?? 'Unknown') : 'Unassigned';

            $ticketService->recordActivity(
                $ticket,
                $user,
                'lead_assigned',
                "Lead engineer updated from {$oldLeadName} to {$newLeadName}.",
                $oldLeadName,
                $newLeadName
            );
        }

        if ($collaboratorsChanged) {
            $newCollaboratorNames = empty($collaboratorIds)
                ? 'None'
                : User::whereIn('id', $collaboratorIds)->orderBy('name')->pluck('name')->implode(', ');

            $ticketService->recordActivity(
                $ticket,
                $user,
                'collaborators_updated',
                "Collaborating engineers updated to: {$newCollaboratorNames}.",
                null,
                $newCollaboratorNames
            );
        }

        $actionUrl = route('tickets.show', $ticket, false);

        if ($leadChanged && $newLeadId !== null) {
            $newLead = User::find($newLeadId);
            if ($newLead) {
                $notificationService->notifyUsers(
                    [$newLead],
                    $ticket,
                    "Assigned as Lead Engineer: {$ticket->ticket_number}",
                    "You have been assigned as Lead Engineer on \"{$ticket->title}\".",
                    $actionUrl,
                    $user->id
                );
            }
        }

        $addedCollaboratorIds = array_diff($collaboratorIds, $oldCollaboratorIds);
        if (!empty($addedCollaboratorIds)) {
            $addedCollaborators = User::whereIn('id', $addedCollaboratorIds)->get();
            $notificationService->notifyUsers(
                $addedCollaborators,
                $ticket,
                "Added as Collaborator: {$ticket->ticket_number}",
                "{$user->name} added you as a collaborating engineer on \"{$ticket->title}\".",
                $actionUrl,
                $user->id
            );
        }

        if ($leadChanged || $collaboratorsChanged) {
            $ticket->loadMissing('requester');
            if ($ticket->requester) {
                $notificationService->notifyUsers(
                    [$ticket->requester],
                    $ticket,
                    "Assignment Updated: {$ticket->ticket_number}",
                    "Engineering assignment was updated for \"{$ticket->title}\".",
                    $actionUrl,
                    $user->id
                );
            }
        }

        return back()->with('success', 'Ticket assignment updated.');
    }

    public function downloadAttachment(Request $request, TicketAttachment $attachment): StreamedResponse
    {
        $user = $request->user();

        if (!Ticket::visibleTo($user)->whereKey($attachment->ticket_id)->exists()) {
            abort(403, 'You do not have permission to download this attachment.');
        }

        if ($attachment->is_internal && !$user->isStaff()) {
            abort(403, 'You do not have permission to download internal attachments.');
        }

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'Attachment file not found.');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }

    /**
     * @param Builder<Ticket> $query
     */
    private function applyFilters(Builder $query, Request $request, User $user, bool $includeStatus): void
    {
        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function (Builder $subQuery) use ($search): void {
                $subQuery->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($includeStatus && ($status = $request->input('status'))) {
            $query->where('status', $status);
        }

        if ($priority = $request->input('priority')) {
            $query->where('priority', $priority);
        }

        if ($systemId = $request->input('internal_system_id')) {
            $query->where('internal_system_id', $systemId);
        }

        if ($user->isSuperAdmin() && ($leadId = $request->input('lead_engineer_id'))) {
            if ($leadId === 'unassigned') {
                $query->whereNull('lead_engineer_id');
            } else {
                $query->where('lead_engineer_id', $leadId);
            }
        }

        $scope = $request->input('scope');
        match ($scope) {
            'unassigned' => !$user->isEngineer() ? $query->whereNull('lead_engineer_id') : null,
            'led_by_me' => $query->where('lead_engineer_id', $user->id),
            'collaborating' => $query->whereHas('collaborators', fn (Builder $c) => $c->where('users.id', $user->id)),
            'my_submitted' => !$user->isEngineer() ? $query->where('requester_id', $user->id) : null,
            'overdue' => $query->whereIn('status', self::ACTIVE_STATUSES)
                ->whereNotNull('target_due_date')
                ->whereDate('target_due_date', '<', today()),
            default => null,
        };
    }

    private function handleStaffWorkflowUpdate(
        Request $request,
        Ticket $ticket,
        User $user,
        TicketService $ticketService,
        NotificationService $notificationService
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['sometimes', 'required', Rule::in(self::ALL_STATUSES)],
            'priority' => ['sometimes', 'required', 'in:low,medium,high,critical'],
            'target_due_date' => ['sometimes', 'nullable', 'date'],
            'review_decision_notes' => ['sometimes', 'nullable', 'string'],
            'resolution_summary' => ['sometimes', 'nullable', 'string'],
        ]);

        $changes = [];

        if (array_key_exists('status', $validated) && $validated['status'] !== $ticket->status) {
            $oldStatus = $ticket->status;
            $newStatus = $validated['status'];
            $ticket->status = $newStatus;

            if ($newStatus === Ticket::STATUS_RESOLVED && $ticket->resolved_at === null) {
                $ticket->resolved_at = now();
            }

            if ($newStatus === Ticket::STATUS_CLOSED && $ticket->closed_at === null) {
                $ticket->closed_at = now();
            }

            if (
                in_array($oldStatus, [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true)
                && in_array($newStatus, self::ACTIVE_STATUSES, true)
            ) {
                $ticket->closed_at = null;
            }

            $changes[] = [
                'type' => 'status_changed',
                'description' => "Status changed from {$oldStatus} to {$newStatus}.",
                'old' => $oldStatus,
                'new' => $newStatus,
            ];
        }

        if (array_key_exists('priority', $validated) && $validated['priority'] !== $ticket->priority) {
            $oldPriority = $ticket->priority;
            $newPriority = $validated['priority'];
            $ticket->priority = $newPriority;

            $changes[] = [
                'type' => 'priority_changed',
                'description' => "Priority changed from {$oldPriority} to {$newPriority}.",
                'old' => $oldPriority,
                'new' => $newPriority,
            ];
        }

        if (array_key_exists('target_due_date', $validated)) {
            $oldDate = $ticket->target_due_date?->toDateString();
            $newDate = $validated['target_due_date'] ?: null;

            if ($oldDate !== $newDate) {
                $ticket->target_due_date = $newDate;
                $changes[] = [
                    'type' => 'due_date_updated',
                    'description' => 'Target due date updated' . ($newDate ? " to {$newDate}." : ' (cleared).'),
                    'old' => $oldDate,
                    'new' => $newDate,
                ];
            }
        }

        if (
            array_key_exists('review_decision_notes', $validated)
            && $validated['review_decision_notes'] !== $ticket->review_decision_notes
        ) {
            $ticket->review_decision_notes = $validated['review_decision_notes'];
            $changes[] = [
                'type' => 'review_updated',
                'description' => 'Engineering review notes updated.',
                'old' => null,
                'new' => null,
            ];
        }

        if (
            array_key_exists('resolution_summary', $validated)
            && $validated['resolution_summary'] !== $ticket->resolution_summary
        ) {
            $ticket->resolution_summary = $validated['resolution_summary'];
            $changes[] = [
                'type' => 'resolution_updated',
                'description' => 'Resolution summary updated.',
                'old' => null,
                'new' => null,
            ];
        }

        $ticket->save();
        $ticketService->markFirstResponseIfNeeded($ticket, $user);

        foreach ($changes as $change) {
            $ticketService->recordActivity(
                $ticket,
                $user,
                $change['type'],
                $change['description'],
                $change['old'],
                $change['new']
            );
        }

        if (!empty($changes)) {
            $ticket->loadMissing(['requester', 'leadEngineer', 'collaborators']);
            $recipients = collect([$ticket->requester, $ticket->leadEngineer])
                ->merge($ticket->collaborators);

            $notificationService->notifyUsers(
                $recipients,
                $ticket,
                "Ticket Updated: {$ticket->ticket_number}",
                "{$user->name} updated \"{$ticket->title}\" (Status: {$ticket->status}, Priority: {$ticket->priority}).",
                route('tickets.show', $ticket, false),
                $user->id
            );
        }

        return back()->with('success', 'Ticket workflow updated.');
    }

    private function handleRequesterWorkflowUpdate(
        Request $request,
        Ticket $ticket,
        User $user,
        TicketService $ticketService,
        NotificationService $notificationService
    ): RedirectResponse {
        $validated = $request->validate([
            'action' => ['required', 'in:confirm_close,reopen,cancel'],
            'reopen_reason' => ['nullable', 'string'],
        ]);

        $ticket->loadMissing(['leadEngineer', 'collaborators']);
        $staffRecipients = collect([$ticket->leadEngineer])->merge($ticket->collaborators);
        $actionUrl = route('tickets.show', $ticket, false);

        if ($validated['action'] === 'confirm_close') {
            if (!in_array($ticket->status, [Ticket::STATUS_RESOLVED, Ticket::STATUS_WAITING_ON_USER], true)) {
                $message = 'This ticket cannot be confirmed closed in its current status.';
                $request->session()->flash('error', $message);

                throw ValidationException::withMessages([
                    'action' => $message,
                ]);
            }

            $oldStatus = $ticket->status;
            $ticket->status = Ticket::STATUS_CLOSED;
            $ticket->closed_at = now();
            $ticket->save();

            $ticketService->recordActivity(
                $ticket,
                $user,
                'status_changed',
                "{$user->name} confirmed resolution and closed the ticket.",
                $oldStatus,
                Ticket::STATUS_CLOSED
            );

            $notificationService->notifyUsers(
                $staffRecipients,
                $ticket,
                "Ticket Closed: {$ticket->ticket_number}",
                "{$user->name} confirmed and closed \"{$ticket->title}\".",
                $actionUrl,
                $user->id
            );

            return back()->with('success', 'Ticket confirmed and closed.');
        }

        if ($validated['action'] === 'reopen') {
            if (!in_array($ticket->status, [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true)) {
                $message = 'Only resolved or closed tickets can be reopened.';
                $request->session()->flash('error', $message);

                throw ValidationException::withMessages([
                    'action' => $message,
                ]);
            }

            $oldStatus = $ticket->status;
            $newStatus = $ticket->lead_engineer_id ? Ticket::STATUS_IN_PROGRESS : Ticket::STATUS_OPEN;
            $ticket->status = $newStatus;
            $ticket->closed_at = null;
            $ticket->save();

            if (!empty($validated['reopen_reason'])) {
                TicketComment::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $user->id,
                    'body' => $validated['reopen_reason'],
                    'is_internal' => false,
                ]);
            }

            $ticketService->recordActivity(
                $ticket,
                $user,
                'reopened',
                "{$user->name} reopened the ticket.",
                $oldStatus,
                $newStatus
            );

            $notificationService->notifyUsers(
                $staffRecipients,
                $ticket,
                "Ticket Reopened: {$ticket->ticket_number}",
                "{$user->name} reopened \"{$ticket->title}\".",
                $actionUrl,
                $user->id
            );

            return back()->with('success', 'Ticket reopened.');
        }

        // action === 'cancel'
        if ($ticket->status !== Ticket::STATUS_OPEN) {
            $message = 'Only open tickets can be cancelled.';
            $request->session()->flash('error', $message);

            throw ValidationException::withMessages([
                'action' => $message,
            ]);
        }

        $ticket->status = Ticket::STATUS_CANCELLED;
        $ticket->save();

        $ticketService->recordActivity(
            $ticket,
            $user,
            'status_changed',
            "{$user->name} cancelled the ticket.",
            Ticket::STATUS_OPEN,
            Ticket::STATUS_CANCELLED
        );

        $notificationService->notifyUsers(
            $staffRecipients,
            $ticket,
            "Ticket Cancelled: {$ticket->ticket_number}",
            "{$user->name} cancelled \"{$ticket->title}\".",
            $actionUrl,
            $user->id
        );

        return back()->with('success', 'Ticket cancelled.');
    }

    private function loadTicketDetails(Ticket $ticket, bool $isStaff): Ticket
    {
        return $ticket->load([
            'internalSystem',
            'requester',
            'leadEngineer',
            'collaborators',
            'subtasks' => fn ($query) => $query->orderBy('id')->with('assignedEngineer'),
            'activities' => fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id')->with('user'),
            'comments' => function ($query) use ($isStaff): void {
                if (!$isStaff) {
                    $query->where('is_internal', false);
                }

                $query->orderBy('created_at')->orderBy('id')->with([
                    'user',
                    'attachments' => function ($attachmentQuery) use ($isStaff): void {
                        if (!$isStaff) {
                            $attachmentQuery->where('is_internal', false);
                        }
                    },
                ]);
            },
            'attachments' => function ($query) use ($isStaff): void {
                if (!$isStaff) {
                    $query->where('is_internal', false);
                }

                $query->orderBy('created_at')->orderBy('id')->with('uploadedBy');
            },
        ]);
    }

    private function canStaffWorkOnTicket(Ticket $ticket, User $user): bool
    {
        return $ticket->canBeWorkedOnBy($user);
    }

    /**
     * @return array{
     *     can_manage_workflow: bool,
     *     can_assign: bool,
     *     can_post_internal_note: bool,
     *     can_manage_subtasks: bool,
     *     can_close_or_reopen: bool,
     *     can_cancel: bool
     * }
     */
    private function buildTicketPermissions(Ticket $ticket, User $user): array
    {
        $canWorkOnTicket = $this->canStaffWorkOnTicket($ticket, $user);

        return [
            'can_manage_workflow' => $canWorkOnTicket,
            'can_assign' => $user->isSuperAdmin(),
            'can_post_internal_note' => $canWorkOnTicket,
            'can_manage_subtasks' => $canWorkOnTicket,
            'can_close_or_reopen' => $canWorkOnTicket || (
                $ticket->requester_id === $user->id
                && in_array($ticket->status, [
                    Ticket::STATUS_RESOLVED,
                    Ticket::STATUS_WAITING_ON_USER,
                    Ticket::STATUS_CLOSED,
                ], true)
            ),
            'can_cancel' => $canWorkOnTicket || (
                $ticket->requester_id === $user->id
                && $ticket->status === Ticket::STATUS_OPEN
            ),
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function activeStaffList()
    {
        return User::query()
            ->where('is_active', true)
            ->where('role', User::ROLE_ENGINEER)
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'role', 'job_title']);
    }
}
