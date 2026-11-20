# CallTek Desk - Request & Issue Ticketing System

CallTek Desk (`request-ticketing-system`) is an internal engineering request and issue ticketing system built with Laravel, Inertia.js, Vue 3, and Tailwind CSS.

## Tech Stack

- **Backend:** PHP `^8.3`, Laravel `^13.17`, `inertiajs/inertia-laravel` `^3.7`
- **Frontend:** Vue `^3.5.45`, `@inertiajs/vue3` `^3.11.0`, Tailwind CSS `^4.0.0`, Vite `^8.0.0`
- **Database:** SQLite (default local) or MySQL (configurable in `.env`)
- **Testing:** PHPUnit `^12.5.12`

## Key Features

- **Authentication & Role-Based Access:** Login via username or email (`/login`) with active-account enforcement (`EnsureUserIsActive`) and three roles: `superadmin` (Superadmin), `engineer` (Engineer), and `user` (Requester).
- **Full-Screen Ticket Submission Modal:** Accessible via `+ New Ticket` on the Dashboard, Tickets list, and Kanban board. Supports **Issue** (`ISS-YYYY-NNNN`) and **Engineering Request** (`REQ-YYYY-NNNN`) submissions with internal system selection, priority levels (`low`, `medium`, `high`, `critical`), Issue diagnostic fields (`environment`, `impact_scope`), Engineering Request scope fields (`business_justification`, `proposed_functionality`, `expected_benefits`, `requested_deadline`), and required file attachments (at least 1 file, max 10 MB each).
- **Ticket Tracking & Collaboration:** Dashboard (`/dashboard`), filterable Tickets index (`/tickets`), staff Kanban board (`/tickets/kanban`), and ticket detail page (`/tickets/{ticket}`) featuring workflow status transitions, lead engineer and collaborator assignment, subtasks, public comments vs. staff-only internal notes and attachments, and activity history.
- **Notifications:** In-app notification center (`/notifications`) and email notifications (`TicketNotificationMail`).
- **Reports & CSV Exports:** Staff metrics overview (`/reports`) and CSV export (`/reports/export`) for `tickets`, `systems`, `engineers`, and `departments`.
- **Administration (`superadmin` only):** User management (`/admin/users`) and internal system catalog (`/admin/systems`).

## Local Setup

### Prerequisites

- PHP 8.3+
- Composer
- Node.js and npm

### Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
npm install
npm run build
```

For local development with concurrent servers, run:

```bash
composer run dev
```

### Queue Worker

Email notifications (`TicketNotificationMail`) are dispatched to the queue. Locally, `composer run dev` (`php artisan dev`) automatically runs a local queue listener (`queue:listen`).

In production, run a dedicated queue worker process:

```bash
php artisan queue:work --tries=3 --max-time=3600
```

### Browser Push Notifications (Web Push / VAPID)

CallTek Desk supports browser desktop push notifications using the Web Push API and VAPID keys.

1. Generate a VAPID key pair:

```bash
php artisan webpush:vapid
```

On Windows, if PHP reports an OpenSSL configuration error when generating keys, set `OPENSSL_CONF` to PHP's `extras\ssl\openssl.cnf` first:

```powershell
$env:OPENSSL_CONF="C:\path\to\php\extras\ssl\openssl.cnf"
php artisan webpush:vapid
```

2. Ensure the following variables are configured in `.env`:

```env
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:notifications@calltek.local
```

3. Requirements:
- **Secure Context:** Browser Push API requires HTTPS in production (`http://localhost` is allowed for local development).
- **Network Access:** The application server must have outbound HTTPS access to browser push endpoints (such as `fcm.googleapis.com`, Mozilla autopush, and Apple push services).
- **Queue Worker:** Push notifications are delivered through Laravel's queue system when queued, so keep a queue worker running alongside the web server.

## Seeded Accounts

The default account seeded by `database/seeders/DatabaseSeeder.php` uses the password `password`.

| Role | Username | Email | Department |
| --- | --- | --- | --- |
| Superadmin | `superadmin` | `superadmin@calltek.local` | IT Leadership |

## Testing & Build Commands

```bash
php artisan test
# or
composer test

npm run build
```
