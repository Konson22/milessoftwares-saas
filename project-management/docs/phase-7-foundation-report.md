# Phase 7: foundation increment (account security, health thresholds, approval policies)

The first-phase plan in `platform-assessment-and-roadmap.md`, items 1 to 5. All of it is
server-side enforced and covered by feature tests; nothing earlier was rewritten.

## What changed

### 1. Two-step sign-in (TOTP)

- Fortify's two-factor feature, with `confirm` (it is not on until a code from the app is
  accepted) and `confirmPassword` (each change needs the password re-entered within
  `auth.password_timeout`).
- New `users` columns: `two_factor_secret` and `two_factor_recovery_codes` (stored encrypted
  with the app key, and hidden from serialization), plus `two_factor_confirmed_at`.
- Sign-in: after the password, `/two-factor-challenge` (`portal/auth/two-factor-challenge`)
  asks for a 6-digit code, or a recovery code.
    - A code works once: Fortify refuses a replay.
    - A recovery code is used up and replaced.
    - Five tries a minute per pending sign-in (the `two-factor` limiter); after that, 429.
- Account page (`/account`), "Two-step sign-in" panel:
    1. Confirm your password.
    2. Set up: shows the QR code and setup key, only to a session that has just confirmed the
       password, and only until setup is finished.
    3. Confirm with a code.
    4. The eight recovery codes show once.
    5. After that, you can make new codes, or turn two-step sign-in off.
- Audit (account events, no organization):

    | Action                          | When                            |
    | ------------------------------- | ------------------------------- |
    | `auth.two_factor_started`       | Setup begins                    |
    | `auth.two_factor_enabled`       | The confirming code is accepted |
    | `auth.two_factor_disabled`      | Turned off                      |
    | `auth.two_factor_failed`        | A wrong code at sign-in         |
    | `auth.recovery_codes_generated` | New codes made                  |
    | `auth.recovery_code_used`       | A recovery code signs in        |

    Codes themselves are never logged.

### 2. Where you are signed in

- The account page lists database sessions: device (browser and OS from the user agent), IP,
  last activity, and "this device". Session IDs are never sent to the browser.
- "Sign out other sessions" needs the current password. It:
    - deletes the account's other session rows;
    - cycles `remember_token`, so "remember me" cookies stop working;
    - audits `auth.other_sessions_signed_out`.

### 3. Closing your own account

- Needs the password. It's refused while you are the only active owner of an active
  organization; the page names those organizations.
- Status becomes `deactivated` and `deactivated_at` is set. All sessions are deleted, the
  remember token is cycled, and you are signed out and sent to the public site.
  Audit: `auth.account_deactivated`.
- Signing in again says the account was closed. `php artisan users:status <email> active`
  reopens it (and clears `deactivated_at`).
- Memberships and the work you did stay as they are: nothing is deleted.

### 4. Project health thresholds per organization

- `organizations.health_schedule_slack` and `health_budget_slack`: percentage points,
  default 20, i.e. the old hard-coded value.
- `ProjectMetrics` loads them once per batch of projects.
- Administration → Settings → Project health: 0 to 100 each. Needs `organization.manage`.
  Audited as `organization.health_thresholds_updated`, with before and after values.

### 5. Spending approval policies

- `approval_policies`: per organization and currency, "from this amount (minor units), this
  many approvers" (1 to 3).
    - The band with the highest minimum at or below the amount applies.
    - Without a band, one approver, as before.
- `expenses.approvals_required` is set when spending is requested, so a later policy change
  doesn't move a request already made.
- `expense_approvals`: one row per approver (unique per expense and person).
- `Spending::approve` checks, at every approval:
    - the requester never approves;
    - the same person never approves twice;
    - the approved budget and the grant allocation still have room.

    The spending is committed (status `approved`) with the last required approval. Earlier
    approvals are audited as `expense.approval_recorded`, with a count such as "1 of 2".

- The approvals queue shows "Needs 2 approvers: 1 so far (name)". Someone who has already
  approved sees that it waits for another approver.
- Administration → Settings → Spending approvals: add and remove bands.
    - Needs `organization.manage`.
    - Audited as `approval_policy.created` and `approval_policy.removed`.
    - Scoped to the organization: another organization gets a 404.

## Files

- **Migrations:**
    - `2026_11_04_000700_add_account_security`
    - `2026_11_04_000800_add_health_thresholds_to_organizations`
    - `2026_11_04_000900_create_approval_policies`
- **Models:**
    - new: `ApprovalPolicy`, `ExpenseApproval`
    - changed: `User` (`TwoFactorAuthenticatable`, `DEACTIVATED`), `Organization`, `Expense`
- **Controllers:**
    - new: `Account/AccountSecurityController`, `Admin/ApprovalPolicyController`
    - extended: `Account/AccountController`, `Admin/SettingsController`,
      `Finance/ApprovalController`, `Finance/ExpenseController`
- **Other PHP:**
    - `app/Actions/Spending.php`, `app/Support/ProjectMetrics.php`
    - `app/Providers/FortifyServiceProvider.php`, `config/fortify.php`
    - `routes/console.php`, `routes/web/organizations.php`, `routes/web/admin.php`
- **Frontend:**
    - new: `pages/portal/auth/two-factor-challenge.tsx`, `components/account-security.tsx`,
      `components/approval-bands.tsx`
    - changed: `pages/portal/account/settings.tsx`, `pages/portal/admin/settings.tsx`,
      `pages/portal/finance/approvals.tsx`
- **Tests:**
    - new: `tests/Feature/AccountSecurityTest.php` (7 tests)
    - added to `ProjectsTest`: 1 test (health slack)
    - added to `FinanceTest`: 2 tests (approval bands, and keeping bands within their
      organization)

## Verification

- **SQLite** (`php artisan test`): 84 passed, 1 failed. The failure is `MarketingSiteTest`, which
  is part of the separate marketing-site work in progress: its routes aren't there yet. It
  isn't part of this increment.
- **Postgres** (`DB_CONNECTION=pgsql`): `AccountSecurityTest`, `FinanceTest` and `ProjectsTest`
  all pass (28 tests, 687 assertions).
- **Migrations:** applied, rolled back and re-applied on Postgres.
- **Static checks:** Pint, PHPStan and `tsc` clean. `vp check` is clean for these files; the only
  formatting findings are in the in-progress `resources/js/pages/guest/*` pages.
- **Build:** `npm run build` succeeds.
- **Not verified:** a real browser walkthrough (the Chrome extension wasn't connected), and
  scanning a QR code with an actual authenticator app. Codes in the tests come from the same
  library the apps' algorithm follows (RFC 6238).

## Limits and next steps

- Bands set the number of approvers, not which role each approver must hold: every approver
  needs `finance.expenses.approve`. Role-specific tiers (for example "the second approver is a
  director") would be a `required_permission` column on the band.
- Approvals given before a rejection stay as history. A rejected request is closed; it isn't
  reopened.
- Next from the roadmap: the work hierarchy (Workspace → Space → Folder → List → Task), with
  existing tasks backfilled into default lists.

## Database: moved to MySQL / MariaDB

- The app now runs on MariaDB 10.4 (XAMPP), using the `mariadb` driver. It has its own
  `projects` database (utf8mb4) and a `projects` user granted access to that database only.
- **Two fixes for MySQL:**
    - The approval-band unique index got a short name. MySQL caps names at 64 characters;
      Postgres had silently shortened it.
    - The financial-controls migration's `down()` now gives the expenses foreign keys their own
      indexes before dropping the composite ones. MySQL had been using the composite indexes for
      those keys.
- **Checks on MariaDB:**
    - All migrations run up, all the way down, and up again.
    - The full suite passes: 85 tests, 1,431 assertions.
    - `php artisan serve` serves the home and sign-in pages, and sessions are written to the
      database.
- No SQL in the app is engine-specific, so PostgreSQL still works with `DB_CONNECTION=pgsql`.
