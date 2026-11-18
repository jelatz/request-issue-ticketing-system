# Kanban Task Full-Screen Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Open any clicked Kanban board ticket inside a full-screen modal where staff users can view all ticket details, edit workflow/triage fields, update assignments, manage subtasks, and post comments or internal notes without leaving `/tickets/kanban`.

**Architecture:** Extract the interactive ticket detail workspace from `Tickets/Show.vue` into a reusable `TicketDetailWorkspace.vue` component wrapped by a new full-screen `TicketDetailModal.vue` on `Tickets/Kanban.vue`. Drive modal state via the `?ticket={id}` query parameter on `TicketController::kanban()` using Inertia partial reloads (`only: ['selectedTicket', 'selectedTicketPermissions']`) so existing `back()` endpoints refresh both the modal and board columns in one round-trip.

**Tech Stack:** Laravel 13, Inertia.js v3 (Vue 3), Tailwind CSS v4, PHPUnit 12, Vite 8

**Spec:** `docs/superpowers/specs/2026-11-18-kanban-task-fullscreen-modal-design.md`

## Global Constraints

- Follow the full-screen modal layout and accessibility pattern in `resources/js/Components/NewTicketModal.vue` (`Teleport to="body"`, `fixed inset-0 z-50 flex flex-col bg-[#F8FAFC]`, `role="dialog"`, `aria-modal="true"`, sticky header with `Close` button, capture-phase `Escape` key handler, and `document.body.style.overflow` scroll lock).
- Strictly observe the No Icons rule: no FontAwesome classes, `<i>` icon tags, `<svg>` icons, or emojis in any templates, code, or comments.
- Reuse existing Laravel update routes (`tickets.workflow.update`, `tickets.assignment.update`, `tickets.comments.store`, `tickets.subtasks.store`, `tickets.subtasks.toggle`, `tickets.subtasks.destroy`) and `back()` redirects without duplicating backend mutation endpoints.
- Keep `/tickets/kanban` staff-only (`superadmin` and `engineer`) and preserve `/tickets/{ticket}` (`Show.vue`) standalone behavior.
- Do not duplicate ticket detail markup/form logic across `Show.vue` and `TicketDetailModal.vue`.

## Review Focus

1. **Stale, missing, or non-visible `?ticket=` query parameter on `/tickets/kanban`:** Must return `selectedTicket: null` and `selectedTicketPermissions: null` cleanly (HTTP 200) instead of throwing 404/403 on the board or leaking inaccessible ticket data.
2. **In-modal mutations returning via `back()`:** Updating workflow status (including to `closed` or `cancelled`), posting comments/internal notes, or adding subtasks from `/tickets/kanban?ticket={id}` must redirect back to `/tickets/kanban?ticket={id}` with refreshed `selectedTicket`, refreshed `columns`, and `flash.success`.
3. **Form and tab state leakage across ticket switches:** Switching from Ticket A to Ticket B in the modal must reset transient form state (`commentForm`, `subtaskForm`, `showReopenInput`, `reopenReason`, `activeActivityTab`) so drafts or validation errors never carry over.
4. **Card click vs. drag-and-drop and footer status selector:** Dragging a card between columns or interacting with the card's footer `<select>` must not open the modal; `Escape` must close the modal even when focus is inside form controls and always restore `document.body.style.overflow`.
5. **Active filter preservation:** Opening and closing the ticket modal on `/tickets/kanban` must preserve active board filters (`search`, `type`, `priority`, `internal_system_id`, `lead_engineer_id`, `scope`) in both the URL and filter state.

---

### Task 1: Backend `TicketController::kanban()` Selected Ticket Support & Shared Helpers

**Files:**
- Modify: `app/Http/Controllers/TicketController.php:109-150` (`kanban`) and `262-324` (`show`)
- Test: `tests/Feature/TicketingBackendTest.php`

**Interfaces:**
- Produces (Inertia props on `Tickets/Kanban`):
  - `selectedTicket`: `?Ticket` (same eager-loaded relations as `Tickets/Show`: `internalSystem`, `requester`, `leadEngineer`, `collaborators`, `subtasks.assignedEngineer`, `activities.user`, `comments.user`, `comments.attachments`, `attachments.uploadedBy`)
  - `selectedTicketPermissions`: `?array{can_manage_workflow: bool, can_assign: bool, can_post_internal_note: bool, can_manage_subtasks: bool, can_close_or_reopen: bool, can_cancel: bool}`
- Internal helpers in `App\Http\Controllers\TicketController`:
  - `private function loadTicketDetails(Ticket $ticket, bool $isStaff): Ticket`
  - `private function buildTicketPermissions(Ticket $ticket, User $user): array`

- [ ] **Step 1: Write failing feature tests for Kanban selected ticket loading and in-modal `back()` mutations**

Add two test methods to `tests/Feature/TicketingBackendTest.php`:
- `test_kanban_loads_selected_ticket_details_and_permissions_when_ticket_query_present`:
  - Verifies `/tickets/kanban` without `?ticket` returns `selectedTicket: null` and `selectedTicketPermissions: null`.
  - Verifies `/tickets/kanban?ticket={$ticket->id}` as an engineer returns `selectedTicket` with `id`, `comments`, `subtasks`, `attachments`, `activities`, and `selectedTicketPermissions` (`can_manage_workflow => true`, `can_assign => true`, `can_post_internal_note => true`, `can_manage_subtasks => true`).
  - Verifies `/tickets/kanban?ticket=999999` (non-existent ID) returns HTTP 200 with `selectedTicket: null` and `selectedTicketPermissions: null`.
- `test_kanban_modal_mutations_preserve_selected_ticket_and_refresh_board_columns`:
  - Using `followingRedirects()->from(route('tickets.kanban', ['ticket' => $ticket->id, 'priority' => 'high']))`, patches `tickets.workflow.update` (changing status to `in_progress` and updating `resolution_summary`), posts `tickets.comments.store` (posting an internal note), and posts `tickets.subtasks.store`.
  - Asserts each response returns HTTP 200 on `Tickets/Kanban` with updated `selectedTicket` (new status, comment count, subtask count), updated `columns.in_progress`, and `flash.success`.
  - Also asserts transitioning to `cancelled` from `/tickets/kanban?ticket={$ticket->id}` still keeps `selectedTicket.status === 'cancelled'` while removing it from active board columns.

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `php artisan test --filter=test_kanban_`
Expected: FAIL because `selectedTicket` and `selectedTicketPermissions` props are not returned by `TicketController::kanban()`.

- [ ] **Step 3: Extract `loadTicketDetails()` and `buildTicketPermissions()` and update `kanban()` in `app/Http/Controllers/TicketController.php`**

- Extract the `$ticket->load([...])` logic from `show()` into `private function loadTicketDetails(Ticket $ticket, bool $isStaff): Ticket` and the permissions array into `private function buildTicketPermissions(Ticket $ticket, User $user): array`.
- Update `show()` to call both helpers.
- Update `kanban(Request $request)` to inspect `$selectedTicketId = $request->integer('ticket')`:
  - If `$selectedTicketId > 0`, query `Ticket::visibleTo($user)->find($selectedTicketId)`.
  - If found, pass `'selectedTicket' => $this->loadTicketDetails($selectedTicket, $user->isStaff())` and `'selectedTicketPermissions' => $this->buildTicketPermissions($selectedTicket, $user)`.
  - Otherwise pass `'selectedTicket' => null` and `'selectedTicketPermissions' => null`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=test_kanban_`
Expected: PASS (2 tests passed).

---

### Task 2: Extract `TicketDetailWorkspace.vue` and Refactor `Tickets/Show.vue`

**Files:**
- Create: `resources/js/Components/TicketDetailWorkspace.vue`
- Modify: `resources/js/Pages/Tickets/Show.vue`

**Interfaces:**
- `TicketDetailWorkspace.vue`:
  - Props:
    - `ticket: { type: Object, required: true }`
    - `engineers: { type: Array, default: () => [] }`
    - `permissions: { type: Object, required: true }`
  - Exposed via `defineExpose`:
    - `cancelTicket: () => void`
    - `requesterActionProcessing: Ref<boolean>`
- Consumes: Inertia `useForm`, `usePage`, `router`, existing components (`StatusBadge`, `PriorityBadge`, `TypeBadge`, `RoleBadge`), and `utils/formatters.js`.

- [ ] **Step 1: Create `resources/js/Components/TicketDetailWorkspace.vue`**

Move the ticket body logic and markup from `resources/js/Pages/Tickets/Show.vue` into `TicketDetailWorkspace.vue`:
- Action error alert (`pageErrors.action || pageErrors.reopen_reason`), Requester verification/action banner, and the two-column workspace (`Description & Diagnostics`, `Subtasks`, `Attachments`, `Comments & Audit log` tabs, and right properties rail with `Workflow & triage`, `Assignment`, `Details`).
- Preserve all existing form payloads, validation error displays, `watch(() => props.ticket, ...)` sync for `workflowForm` and `assignmentForm`, and `cancelTicket()` behavior.
- Add a watcher on `() => props.ticket.id` that resets transient per-ticket state (`activeActivityTab.value = 'comments'`, `reopenReason.value = ''`, `showReopenInput.value = false`, `subtaskForm.reset()`, `subtaskForm.clearErrors()`, `commentForm.reset()`, `commentForm.clearErrors()`, `commentForm.is_internal = false`) when switching tickets.
- Call `defineExpose({ cancelTicket, requesterActionProcessing })` so parent headers (`Show.vue` and `TicketDetailModal.vue`) reuse the exact same cancel handler without duplicating logic.
- Ensure zero icons/SVGs/emojis are introduced.

- [ ] **Step 2: Refactor `resources/js/Pages/Tickets/Show.vue` to render `TicketDetailWorkspace`**

- Keep `<Head>`, `<AppLayout>`, and the compact sticky page header (`Tickets / {ticket_number}`, `Cancel ticket` button calling `workspaceRef?.cancelTicket()`, `Back to queue` link, title, and status/priority/type/due-date badges).
- Render `<TicketDetailWorkspace ref="workspaceRef" :ticket="ticket" :engineers="engineers" :permissions="permissions" />`.

- [ ] **Step 3: Run frontend build to verify compilation**

Run: `npm run build`
Expected: PASS (`vite build` completes with zero errors).

---

### Task 3: Create `TicketDetailModal.vue` and Integrate with `Tickets/Kanban.vue`

**Files:**
- Create: `resources/js/Components/TicketDetailModal.vue`
- Modify: `resources/js/Pages/Tickets/Kanban.vue`

**Interfaces:**
- `TicketDetailModal.vue`:
  - Props:
    - `show: { type: Boolean, default: false }`
    - `ticket: { type: Object, default: null }`
    - `engineers: { type: Array, default: () => [] }`
    - `permissions: { type: Object, default: null }`
  - Emits: `['close']`
  - Consumes: `TicketDetailWorkspace` (Task 2), `StatusBadge`, `PriorityBadge`, `TypeBadge`, `formatDate`, `isTicketOverdue`.
- `Kanban.vue`:
  - Props added:
    - `selectedTicket: { type: Object, default: null }`
    - `selectedTicketPermissions: { type: Object, default: null }`

- [ ] **Step 1: Create `resources/js/Components/TicketDetailModal.vue`**

Implement the full-screen modal matching `NewTicketModal.vue`:
- `Teleport to="body"` with `v-if="show && ticket && permissions"`, `role="dialog"`, `aria-modal="true"`, `aria-labelledby="ticket-detail-modal-title"`, and `class="fixed inset-0 z-50 flex flex-col bg-[#F8FAFC] text-slate-900"`.
- Sticky top header (`bg-white border-b border-slate-200 px-4 sm:px-6 lg:px-8 py-3 shrink-0`):
  - Displays `ticket.ticket_number` (monospace), `ticket.title` (`id="ticket-detail-modal-title"`), `StatusBadge`, `PriorityBadge`, `TypeBadge`, and overdue/due pill.
  - Right controls: `Cancel ticket` button (when `permissions?.can_cancel && !['cancelled', 'closed'].includes(ticket.status)`, disabled when `workspaceRef?.requesterActionProcessing`) and `Close` button (`@click="close"`).
- Scrollable body (`ref="scrollContainerRef"`, `class="flex-1 overflow-y-auto px-4 sm:px-6 lg:px-8 py-6"`):
  - Centers `<div class="max-w-7xl mx-auto">` containing `<TicketDetailWorkspace ref="workspaceRef" :ticket="ticket" :engineers="engineers" :permissions="permissions" />`.
- Keyboard & scroll-lock lifecycle:
  - Capture-phase `keydown` listener on `window` closing on `Escape` when `props.show` is true (`event.stopPropagation(); close();`).
  - Watch `() => props.show` to toggle `document.body.style.overflow = isOpen ? 'hidden' : ''` and reset scroll position to top when `props.ticket?.id` changes; always clear `document.body.style.overflow = ''` on `onUnmounted`.
- Ensure zero icons/SVGs/emojis.

- [ ] **Step 2: Update `resources/js/Pages/Tickets/Kanban.vue` to open and close `TicketDetailModal`**

- Add `selectedTicket` and `selectedTicketPermissions` props and import `TicketDetailModal`.
- Extract `buildFilterParams()` so `applyFilters()`, `openTicketModal(ticket)`, and `closeTicketModal()` all share the exact active filter query parameters (`search`, `type`, `priority`, `internal_system_id`, `lead_engineer_id`, `scope`).
- Track `isDragging` (`true` on `onDragStart`, cleared via `setTimeout(..., 0)` in `onDragEnd`) so dragging a card never fires `openTicketModal`.
- Replace the `<Link :href="/tickets/${ticket.id}">` tags on the card's `ticket_number` and `title` with non-navigating elements, add `cursor-pointer` (plus `active:cursor-grabbing`), `@click="openTicketModal(ticket)"`, and keyboard activation (`tabindex="0"`, `@keydown.enter.self="openTicketModal(ticket)"`) on the card container while keeping `@click.stop` and `@mousedown.stop` on the status `<select>`.
- Implement:
  - `openTicketModal(ticket)`: guards against `isDragging.value`, then calls `router.get('/tickets/kanban', { ...buildFilterParams(), ticket: ticket.id }, { preserveState: true, preserveScroll: true, only: ['selectedTicket', 'selectedTicketPermissions'] })`.
  - `closeTicketModal()`: calls `router.get('/tickets/kanban', buildFilterParams(), { preserveState: true, preserveScroll: true, only: ['selectedTicket', 'selectedTicketPermissions'] })`.
- Render `<TicketDetailModal :show="Boolean(selectedTicket)" :ticket="selectedTicket" :engineers="engineers" :permissions="selectedTicketPermissions" @close="closeTicketModal" />`.
- Ensure zero icons/SVGs/emojis.

- [ ] **Step 3: Run full verification suite**

Run:
- `npm run build`
- `php artisan test`
Expected: `vite build` succeeds cleanly and all PHPUnit tests pass with zero failures or skipped assertions.

---

## Self-Review

- **Spec coverage:** Backend helpers and `kanban()` `?ticket=` prop loading (Task 1); `TicketDetailWorkspace.vue` extraction and `Show.vue` refactor (Task 2); `TicketDetailModal.vue` matching `NewTicketModal.vue` and `Kanban.vue` click/filter/drag integration (Task 3). Every requirement in `docs/superpowers/specs/2026-11-18-kanban-task-fullscreen-modal-design.md` is covered.
- **Step scan:** Every step specifies exact files, signatures, props, exposed methods, and commands. No placeholders or ambiguous instructions.
- **Type & name consistency:** `selectedTicket`, `selectedTicketPermissions`, `loadTicketDetails`, `buildTicketPermissions`, `TicketDetailWorkspace`, `TicketDetailModal`, `openTicketModal`, `closeTicketModal`, `buildFilterParams`, and `cancelTicket` match across all tasks.
- **Review Focus coverage:** All 5 failure modes are explicitly covered by PHPUnit assertions in Task 1 and component watchers/guards in Tasks 2 and 3.
- **Proportion:** Concise task breakdown grounded in the spec and existing codebase without dumping full file implementations.
