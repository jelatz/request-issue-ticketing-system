# Role-Based Task Assignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restrict ticket assignment management to `superadmin`, limit assignable engineer rosters to active `engineer` accounts, and isolate `engineer` visibility and ticket mutation access strictly to tickets assigned to them.

**Architecture:** Introduce `Ticket::isAssignedTo(User $user): bool` and tighten `Ticket::scopeVisibleTo(Builder $query, User $user): Builder` so `superadmin` sees all tickets while `engineer` sees only tickets where they are `lead_engineer_id` or in `collaborators`. Enforce `role:superadmin` middleware and controller-level assignment checks, validate assignable users strictly against active `User::ROLE_ENGINEER` records, gate workflow/subtask mutations on assignment, and align Vue workspace and filter bars with `permissions.can_assign`, `user.is_super_admin`, and `user.is_engineer`.

**Tech Stack:** Laravel 13, Inertia.js v3 (Vue 3), Tailwind CSS v4, PHPUnit 12, Vite 8

**Spec:** `docs/superpowers/specs/2026-11-18-role-based-task-assignment-design.md`

## Global Constraints

- Strictly observe the No Icons rule: zero emojis, zero `<svg>` tags, zero `<i>` icon tags, and zero FontAwesome classes anywhere in code, templates, or comments.
- Follow TDD: write or update tests first, verify targeted failures before implementation, and pass the full test suite before committing.
- Remove the self-service `action: 'claim'` backend branch and `Claim ticket` UI button completely.
- Restrict assignment modification (`PATCH /tickets/{ticket}/assignment`, `permissions.can_assign`) exclusively to `User::ROLE_SUPERADMIN`.
- Restrict all assignable user pools (`TicketController::activeStaffList()`, `TicketController::updateAssignment()`, `TicketSubtaskController::store()`, `ReportController::buildReportData()`) strictly to active users with `role = 'engineer'` (`User::ROLE_ENGINEER`), excluding `superadmin`.
- Restrict `User::ROLE_ENGINEER` ticket visibility and mutation access (viewing, workflow/triage updates, subtasks, comments/internal notes, attachment downloads) strictly to tickets where the engineer is assigned as `lead_engineer_id` or in `collaborators`.

## Review Focus

1. **Direct HTTP assignment requests by an engineer (standard payload or legacy `action: 'claim'`):** `PATCH /tickets/{ticket}/assignment` with either `lead_engineer_id` / `collaborator_ids` or `action: 'claim'` must return `403 Forbidden` for `engineer` (and `user`), and `superadmin` sending `action: 'claim'` must not self-claim via a legacy branch.
2. **Assigning a non-engineer (`superadmin`, standard `user`, or inactive `engineer`):** Submitting a `superadmin`, `user`, or `is_active = false` user ID in `lead_engineer_id`, `collaborator_ids.*`, or subtask `assigned_engineer_id` must fail validation with session errors (`422` / redirect with errors), and the `engineers` Inertia prop across `/tickets`, `/tickets/kanban`, and `/tickets/{ticket}` must omit `superadmin` accounts.
3. **Unassigned engineer direct URL access or mutation on another ticket:** An unassigned `engineer` requesting `GET /tickets/{ticket}`, `PATCH /tickets/{ticket}/workflow`, `POST /tickets/{ticket}/comments`, `GET /attachments/{attachment}/download`, `POST /tickets/{ticket}/subtasks`, `PATCH /tickets/{ticket}/subtasks/{subtask}/toggle`, or `DELETE /tickets/{ticket}/subtasks/{subtask}` must receive `403 Forbidden`, while `/tickets/kanban?ticket={unassigned_id}` must return `200 OK` with `selectedTicket: null` and `selectedTicketPermissions: null`.
4. **Collaborating engineer retaining full work access without assignment rights:** An `engineer` listed only in `ticket_collaborators` (not `lead_engineer_id`) must retain `200 OK` visibility, workflow modification, subtask creation/toggle/deletion, public/internal commenting, and attachment download access while `permissions.can_assign` and `selectedTicketPermissions.can_assign` remain `false`.
5. **Immediate revocation upon unassignment and exclusion of unassigned tickets from engineer views:** When a `superadmin` removes an `engineer` from `lead_engineer_id` and `collaborator_ids`, that engineer must immediately lose visibility and mutation access (`403 Forbidden`) on subsequent requests, and unassigned tickets must not appear in `/dashboard` (`attention_tickets`, `recent_tickets`, `metrics`), `/tickets`, or `/tickets/kanban` for `engineer` users.

---

### Task 1: Backend Role-Based Ticket Visibility, Assignment Authorization & Engineer-Only Validation

**Files:**
- Modify: `app/Models/Ticket.php:159-178`
- Modify: `routes/web.php:41-43`
- Modify: `app/Http/Controllers/TicketController.php:288-358`, `825-860`
- Modify: `app/Http/Controllers/TicketSubtaskController.php:13-101`
- Modify: `app/Http/Controllers/DashboardController.php:55-76`
- Modify: `app/Http/Controllers/ReportController.php:272-276`
- Test: `tests/Feature/TicketingBackendTest.php:178-203`, `416-485`, `618-693`, `825-936`

**Interfaces:**
- Produces on `App\Models\Ticket`:
  - `public function isAssignedTo(User $user): bool`
  - `public function scopeVisibleTo(Builder $query, User $user): Builder`
- Produces on `App\Http\Controllers\TicketController`:
  - `private function buildTicketPermissions(Ticket $ticket, User $user): array` (returning `can_assign => $user->isSuperAdmin()` and `can_manage_workflow` / `can_post_internal_note` / `can_manage_subtasks` scoped to `$user->isSuperAdmin() || ($user->isEngineer() && $ticket->isAssignedTo($user))`)
  - `private function activeStaffList()` (returning active `User::ROLE_ENGINEER` records only, passed as `engineers` Inertia prop to `Tickets/Index`, `Tickets/Kanban`, and `Tickets/Show`)

- [ ] **Step 1: Update affected existing tests and add new role-based assignment/visibility feature tests in `tests/Feature/TicketingBackendTest.php`**

Update the 4 existing tests in `tests/Feature/TicketingBackendTest.php`:
- `test_ticket_visibility_isolation_between_standard_users_and_staff`:
  - Change line 201 expectation so an unassigned `$engineer` receives `assertForbidden()` on `GET /tickets/{$ticketUser2->id}`, while `$superadmin` receives `assertOk()`, and a second assigned engineer (via `lead_engineer_id` or `collaborators`) receives `assertOk()`.
- `test_reports_and_csv_export_succeed_for_engineers_and_superadmins`:
  - Assert `by_engineer` on `Reports/Index` contains `$engineer->id` and excludes `$superadmin->id`.
- `test_claim_subtask_destroy_requester_cancel_and_invalid_workflow_transitions_flash_feedback`:
  - Replace the `$engineer` self-claim step with assertions that `$engineer` sending `action: 'claim'` to `tickets.assignment.update` receives `assertForbidden()`, then have a `$superadmin` assign `$engineer->id` as `lead_engineer_id` before `$engineer` creates and destroys the subtask.
- `test_kanban_loads_selected_ticket_details_and_permissions_when_ticket_query_present`:
  - Update `selectedTicketPermissions.can_assign` assertion for `$engineer` from `true` to `false` (while keeping `can_manage_workflow`, `can_post_internal_note`, `can_manage_subtasks`, `can_close_or_reopen`, and `can_cancel` as `true`).

Add 5 new test methods to `tests/Feature/TicketingBackendTest.php` pinning all 5 Review Focus scenarios:
- `test_only_superadmin_can_update_ticket_assignment_and_claim_action_is_removed`:
  - Asserts `PATCH /tickets/{ticket}/assignment` returns `403 Forbidden` for an `engineer` (both with `lead_engineer_id` payload and with `action: 'claim'`) and for a standard `user`.
  - Asserts `superadmin` sending `action: 'claim'` does not assign `superadmin` as lead (keeps `lead_engineer_id === null` since `claim` branch is removed and payload validates `lead_engineer_id`).
  - Asserts `Show.vue` `permissions.can_assign` is `true` for `superadmin` and `false` for assigned `engineer`.
- `test_assignment_and_subtask_pools_reject_superadmins_standard_users_and_inactive_engineers`:
  - Asserts `PATCH /tickets/{ticket}/assignment` fails validation (`assertSessionHasErrors('lead_engineer_id')` and `assertSessionHasErrors('collaborator_ids.0')`) when given a `superadmin`, standard `user`, or inactive `engineer` ID.
  - Asserts `POST /tickets/{ticket}/subtasks` fails validation (`assertSessionHasErrors('assigned_engineer_id')`) when given a `superadmin`, standard `user`, or inactive `engineer` ID.
  - Asserts `engineers` Inertia prop on `/tickets`, `/tickets/kanban`, and `/tickets/{ticket}` contains active `engineer` accounts and omits `superadmin` and inactive `engineer` accounts.
- `test_unassigned_engineer_is_forbidden_from_viewing_or_mutating_unassigned_tickets`:
  - Creates an unassigned ticket (and an assigned ticket for another engineer) with an attachment and a subtask.
  - Asserts the unassigned `engineer` receives `403 Forbidden` on `GET /tickets/{ticket}`, `PATCH /tickets/{ticket}/workflow`, `POST /tickets/{ticket}/comments`, `GET /attachments/{attachment}/download`, `POST /tickets/{ticket}/subtasks`, `PATCH /tickets/{ticket}/subtasks/{subtask}/toggle`, and `DELETE /tickets/{ticket}/subtasks/{subtask}`.
  - Asserts `/tickets/kanban?ticket={$ticket->id}` returns `200 OK` with `selectedTicket: null` and `selectedTicketPermissions: null`.
- `test_collaborating_engineer_retains_work_access_without_assignment_rights`:
  - Assigns `engineerA` as `lead_engineer_id` and `engineerB` in `collaborators`.
  - Asserts `engineerB` can load `/tickets/{ticket}` with `permissions.can_assign === false` and `can_manage_workflow`, `can_post_internal_note`, `can_manage_subtasks` all `true`.
  - Asserts `engineerB` can `PATCH` workflow, `POST` internal comment, `POST`/`PATCH`/`DELETE` subtasks, and download ticket attachments, while `PATCH /tickets/{ticket}/assignment` returns `403 Forbidden`.
- `test_unassigning_engineer_immediately_revokes_access_and_hides_tickets_from_dashboard_lists_and_kanban`:
  - Assigns `$engineer` to a ticket, verifies it appears in `/dashboard` (`attention_tickets`, `recent_tickets`, `metrics.total_visible`), `/tickets`, and `/tickets/kanban`.
  - Has `$superadmin` clear `lead_engineer_id` and `collaborator_ids` (`lead_engineer_id: null, collaborator_ids: []`).
  - Asserts `$engineer` immediately receives `403 Forbidden` on `/tickets/{ticket}` and workflow/comment/subtask mutations, and that the unassigned ticket is excluded from `/dashboard` (`attention_tickets`, `recent_tickets`, `metrics.total_visible === 0`), `/tickets`, and `/tickets/kanban` while remaining visible to `$superadmin`.

- [ ] **Step 2: Run targeted tests to verify they fail**

Run: `php artisan test --filter="test_ticket_visibility_isolation_between_standard_users_and_staff|test_reports_and_csv_export_succeed_for_engineers_and_superadmins|test_claim_subtask_destroy_requester_cancel_and_invalid_workflow_transitions_flash_feedback|test_kanban_loads_selected_ticket_details_and_permissions_when_ticket_query_present|test_only_superadmin_can_update_ticket_assignment_and_claim_action_is_removed|test_assignment_and_subtask_pools_reject_superadmins_standard_users_and_inactive_engineers|test_unassigned_engineer_is_forbidden_from_viewing_or_mutating_unassigned_tickets|test_collaborating_engineer_retains_work_access_without_assignment_rights|test_unassigning_engineer_immediately_revokes_access_and_hides_tickets_from_dashboard_lists_and_kanban"`
Expected: FAIL on existing visibility/claim/kanban/report assertions and all 5 new tests.

- [ ] **Step 3: Implement backend model scoping, middleware, authorization, and engineer-only validation**

Apply the targeted backend updates:
- **`app/Models/Ticket.php`:**
  - Add `public function isAssignedTo(User $user): bool` returning `true` if `(int) $this->lead_engineer_id === (int) $user->id` or if `$user->id` exists in `collaborators` (check `$this->collaborators->contains('id', $user->id)` when relation loaded, else `$this->collaborators()->where('users.id', $user->id)->exists()`).
  - Update `scopeVisibleTo(Builder $query, User $user): Builder`:
    - If `$user->isSuperAdmin()`, return `$query`.
    - If `$user->isEngineer()`, scope to `lead_engineer_id = $user->id` or `whereHas('collaborators', fn (Builder $collabQuery) => $collabQuery->where('users.id', $user->id))`.
    - Otherwise (`User::ROLE_USER`), keep existing `requester_id = $user->id` or collaborator membership query.
- **`routes/web.php`:**
  - Change `tickets.assignment.update` middleware on line 42 from `role:superadmin,engineer` to `role:superadmin`.
- **`app/Http/Controllers/TicketController.php`:**
  - In `updateWorkflow()`: allow `handleStaffWorkflowUpdate(...)` when `$user->isSuperAdmin() || ($user->isEngineer() && $ticket->isAssignedTo($user))`; if `$user->isEngineer()` and not assigned, `abort(403, 'You do not have permission to update this ticket workflow.')`; otherwise allow `$ticket->requester_id === $user->id` via `handleRequesterWorkflowUpdate(...)`, else `abort(403, ...)`.
  - In `updateAssignment()`: change `abort_unless($user->isStaff(), 403)` to `abort_unless($user->isSuperAdmin(), 403)`; remove the `if ($request->input('action') === 'claim')` branch completely; update `Rule::exists('users', 'id')` on `lead_engineer_id` and `collaborator_ids.*` to `->where('is_active', true)->where('role', User::ROLE_ENGINEER)`.
  - In `buildTicketPermissions(Ticket $ticket, User $user): array`: compute `$canWorkOnTicket = $user->isSuperAdmin() || ($user->isEngineer() && $ticket->isAssignedTo($user))`; set `can_manage_workflow`, `can_post_internal_note`, `can_manage_subtasks` to `$canWorkOnTicket`; set `can_assign` to `$user->isSuperAdmin()`; set `can_close_or_reopen` and `can_cancel` to `$canWorkOnTicket || (<requester condition>)`.
  - In `activeStaffList()`: change `->whereIn('role', [User::ROLE_SUPERADMIN, User::ROLE_ENGINEER])` to `->where('role', User::ROLE_ENGINEER)`.
- **`app/Http/Controllers/TicketSubtaskController.php`:**
  - Add a private guard (or inline check) in `store()`, `toggle()`, and `destroy()` enforcing `abort_unless($user->isSuperAdmin() || ($user->isEngineer() && $ticket->isAssignedTo($user)), 403, 'You do not have permission to manage subtasks on this ticket.')`.
  - In `store()`: update `assigned_engineer_id` validation rule to `['nullable', Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true)->where('role', User::ROLE_ENGINEER))]`.
- **`app/Http/Controllers/DashboardController.php`:**
  - In `index()`, inside `if ($user->isStaff())` for `$attentionTickets`, apply the `orWhere(open + null lead_engineer_id)` clause only when `$user->isSuperAdmin()`.
- **`app/Http/Controllers/ReportController.php`:**
  - In `buildReportData()`, change `$staffUsers` query from `->whereIn('role', [User::ROLE_ENGINEER, User::ROLE_SUPERADMIN])` to `->where('role', User::ROLE_ENGINEER)`.

- [ ] **Step 4: Run full backend test suite to verify all tests pass**

Run: `php artisan test`
Expected: PASS (all existing and newly added PHPUnit tests pass with zero failures or skipped assertions).

- [ ] **Step 5: Commit Task 1 changes**

```bash
git add app/Models/Ticket.php routes/web.php app/Http/Controllers/TicketController.php app/Http/Controllers/TicketSubtaskController.php app/Http/Controllers/DashboardController.php app/Http/Controllers/ReportController.php tests/Feature/TicketingBackendTest.php
git commit -m "feat: enforce role-based ticket visibility and superadmin-only assignment"
```

---

### Task 2: Frontend Assignment Section & Filter Bar Role Adjustments

**Files:**
- Modify: `resources/js/Components/TicketDetailWorkspace.vue:286-293`, `1020-1036`
- Modify: `resources/js/Pages/Tickets/Index.vue:282-310`
- Modify: `resources/js/Pages/Tickets/Kanban.vue:1-38`, `309-362`

**Interfaces:**
- Consumes from Inertia shared/page props:
  - `permissions.can_assign: boolean` (true only for `superadmin`)
  - `page.props.auth.user.is_super_admin: boolean`
  - `page.props.auth.user.is_engineer: boolean`
  - `engineers: Array<{id: number, name: string, username: string, role: string, job_title: ?string}>` (already filtered to active `engineer` accounts in Task 1)

- [ ] **Step 1: Remove `claimAsLead()` and `Claim ticket` button in `resources/js/Components/TicketDetailWorkspace.vue`**

- Remove the `claimAsLead()` function (lines 286-292).
- Remove the `Claim ticket` `<button>` in the Assignment section header (lines 1025-1032).
- Keep `<form v-if="permissions.can_assign" ...>` (shown to `superadmin`) and `<dl v-else ...>` (read-only lead and collaborators display shown to `engineer` and `user`) unchanged.
- Verify zero icons/SVGs/emojis are present.

- [ ] **Step 2: Restrict lead filter and unassigned scope in `resources/js/Pages/Tickets/Index.vue`**

- Change `#filter-lead` `<select>` condition from `v-if="user?.is_staff"` to `v-if="user?.is_super_admin"`.
- Add `v-if="!user?.is_engineer"` to `<option value="unassigned">Unassigned</option>` inside `#filter-scope` so `engineer` users only see relevant scope options (`All visible`, `Led by me`, `Collaborating`, `Submitted by me`, `Overdue`).

- [ ] **Step 3: Restrict lead filter and unassigned scope in `resources/js/Pages/Tickets/Kanban.vue`**

- Import `usePage` from `@inertiajs/vue3` alongside `Head`, `Link`, and `router`.
- Add `const page = usePage();` and `const user = computed(() => page.props.auth?.user || null);`.
- Add `v-if="user?.is_super_admin"` to `#kanban-lead` `<select>`.
- Add `v-if="user?.is_super_admin"` to `<option value="unassigned">Unassigned</option>` inside `#kanban-scope`.

- [ ] **Step 4: Verify frontend build and full test suite**

Run:
- `npm run build`
- `php artisan test`
Expected: `vite build` completes with zero errors and all PHPUnit tests pass.

- [ ] **Step 5: Commit Task 2 changes**

```bash
git add resources/js/Components/TicketDetailWorkspace.vue resources/js/Pages/Tickets/Index.vue resources/js/Pages/Tickets/Kanban.vue
git commit -m "feat: align assignment controls and ticket filters with user roles"
```

---

## Self-Review

- **Spec coverage:**
  - `Ticket::isAssignedTo()` and `Ticket::scopeVisibleTo()` role isolation: covered in Task 1.
  - `superadmin`-only `PATCH /tickets/{ticket}/assignment` and removal of `action: 'claim'`: covered in Task 1 (`routes/web.php`, `TicketController::updateAssignment()`, `buildTicketPermissions()`) and Task 2 (`TicketDetailWorkspace.vue`).
  - Engineer-only assignable pool (`activeStaffList()`, `updateAssignment()`, `TicketSubtaskController::store()`, `ReportController::buildReportData()`): covered in Task 1.
  - Workflow, subtask, comment, attachment, index, kanban, and dashboard isolation for unassigned engineers: covered in Task 1.
  - Frontend `#filter-lead`, `#kanban-lead`, and `unassigned` scope restrictions: covered in Task 2.
- **Step scan:** Every step identifies exact files, line ranges, method signatures, validation rules, and verification commands without placeholder instructions or full-file boilerplate dumps.
- **Type consistency:** `isAssignedTo(User $user): bool`, `scopeVisibleTo(Builder $query, User $user): Builder`, `buildTicketPermissions(Ticket $ticket, User $user): array`, `activeStaffList()`, `permissions.can_assign`, `user.is_super_admin`, and `user.is_engineer` match across the spec, Task 1, Task 2, and existing codebase (`HandleInertiaRequests.php`).
- **Review Focus coverage:** All 5 Review Focus failure modes have dedicated assertions in the 5 new feature tests plus updated existing tests in Task 1.
- **Proportion:** Concise 2-task plan proportional to the design spec, avoiding unnecessary code transcripts while keeping every step unambiguous.
