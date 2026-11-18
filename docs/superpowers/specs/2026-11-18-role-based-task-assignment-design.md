# Role-Based Task Assignment Design

## Intent & Why
Enforce strict role-based boundaries around ticket assignment, ticket visibility, and ticket execution across the platform. Previously, any staff user (`superadmin` or `engineer`) could view all tickets, self-claim unassigned tickets, modify lead/collaborator assignments, and assign tickets or subtasks to `superadmin` accounts. Separating administrative triage (`superadmin`) from engineering execution (`engineer`) ensures `superadmin` users exclusively manage ticket allocation, `engineer` accounts only see and work on tickets assigned to them, and assignment rosters remain strictly limited to active `engineer` accounts.

## Size
**Medium change** — updates backend visibility scoping, route middleware, authorization checks, validation rules, and frontend assignment/filter UI across existing models, controllers, Vue pages, and feature tests without introducing database schema migrations.

## Goals, Non-Goals & Anti-Goals
- **Goals:**
  - Restrict ticket assignment (`lead_engineer_id` and `collaborator_ids`) strictly to `superadmin` (`User::ROLE_SUPERADMIN`), removing self-service ticket claiming (`action: 'claim'`) from both backend and frontend.
  - Restrict the assignable engineer pool (`TicketController::activeStaffList()`, `TicketController::updateAssignment()`, `TicketSubtaskController::store()`, and `ReportController::buildReportData()`) to active users with `role = 'engineer'` (`User::ROLE_ENGINEER`), excluding `superadmin`.
  - Limit `engineer` (`User::ROLE_ENGINEER`) visibility and work capabilities (viewing tickets, updating workflow/triage, managing subtasks, posting comments or internal notes, downloading attachments) strictly to tickets where they are assigned as `lead_engineer_id` or in `collaborators`.
  - Allow `superadmin` to view and manage all tickets across the platform, including open unassigned tickets.
  - Align Vue UI components (`TicketDetailWorkspace.vue`, `Tickets/Index.vue`, `Tickets/Kanban.vue`) so assignment controls, lead filters, and `unassigned` scope options match each role's capabilities.
- **Non-Goals:**
  - Changing standard `user` (`User::ROLE_USER`) visibility, submission, comment, or close/reopen/cancel permissions.
  - Changing `/reports` or `/tickets/kanban` route-level access for `engineer` users (engineers retain Kanban board access for their assigned tickets and report access).
  - Adding database schema migrations or new user roles.
- **Anti-Goals:**
  - Relying solely on frontend UI hiding (`v-if`) without backend middleware, `Ticket::visibleTo()` query scoping, and controller-level `403 Forbidden` enforcement.
  - Leaving legacy `action: 'claim'` handling active in `TicketController::updateAssignment()`.

## Constraints
- Follow the project's No Icons rule: zero emojis, zero `<svg>` tags, zero `<i>` tags, and zero FontAwesome classes in code, templates, or documentation.
- Preserve existing `back()` Inertia redirects, validation error keys, and `TicketService` / `NotificationService` contracts.
- Maintain existing Inertia shared auth flags in `HandleInertiaRequests` (`user.is_super_admin`, `user.is_engineer`, `user.is_staff`).
- Keep `lead_engineer_id` + `collaborator_ids` assignment structure and automatically exclude the lead engineer from `collaborator_ids`.

## Design Summary
- **Ticket Visibility and Assignment Helper (`app/Models/Ticket.php`):**
  - Add `public function isAssignedTo(User $user): bool` on `App\Models\Ticket`. Returns `true` when `(int) $this->lead_engineer_id === (int) $user->id` or the user exists in the `collaborators` relation (checking loaded `collaborators` collection when relation-loaded, otherwise querying `collaborators()->where('users.id', $user->id)->exists()`).
  - Update `public function scopeVisibleTo(Builder $query, User $user): Builder`:
    - If `$user->isSuperAdmin()`, return `$query` unrestricted.
    - If `$user->isEngineer()`, scope `$query` strictly to tickets where `lead_engineer_id = $user->id` or `whereHas('collaborators', fn (Builder $collabQuery) => $collabQuery->where('users.id', $user->id))`.
    - Otherwise (`User::ROLE_USER`), preserve existing scoping (`requester_id = $user->id` or collaborator membership).

- **Assignment Authorization & Engineer-Only Pool:**
  - **`routes/web.php`:** Update `tickets.assignment.update` (`PATCH /tickets/{ticket}/assignment`) middleware from `role:superadmin,engineer` to `role:superadmin`.
  - **`TicketController::updateAssignment()`:**
    - Enforce `abort_unless($user->isSuperAdmin(), 403);`.
    - Remove the `if ($request->input('action') === 'claim')` branch completely so all assignment updates validate explicit `lead_engineer_id` and `collaborator_ids`.
    - Restrict `Rule::exists('users', 'id')` on both `lead_engineer_id` and `collaborator_ids.*` to `where('is_active', true)->where('role', User::ROLE_ENGINEER)`.
  - **`TicketController::activeStaffList()`:** Change `->whereIn('role', [User::ROLE_SUPERADMIN, User::ROLE_ENGINEER])` to `->where('role', User::ROLE_ENGINEER)` so `engineers` Inertia props on `Tickets/Index`, `Tickets/Kanban`, and `Tickets/Show` omit `superadmin` accounts.
  - **`TicketSubtaskController::store()`:** Tighten `assigned_engineer_id` validation from `exists:users,id` to `Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)->where('role', User::ROLE_ENGINEER))`.
  - **`ReportController::buildReportData()`:** Change `$staffUsers` query from `whereIn('role', [User::ROLE_ENGINEER, User::ROLE_SUPERADMIN])` to `where('role', User::ROLE_ENGINEER)` so `by_engineer` metrics and the `engineers` CSV export exclude `superadmin` accounts.

- **Workflow, Subtask, Comment & Attachment Access Control:**
  - **`TicketController::updateWorkflow()`:** Allow staff workflow updates (`handleStaffWorkflowUpdate`) only when `$user->isSuperAdmin() || ($user->isEngineer() && $ticket->isAssignedTo($user))`. If an unassigned `engineer` requests `PATCH /tickets/{ticket}/workflow`, abort with `403 Forbidden`. Preserve `handleRequesterWorkflowUpdate` for non-staff requesters.
  - **`TicketController::buildTicketPermissions(Ticket $ticket, User $user)`:**
    - Define `$canWorkOnTicket = $user->isSuperAdmin() || ($user->isEngineer() && $ticket->isAssignedTo($user));`.
    - Set `can_manage_workflow`, `can_post_internal_note`, and `can_manage_subtasks` to `$canWorkOnTicket`.
    - Set `can_assign` to `$user->isSuperAdmin()`.
    - Set `can_close_or_reopen` and `can_cancel` to `$canWorkOnTicket || (<existing requester status conditions>)`.
  - **`TicketSubtaskController` (`store`, `toggle`, `destroy`):** Enforce `$user->isSuperAdmin() || ($user->isEngineer() && $ticket->isAssignedTo($user))` via `abort_unless(..., 403)` before creating, toggling, or removing subtasks.
  - **`Ticket::visibleTo($user)` Enforcement Across Read/Comment/Attachment/Board/Dashboard Endpoints:**
    - `TicketController::show()`: Already checks `Ticket::visibleTo($user)->whereKey($ticket->id)->exists()` and aborts with `403` for unassigned engineers.
    - `TicketCommentController::store()`: Already checks `Ticket::visibleTo($user)->whereKey($ticket->id)->exists()` and aborts with `403` for unassigned engineers.
    - `TicketController::downloadAttachment()`: Already checks `Ticket::visibleTo($user)->whereKey($attachment->ticket_id)->exists()` and aborts with `403` for unassigned engineers.
    - `TicketController::index()` & `TicketController::kanban()`: Already scope queries and `selectedTicket` resolution through `Ticket::visibleTo($user)`, ensuring unassigned engineers do not see unassigned tickets and receive `selectedTicket: null` (HTTP 200) on `/tickets/kanban?ticket={unassigned_id}`.
    - `DashboardController::index()`: Already scopes `$baseQuery = Ticket::visibleTo($user)` across `metrics`, `recent_tickets`, `attention_tickets`, and `recent_activities`. Gate the open-unassigned `orWhere` clause inside staff `attention_tickets` explicitly on `$user->isSuperAdmin()` so engineers only see their active assigned tickets.

- **Frontend UI Alignment:**
  - **`resources/js/Components/TicketDetailWorkspace.vue`:** Remove `claimAsLead()` and the `Claim ticket` button in the Assignment header while preserving the assignment form under `v-if="permissions.can_assign"` (shown to `superadmin`) and the read-only `<dl>` summary under `v-else` (shown to `engineer` and `user`).
  - **`resources/js/Pages/Tickets/Index.vue`:** Restrict `#filter-lead` `<select>` from `v-if="user?.is_staff"` to `v-if="user?.is_super_admin"`, and restrict `<option value="unassigned">` in `#filter-scope` with `v-if="!user?.is_engineer"`.
  - **`resources/js/Pages/Tickets/Kanban.vue`:** Import `usePage` from `@inertiajs/vue3`, compute `user = computed(() => page.props.auth?.user || null)`, and restrict both `#kanban-lead` `<select>` and `<option value="unassigned">` in `#kanban-scope` with `v-if="user?.is_super_admin"`.
