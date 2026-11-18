# Kanban Task Full-Screen Modal Design

## Intent & Why
Allow any staff user viewing the Engineering Kanban Board (`/tickets/kanban`) to click any ticket card and open its full interactive detail workspace inside a full-screen modal—so they can modify workflow/triage fields, update assignments, manage subtasks, post comments or internal engineering notes, upload comment attachments, and review the audit log without leaving the board or losing active filters and column scroll.

## Size
**Small change** — reuses existing Laravel endpoints and extracts the existing ticket detail UI into a shared component rather than building new backend workflows.

## Goals, Non-Goals & Anti-Goals
- **Goals:**
  - Clicking anywhere on a Kanban ticket card (excluding the inline status selector or a drag-and-drop gesture) opens a full-screen modal displaying the complete ticket workspace.
  - Users can perform all ticket modifications and collaboration actions available on `/tickets/{ticket}` directly inside the modal (workflow status, priority, due date, review/resolution notes, claim/assign lead & collaborators, add/toggle/remove subtasks, post public comments or internal notes with attachments, cancel ticket, download attachments, and view the audit log).
  - Changes saved inside the modal immediately update both the open modal and the underlying Kanban board columns via Inertia's `back()` redirect.
  - Closing the modal (Close button, `Escape` key, or browser Back) returns seamlessly to the Kanban board with all active filters preserved.
- **Non-Goals:**
  - Changing Kanban board role access (board remains `superadmin` and `engineer` only).
  - Altering `/tickets/{ticket}` standalone page behavior or `/tickets` index list behavior.
  - Adding inline card editing beyond the existing quick status selector and drag-and-drop.
- **Anti-Goals:**
  - Duplicating the 900+ lines of ticket detail markup/forms between `Show.vue` and the modal.
  - Eager-loading comments, attachments, subtasks, and audit logs for every card on the board.

## Constraints
- Follow the full-screen modal layout and accessibility pattern established in `resources/js/Components/NewTicketModal.vue` (`Teleport to="body"`, `fixed inset-0 z-50`, `role="dialog"`, `aria-modal="true"`, sticky header with Close button, `Escape` key handling, body scroll lock).
- Follow the project's No Icons rule (no FontAwesome, SVG icons, `<i>` tags, or emojis).
- Reuse existing backend routes and `RedirectResponse` (`back()`) responses so validation errors, flash messages, notifications, and activity logs behave identically.

## Approaches Considered
1. **Shared Workspace Component + Query-Driven Inertia Reload (`?ticket={id}`)** *(Selected)*
   - Extract the detail workspace from `resources/js/Pages/Tickets/Show.vue` into `resources/js/Components/TicketDetailWorkspace.vue`, wrap it in `resources/js/Components/TicketDetailModal.vue`, and have `TicketController::kanban()` optionally load `selectedTicket` and `selectedTicketPermissions` when `?ticket={id}` is present.
   - *Why picked:* Existing `back()` endpoints update both `columns` and `selectedTicket` in a single Inertia round-trip with zero duplicate API endpoints and native browser Back/deep-link support.
2. **Shared Workspace Component + Dedicated JSON Fetch Endpoint (`/tickets/{ticket}/details`)**
   - Keeps `/tickets/kanban` URL clean without query params, but adds a new JSON endpoint and requires a second follow-up request on every `onSuccess` to keep the modal in sync with server updates.
3. **Eager-Load Full Ticket Details Inside `kanban()`**
   - Avoids click latency, but bloats every board load, filter change, and drag-and-drop transition with every ticket's comments, attachments, and audit history.

## Design Summary (and Builder Calls)
- **Backend (`app/Http/Controllers/TicketController.php`):**
  - Extract shared helpers for loading full ticket detail relations (`loadTicketDetails(Ticket $ticket, bool $isStaff)`) and building the `permissions` array (`buildTicketPermissions(Ticket $ticket, User $user)`) used by both `show()` and `kanban()`.
  - In `kanban(Request $request)`, read `$ticketId = $request->integer('ticket')`. When positive, query `Ticket::visibleTo($user)->find($ticketId)`; if found, load its full details and pass `selectedTicket` and `selectedTicketPermissions` (otherwise `null`).
  - *Call made:* If `?ticket=` refers to a missing or non-visible ticket ID, return `null` for `selectedTicket` rather than failing the whole Kanban board page.
- **Shared Workspace (`resources/js/Components/TicketDetailWorkspace.vue`):**
  - Extract the main workspace (action errors, requester banner, description & diagnostics table, subtasks, attachments, comments/internal notes & audit log tabs, workflow & triage, assignment, and details rail) from `Show.vue`.
  - Keep `Show.vue` as a thin page wrapper rendering its sticky page header (`Tickets / {number}`, `Cancel ticket`, `Back to queue`) plus `TicketDetailWorkspace`.
- **Full-Screen Modal (`resources/js/Components/TicketDetailModal.vue`):**
  - Render a `Teleport` full-screen dialog (`bg-[#F8FAFC]`) with a sticky header showing the ticket number, title, status/priority/type/due-date badges, `Cancel ticket` button (when permitted), and `Close` button, plus a scrollable body containing `TicketDetailWorkspace`.
  - Handle `Escape` (capture phase, matching `NewTicketModal.vue`) and lock `document.body.style.overflow` while open.
- **Kanban Board Integration (`resources/js/Pages/Tickets/Kanban.vue`):**
  - Receive `selectedTicket` and `selectedTicketPermissions` props.
  - Clicking anywhere on a ticket card calls `openTicketModal(ticket)` (replacing the `<Link>` tags on ticket number/title, while preserving `@click.stop` on the status `<select>` and guarding against opening when a drag ends).
  - `openTicketModal` performs an Inertia visit to `/tickets/kanban` with current active filters plus `ticket: ticket.id` (`preserveState: true`, `preserveScroll: true`, `only: ['selectedTicket', 'selectedTicketPermissions']`).
  - `closeTicketModal` performs an Inertia visit to `/tickets/kanban` with only the active filters (`preserveState: true`, `preserveScroll: true`, `replace: true`, `only: ['selectedTicket', 'selectedTicketPermissions']`).
