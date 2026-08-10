# PrimePower Manpower — HRIS (Core Transaction 2)

Fleet & Transportation HRIS. **All five modules are built**: Employee
Information, Timekeeping & Attendance, Leave & Absence, Payroll & Compensation,
and Performance Management.

## Stack

Laravel 12 + Inertia 2 + React 18 + Tailwind 3, served by Herd at
`http://core2.test` (use `http://` — the site is not secured for TLS).

**Architecture:** Inertia renders the UI *and* a token-authenticated REST API
lives at `/api/v1`. Both entry points call the same Service class, so behaviour
can't drift between them. Controllers stay thin: authorize, delegate, respond.

```
Request ─┬─ Http/Controllers/EmployeeController      (Inertia -> Pages/…)
         └─ Http/Controllers/Api/EmployeeController  (JSON  -> Resources)
                              │
                       Services/EmployeeService      ← all business logic
                              │
                          Models/…                   ← Auditable, scopes
```

## Commands

| Task | Command |
|---|---|
| Run tests | `php artisan test` |
| Format PHP | `vendor/bin/pint` |
| Format JS | `npm.cmd run format` |
| Everything | `npm.cmd run check` |
| Dev assets | `npm.cmd run dev` |
| Build assets | `npm.cmd run build` |

**On this machine, `npm` is blocked by the PowerShell execution policy — use
`npm.cmd`.** Assets are pre-built, so the site works without `npm run dev`.

## Roles

`admin`, `hr_staff`, `supervisor`, `employee` (constants on `App\Models\User`).

- **admin / hr_staff** — every record; only admin may archive
- **supervisor** — own record plus direct reports
- **employee** — own record only

Enforced in two places that must agree: `EmployeeService::scopedQuery()` narrows
the *list*, and `EmployeePolicy` guards *individual* records. Salary, bank
details, and government IDs are gated behind `viewSensitive` and omitted from the
API resource entirely — not just hidden in the UI.

Self-registration is disabled by design; HR provisions logins from the employee
form.

## Design system

Every colour is a semantic token in `resources/css/app.css`, exposed through
`tailwind.config.js`. **Never write a raw hex or a `gray-500` in a component** —
use `bg-card`, `text-muted-foreground`, `border-border`, `bg-sidebar-accent`.
Light and dark both work because components reference tokens, not values.

- Shared components live in `resources/js/Components/ui/` — import from the
  `@/Components/ui` barrel.
- Every authenticated page wraps in `@/Layouts/AppLayout` and passes `title` +
  `breadcrumbs`.
- Chart marks use `--chart-1`, held apart from `--primary` because chart fills
  have to sit inside an OKLCH lightness band that `--primary` misses in dark mode.

## Adding a module (the Module 1 recipe)

1. **Migration** already exists for Modules 2–5 — check
   `database/migrations/2026_08_09_0000*` before writing a new one.
2. **Model** — `use Auditable` for anything HR edits; add `scopeFilter()` for
   list screens.
3. **Policy** — `App\Policies\{Model}Policy`, auto-discovered.
4. **Service** — `App\Services\{Module}Service`, holds the logic and a
   `scopedQuery(User)` for role narrowing.
5. **Form Requests** — validation only; `authorize()` delegates to the policy.
6. **Resource** — use `mergeWhen($canSeeSensitive, …)` for restricted fields.
7. **Controllers** — an Inertia one and an `Api\` one, both injecting the service.
8. **Routes** — web under `Route::prefix('hr')->name('hr.')`, API under
   `/api/v1` inside `auth:sanctum`.
9. **Pages** — `resources/js/Pages/HR/{Module}/`, built from the UI kit.
10. **Tests** — management, access-control, and API tests, mirroring
    `tests/Feature/HR/` and `tests/Feature/Api/`.

Replace the module's entry in `resources/js/config/navigation.js` (the route is
already listed) and delete its `ModulePlaceholderController` method.

## Timekeeping (Module 2)

`AttendanceCalculator` is deliberately database-free: Payroll multiplies its
output by money, so every rule is unit tested in isolation. It derives status,
hours worked, late, undertime, overtime, and night differential from the punches
plus the assigned `Shift`.

- The grace period **forgives** lateness entirely; past it, lateness counts from
  the scheduled start (not from the grace cutoff).
- A shift whose `end_time <= start_time` wraps midnight; the calculator pushes
  its end — and any time-out earlier than the start — to the next day.
- Night differential is 22:00–06:00, summed over non-overlapping per-day windows
  so a multi-night shift can't double count.
- Overtime is stored raw. Whether it is *paid* depends on an approved
  `OvertimeRequest` — that gate belongs to Payroll.
- `TimekeepingService::record()` upserts one row per employee/date.

Six screens share `SectionTabs`: **Daily Records** (DTR + CSV import),
**Overtime** (file / approve / reject), **Shifts & Schedules**, **Reports**
(per-employee aggregation, CSV export), **Exceptions**, and **History**. Only HR
records or corrects time; approvers are HR or the employee's own supervisor,
never the requester.

A shift still referenced by a schedule or a time record is **deactivated**
instead of deleted, so attendance history keeps its shift.

**Exceptions** is the automated DTR checker: `AttendanceExceptionScanner` is a
database-free, config-driven rule engine (same pattern as
`AttendanceCalculator`) that flags two kinds of anomaly over the filtered
range — record-level (a missing time-out, a day's lateness or overtime past a
threshold) and pattern-level (an employee trending toward chronic lateness or
absence, even when no single day crosses a threshold). Thresholds live in
`config/timekeeping.php`, not code, so tightening a rule is a config edit. A
missing time-out is never flagged for *today* — only for a day already in the
past, per `stale_open_punch_days`.

**History** is the audit trail for DTR edits — who changed a record, when, and
what changed — reusing the same `viewAuditLog` gate as Settings > Security
rather than a new permission, so it's HR/admin only. It filters by event type
(`created`/`updated`/`deleted`) only: `employee_id` lives inside the audit's
JSON diff, not a real column, so filtering on it after `paginate()` would
silently corrupt the pagination totals.

## Leave (Module 3)

Two-step approval: the employee files, their **supervisor endorses**, then **HR
confirms** — and only that last step moves credits. Nobody signs off on their own
leave, HR included.

- `LeaveService::workingDays()` skips holidays and the employee's rest days, so a
  Friday-to-Monday request over a weekend costs two days, not four. It reads the
  schedule through `TimekeepingService` — Module 2 and 3 share one calendar.
- **Open requests reserve credits.** `availableCredits()` subtracts days on
  pending and endorsed requests, so the same credit cannot be filed against twice
  before either is approved.
- Cancelling or rejecting an already-approved request hands the credits back.
- Unpaid types (`is_paid = false`) never touch the ledger.
- Attachments live on the private disk and download through
  `hr.leave.attachment` after a policy check, same as 201-file documents.
- The topbar bell counts what **this** user must act on: pending requests from a
  supervisor's own reports, endorsed requests for HR. Shared lazily from
  `HandleInertiaRequests`, so guests never run the query.

## Payroll (Module 4)

**Statutory rates live in `config/payroll.php`, not in code.** SSS, PhilHealth,
Pag-IBIG, and the TRAIN withholding tables are all config values, so a new
circular is a config edit. `StatutoryContributionsTest` asserts every bracket
against the published tables — change the config and the expectations together.

Two database-free, unit-tested classes do the arithmetic; `PayrollService` only
gathers inputs and stores results:

- `StatutoryContributions` — contributions and withholding tax.
- `PayrollCalculator` — earnings, deductions, net pay, and the payslip lines.
  Daily rate is `monthly × 12 ÷ 261`; hourly is daily ÷ 8.

Rules worth knowing before touching it:

- **Only *approved* overtime is paid.** Attendance records raw time past the
  shift; the `OvertimeRequest` decides what is payable.
- **Only *unpaid* leave is deducted.** Paid leave is already inside the salary.
- Taxable income is gross less non-taxable allowances, less time not worked,
  less the employee's statutory contributions.
- Contributions are assessed monthly, so a semi-monthly run withholds half.
- **Loans only move at approval.** A draft can be recomputed freely; approving
  is the point of no return.
- **Separation of duties:** HR staff compute and submit, an *admin* approves,
  and never the same person who processed the run.
- Employees see their own payslips only once the run is approved or paid — a
  draft is still being corrected.
- The payslip page is print-styled; "Save as PDF" is the browser's own print
  dialog, so there is no PDF dependency to maintain.

**Readiness — the Module 2 → Module 4 gate.** `gatherInputs()` reads attendance
without judging it, so a forgotten time-out quietly understates hours and a
pending `OvertimeRequest` quietly pays nothing. `PayrollReadinessChecker` runs
those checks *before* the money is computed and shows them on the run screen:
`blocker` (paying from this would be wrong) versus `warning` (payable, but
someone should have decided). It reuses `AttendanceExceptionScanner` rather
than re-deriving what a bad record looks like. **Nothing here hard-stops a
run** — a payroll that cannot be run is worse than one that warns loudly — and
the panel is hidden once a run is approved, since the figures are then history
and the advice can no longer be applied.

**Compliance** is the remittance and BIR reporting screen: SSS (R-3),
PhilHealth (RF-1), Pag-IBIG (MCRF), and the BIR alphalist, each with a CSV
export carrying a control total. `ComplianceReportBuilder` is database-free and
**reads figures back from stored payslips, never recomputes them** — otherwise
a new SSS circular in `config/payroll.php` would silently rewrite what was
already remitted. Only *approved* and *paid* runs are reportable; a draft is
still being corrected. An employee missing the relevant government number is
flagged, because the filing cannot include them until it is on their 201 file.

## Performance (Module 5)

The rating scale, the 360 reviewer weights, and the performance bands live in
`config/performance.php`. `PerformanceScorer` is database-free and unit tested;
`PerformanceService` handles the cycle workflow.

- A review's score is the **weighted mean** of its KPI ratings. Weights come
  from the employee's scorecard, never from the submitted form — a reviewer
  rates, they do not decide what counts.
- An employee's score for a cycle blends the four perspectives. **Missing
  perspectives are re-normalised, not scored as zero**, so someone with only a
  supervisor review still scores on the same 1–5 scale.
- Rolling out a cycle builds every scorecard from the KPI library and creates
  the self and supervisor evaluations. It is **safe to re-run** — existing
  scorecards and reviews are left alone.
- Only the assigned reviewer edits, only while the cycle accepts submissions,
  and only a draft. The employee acknowledges afterwards; a self review needs
  no acknowledgement.
- A KPI already on a scorecard is deactivated rather than deleted.

## Settings

Eight sections under `/settings`, sharing `SettingsLayout` (section list on the
left). Company-wide sections are **admin-only**; Organization is HR too;
Appearance and Security belong to every signed-in user.

- Values live in a **key/value `settings` table**, namespaced (`company.name`),
  JSON-valued, read through one cached map. A new preference is a new key in
  `Setting::DEFAULTS`, not a migration.
- **Organization** is the departments/positions CRUD. **Users & Access** is the
  only place besides the employee form where a login is created — an admin
  cannot demote or deactivate themselves, and deactivating revokes API tokens.
- **Security** replaced the starter kit's `/profile`, which now redirects there.
  `email_verified_at` is guarded, so clearing it on an email change has to
  happen outside the mass-assignment payload.
- **Appearance** (theme, sidebar default) is per-device and lives in
  `localStorage`, not the database.

## Gotchas that have already cost time

- **Settings are cached forever.** `Setting::all()` uses `rememberForever`, and
  `setMany()` clears it — but editing `Setting::DEFAULTS` in code does not. After
  changing a default, run `php artisan cache:clear` or you will read the old one.
- **The logo subtitle is `#0c0a0a` in both modes, per the brand spec.** On the
  dark sidebar (`#131E29`) that is roughly 1.1:1 — effectively invisible.
  Raising `--logo-subtitle` in the `.dark` block is the one-line fix.

- **Paginator links.** `employees.links` is the `{first,last,prev,next}` *object*;
  the numbered page buttons are `employees.meta.links` (an *array*). Passing the
  object to `<Pagination>` crashes React and blanks the page. Guarded by a test.
- **File uploads over PUT.** Browsers can't send multipart on PUT — put
  `_method: 'put'` in the `useForm` data and `post()` with `forceFormData: true`.
- **Documents are on the private disk.** Never link to `/storage/...` for a 201
  file; they're streamed through `hr.employees.documents.download` after a policy
  check. Employee *photos* stay public so avatars don't cost a PHP request per row.
- **`ilike` is Postgres-only.** Tests run on SQLite — pick the operator from
  `getDriverName()`, as `Employee::scopeSearch` does.
- **Factory sequences.** Batch `create()` runs every `definition()` before the
  first insert, so a DB-derived counter hands out duplicates. `EmployeeFactory`
  counts in memory instead.
- **Lucide icon names don't fail the build.** A misspelled icon imports as
  `undefined` and only blows up at render. Vite will not warn you.
- **`updateOrCreate` matches on exact column equality.** A `date`-cast column can
  be stored as `Y-m-d 00:00:00`, so looking it up with a `Y-m-d` string misses
  and inserts a duplicate. Use `whereDate` then update, as
  `TimekeepingService::record()` does.
- **A new page must be built before its feature test passes.** The root blade
  `@vite`s the page component by name, so an unbuilt page throws and the test
  reports "Not a valid Inertia response." Run `npm.cmd run build` first.
- **Verify in a browser, not with curl.** A `200` means the server sent correct
  HTML; it says nothing about whether React mounted.

## Database

**PostgreSQL** (`primepower_hris`, local server on port `5433` — not the 5432
default; check `DB_PORT` in `.env` before assuming). Tests always use
in-memory SQLite regardless of the app's own connection (see `phpunit.xml`),
so a Postgres-only bug (like the `ilike` operator) won't show up in a normal
`php artisan test` run — it only surfaces against a real Postgres database.
The old `database/database.sqlite` is kept only as a pre-migration backup
under `storage/app/backups/` (gitignored, not the live source of truth).

Seed accounts (password `password`): `admin@primepower.test`,
`hr@primepower.test`.

## Known gaps

All five modules are functional. Still outstanding: 13th-month pay and final-pay
computation; peer and subordinate reviews are supported by the schema and
scoring but have no assignment UI (only self and supervisor are created at
rollout); holidays management screen (2026 holidays are seeded, but there is no
UI); leave credit accrual on a schedule (credits are allocated in bulk per
year); email notifications (the bell is in-app only); `/profile` still uses the
old Breeze layout.
