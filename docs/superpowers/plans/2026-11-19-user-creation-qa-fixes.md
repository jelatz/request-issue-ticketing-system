# User Creation QA Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all 4 failed user-creation QA test cases (`TC-13` case-insensitive uniqueness, `TC-11b` TLD-less email validation, `TC-09` whitespace-only password validation, and `TC-15` modal label-to-input accessibility) with full parity across Create, Edit, and Login flows.

**Architecture:** Normalize `username` and `email` to trimmed lowercase in `UserController`, enforce SQLite- and MySQL-compatible `LOWER(...)` uniqueness checks and `email:rfc,filter` validation, reject whitespace-only passwords on both `store()` and `update()`, align `AuthController::login()` to case-insensitive lookups, and update `resources/js/Pages/Admin/Users/Index.vue` with matching client-side email/password validation plus explicit `for`/`id` associations on all Create and Edit modal fields.

**Tech Stack:** Laravel 13, Inertia.js v3 (Vue 3), Tailwind CSS v4, PHPUnit 12, Vite 8, SQLite (local) / MySQL (production)

**Spec / QA Evidence:** `C:\Users\TSE\.gemini\antigravity\brain\31ad16bf-a265-4f4e-bda4-becaa80247d7\user-creation-test-cases.md` (failed cases: `TC-09`, `TC-11b`, `TC-13`, `TC-15`)

## Global Constraints

- Strictly observe the No Icons rule: zero emojis, zero `<svg>` tags, zero `<i>` icon tags, and zero FontAwesome classes anywhere in modified code, templates, tests, or documentation.
- Follow TDD: write failing PHPUnit feature tests first, verify targeted failures, implement minimal fixes, and confirm the full suite (`php artisan test`, baseline 25 passing tests) and `npm run build` pass.
- Preserve internal `.local` email domains (e.g., `superadmin@calltek.local`, `qa.manager+test@calltek.local`): use `'email:rfc,filter'`, NOT `'email:rfc,dns'`, to avoid DNS lookup failures in local or offline environments.
- Keep all SQL queries portable across both SQLite and MySQL using ANSI `LOWER(column) = ?`.
- Scope covers the 4 failed test cases (`TC-09`, `TC-11b`, `TC-13`, `TC-15`) across Create, Edit, and Login; optional LOW UX findings (`TC-14` `maxlength="255"`, `TC-16` search placeholder text, and Actions column horizontal overflow) are listed separately as out-of-scope follow-ups unless explicitly requested.

## Review Focus

1. **Case-variant duplicate username/email against both normalized lowercase rows and pre-existing mixed-case DB rows (`TC-13`):** Creating or updating a user with `QA_TEAM_LEAD` / `QA.TEAMLEAD@CALLTEK.LOCAL` when `qa_team_lead` / `qa.teamlead@calltek.local` exists (or vice versa when a legacy row has `MixedCase`) must fail with standard `unique` validation errors on `username` and `email`, while updating a user's own casing (e.g., `MixedCase` -> `mixedcase`) must succeed without self-collision.
2. **Case-insensitive login after lowercase normalization (`AuthController::login`):** Signing in with uppercase or mixed-case username (`QA_TEAM_LEAD`) or email (`QA.TEAMLEAD@CALLTEK.LOCAL`) must authenticate the stored lowercase account on SQLite as well as MySQL.
3. **TLD-less vs internal `.local` / `+` subaddressed emails (`TC-11b`):** `qa@localhost`, `user@company`, `not-an-email`, and `qa@invalid..com` must be rejected by both frontend validation (`Index.vue`) and backend validation (`email:rfc,filter`), while `superadmin@calltek.local`, `qa.manager+test@calltek.local`, and `lad@mail2.calltekcenter.com` must continue to pass.
4. **Whitespace-only password on both Create and Edit vs valid passphrases with spaces (`TC-09`):** Submitting `"      "` (6 spaces) on Create must be blocked client-side (0 POST requests) and rejected server-side; submitting `"      "` on Edit (`PUT /admin/users/{user}`) must be rejected by both frontend and backend and must never overwrite the user's existing password hash. Valid passphrases containing inner spaces (e.g., `"my pass 123"`) must still succeed.
5. **Modal label click focus + anti-autofill `readonly` unlock (`TC-15`):** Clicking every `<label>` in Create and Edit modals (`Full name`, `Username`, `Email address`, `Initial password` / `New password`, `Role`, `Department`, `Job title`, `Active`) must focus its associated input/select, and clicking a `readonly`-guarded field's label must trigger `@focus` and unlock the field immediately without dropping typed characters.

---

### Task 1: Backend Identity Normalization, Case-Insensitive Uniqueness, `email:rfc,filter`, Whitespace Password Guard & Case-Insensitive Login

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php:53-136`
- Modify: `app/Http/Controllers/Auth/AuthController.php:21-54`
- Test: `tests/Feature/TicketingBackendTest.php`

**Interfaces:**
- Consumes: `App\Models\User`, `Illuminate\Http\Request`, `Closure`
- Produces on `App\Http\Controllers\Admin\UserController`:
  - `private function normalizeIdentityInputs(Request $request): void` — trims and lowercases `username` and `email` via `$request->merge(...)` when string inputs are present.
  - `private function uniqueCaseInsensitiveRule(string $column, ?int $ignoreUserId = null): \Closure` — checks `User::query()->whereRaw("LOWER({$column}) = ?", [mb_strtolower((string) $value)])` (excluding `$ignoreUserId` when non-null) and calls `$fail(__('validation.unique', ['attribute' => $attribute]))`.
  - `private function nonWhitespacePasswordRule(): \Closure` — fails if `is_string($value) && $value !== '' && trim($value) === ''` with `'The password cannot consist only of whitespace.'`.
- Produces on `App\Http\Controllers\Auth\AuthController::login(Request $request): RedirectResponse`:
  - Case-insensitive lookup via `$normalizedLogin = mb_strtolower(trim($validated['login']))` with `whereRaw('LOWER(email) = ?', [$normalizedLogin])->orWhereRaw('LOWER(username) = ?', [$normalizedLogin])`.

- [x] **Step 1: Write failing PHPUnit feature tests in `tests/Feature/TicketingBackendTest.php`**

Add the following 4 test methods to `tests/Feature/TicketingBackendTest.php`:

1. `test_admin_user_store_and_update_enforce_case_insensitive_unique_username_and_email_and_normalize_to_lowercase`:
   - `POST /admin/users` as `$superadmin` with `username => '  Username_One  '` and `email => '  User.One@CallTek.Local  '` succeeds and persists lowercase `'username_one'` and `'user.one@calltek.local'`.
   - `POST /admin/users` with `username => 'USERNAME_ONE'` and `email => 'USER.ONE@CALLTEK.LOCAL'` fails `assertSessionHasErrors(['username', 'email'])`.
   - Seed a legacy mixed-case row via `User::factory()->create(['username' => 'Legacy_User', 'email' => 'Legacy@CallTek.Local'])` and assert `POST /admin/users` with `username => 'legacy_user'` and `email => 'legacy@calltek.local'` fails `assertSessionHasErrors(['username', 'email'])`.
   - `PUT /admin/users/{legacy}` updating `$legacyUser` with `'legacy_user'` and `'legacy@calltek.local'` succeeds (`assertSessionHasNoErrors()`) and persists lowercase values, while `PUT /admin/users/{legacy}` using `'USERNAME_ONE'` and `'USER.ONE@CALLTEK.LOCAL'` fails `assertSessionHasErrors(['username', 'email'])`.
2. `test_users_can_login_case_insensitively_with_username_or_email`:
   - Create user `jdoe` / `jdoe@calltek.local` with password `'secret123'`.
   - Assert `POST /login` with `login => 'JDOE'` succeeds (`assertRedirect(route('dashboard'))`, `assertAuthenticatedAs($user)`), logout, and assert `POST /login` with `login => 'JDOE@CALLTEK.LOCAL'` succeeds.
3. `test_admin_user_store_and_update_reject_tldless_emails_while_allowing_internal_local_domains`:
   - For each invalid address in `['qa@localhost', 'user@company', 'not-an-email', 'qa@invalid..com']`, assert `POST /admin/users` and `PUT /admin/users/{user}` fail `assertSessionHasErrors('email')`.
   - Assert `POST /admin/users` succeeds (`assertSessionHasNoErrors()`) for `'qa.manager+test@calltek.local'` and `'ops@mail2.calltekcenter.com'`.
4. `test_admin_user_store_and_update_reject_whitespace_only_passwords_and_allow_passphrases`:
   - Assert `POST /admin/users` with `password => '      '` (6 spaces) fails `assertSessionHasErrors('password')`.
   - Record `$originalHash = $existingUser->password`; assert `PUT /admin/users/{existingUser}` with `password => '      '` fails `assertSessionHasErrors('password')` and `$existingUser->fresh()->password === $originalHash`.
   - Assert `POST /admin/users` with `password => 'my pass 123'` succeeds (`assertSessionHasNoErrors()`) and user can log in via `POST /login` using `'my pass 123'`.

- [x] **Step 2: Run targeted tests to verify they fail before implementation**

Run: `php artisan test --filter="case_insensitive|tldless|whitespace_only"`
Expected: FAIL on all 4 new tests.

- [x] **Step 3: Implement normalization, validation, and case-insensitive login in `UserController.php` and `AuthController.php`**

In `app/Http/Controllers/Admin/UserController.php`:
- Add `normalizeIdentityInputs(Request $request): void` to trim and lowercase `username` and `email` via `$request->merge(...)` when present as strings.
- Call `$this->normalizeIdentityInputs($request)` at the start of both `store()` and `update()`.
- Add `uniqueCaseInsensitiveRule(string $column, ?int $ignoreUserId = null): \Closure` using `User::query()->whereRaw("LOWER({$column}) = ?", [mb_strtolower((string) $value)])` (adding `->when($ignoreUserId !== null, fn ($q) => $q->where('id', '!=', $ignoreUserId))`), failing with `__('validation.unique', ['attribute' => $attribute])`.
- Replace `'unique:users,username'` and `Rule::unique(...)` on `username` and `email` in `store()` and `update()` with `$this->uniqueCaseInsensitiveRule(...)`.
- Replace `'email'` with `'email:rfc,filter'` on `email` in both `store()` and `update()`.
- Add `nonWhitespacePasswordRule(): \Closure` rejecting non-empty strings whose `trim($value) === ''` and attach it to `password` rules in `store()` and `update()`.
- In `update()`, guard password assignment with `if (is_string($validated['password'] ?? null) && trim($validated['password']) !== '')`.

In `app/Http/Controllers/Auth/AuthController.php`:
- Normalize `$loginInput = mb_strtolower(trim($validated['login']))` in `login()` and update the user lookup to `->whereRaw('LOWER(email) = ?', [$loginInput])->orWhereRaw('LOWER(username) = ?', [$loginInput])`.

- [x] **Step 4: Run targeted and full PHPUnit test suites**

Run: `php artisan test --filter="case_insensitive|tldless|whitespace_only"`
Expected: PASS (4 passed).

Run: `php artisan test`
Expected: PASS (29 passed, 0 failures).

---

### Task 2: Frontend Validation Parity (`TC-09`, `TC-11b`), Modal Label Accessibility (`TC-15`) & Live Browser Re-Verification

**Files:**
- Modify: `resources/js/Pages/Admin/Users/Index.vue:158-290`, `526-727`, `756-947`

**Interfaces:**
- Produces inside `resources/js/Pages/Admin/Users/Index.vue`:
  - `const EMAIL_FORMAT_REGEX = /^[^\s@]+@(?:[^\s@.]+\.)+[^\s@.]{2,}$/;`
  - `function isValidEmailAddress(value)` returning `EMAIL_FORMAT_REGEX.test(String(value || '').trim())`.
  - Updated `submitCreate()` (`L158-L183`) and `submitEdit()` (`L248-L275`):
    - Email check: empty -> `'Please enter an email address.'`; `!isValidEmailAddress(...)` -> `'Please enter a valid email address with a domain (e.g. name@calltek.local).'`.
    - Create password check: `!String(createForm.password || '').trim()` -> `'Please enter an initial password.'`; `else if (String(createForm.password).length < 6)` -> `'Password must be at least 6 characters.'`.
    - Edit password check: when `String(editForm.password || '').length > 0`, if `!String(editForm.password).trim()` -> `'Password cannot contain only whitespace.'`; `else if (String(editForm.password).length < 6)` -> `'Password must be at least 6 characters.'`.
  - Explicit `for` / `id` pairing on all 8 Create modal fields (`create-user-name`, `create-user-username`, `create-user-email`, `create-user-password`, `create-user-role`, `create-user-department`, `create-user-job-title`, `create-user-active`) and all 8 Edit modal fields (`edit-user-name`, `edit-user-username`, `edit-user-email`, `edit-user-password`, `edit-user-role`, `edit-user-department`, `edit-user-job-title`, `edit-user-active`).

- [x] **Step 1: Add client-side email format and trimmed password validation in `resources/js/Pages/Admin/Users/Index.vue`**

Add `EMAIL_FORMAT_REGEX` and `isValidEmailAddress(value)` near `formatValidationErrorMessage`, and update `submitCreate()` and `submitEdit()` to validate email domain format and reject whitespace-only passwords before posting.

- [x] **Step 2: Add explicit `for` and `id` attributes to all Create and Edit modal labels and controls in `resources/js/Pages/Admin/Users/Index.vue`**

Pair every `<label for="...">` with matching `id="..."` across all 8 fields in the Create modal (`L526-L727`) and all 8 fields in the Edit modal (`L756-L947`), preserving existing `@focus` / `@pointerdown` `readonly` unlock handlers so clicking any label focuses and unlocks its field immediately.

- [x] **Step 3: Build frontend bundle and run backend test suite**

Run: `npm run build`
Expected: Vite production build exits with code 0.

Run: `php artisan test`
Expected: PASS (29 passed, 0 failures).

- [x] **Step 4: Re-run the 4 failed QA test cases (`TC-09`, `TC-11b`, `TC-13`, `TC-15`) via the `browser` subagent**

Verify against live `http://localhost:8000` (without inserting junk DB rows):
- `TC-09`: `"      "` (6 spaces) password blocked client-side (0 POST requests) with inline `'Please enter an initial password.'` and 1 error toast.
- `TC-11b`: `qa@localhost` blocked client-side with invalid email inline error before hitting the server.
- `TC-13`: `QA_TEAM_LEAD` + `QA.TEAMLEAD@CALLTEK.LOCAL` + 260-char department displays uniqueness errors on BOTH `username` AND `email` alongside `department`.
- `TC-15`: Clicking all 7 Create modal `<label>` elements moves `document.activeElement` to each corresponding control, unlocks `readonly` inputs immediately, and preserves typed input without dropped characters; verify Edit modal label focus and whitespace password guard as well; verify case-insensitive login (`QA_TEAM_LEAD` / `QA.TEAMLEAD@CALLTEK.LOCAL`).
- Update `C:\Users\TSE\.gemini\antigravity\brain\31ad16bf-a265-4f4e-bda4-becaa80247d7\user-creation-test-cases.md` to mark `TC-09`, `TC-11b`, `TC-13`, and `TC-15` as `PASS`.

---

## Optional Out-of-Scope Follow-Ups (Low-Severity UX Items from QA Report)

The following 3 LOW-severity items passed their test cases during QA and are excluded from this plan by default:
1. **`TC-14` [LOW]:** Add `maxlength="255"` attributes to `name`, `username`, `email`, `department`, and `job_title` inputs in Create/Edit modals (`resources/js/Pages/Admin/Users/Index.vue`).
2. **`TC-16` [LOW]:** Update directory search placeholder in `resources/js/Pages/Admin/Users/Index.vue` from `"Search name, username, email, or department..."` to `"Search name, username, email, department, or job title..."`.
3. **Visual [LOW]:** Tighten table cell padding or column wrapping in `resources/js/Pages/Admin/Users/Index.vue` so the `Actions` column (`Edit` / `Deactivate`) renders without horizontal clipping at standard desktop viewports.
