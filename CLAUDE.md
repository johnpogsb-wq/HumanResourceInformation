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

**The API covers Modules 1 and 2 only** — employees (plus documents) and
attendance. Leave, payroll, and performance are Inertia-only today; the pattern
for extending it is in `Http/Controllers/Api/`, and the Service layer each one
would call already exists.

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
- **Dashboard tiles take a `tone`.** `StatCard` and `SplitStatCard` colour the
  icon tile; `SplitStatCard` also colours each figure, and `MeterCard`'s `tone`
  colours the bar and badge while `iconTone` handles the tile. Tone means
  *valence*, not decoration — an approved absence is `info`, not `warning`, and
  a stat at **zero drops to grey by itself**, because "0 absent" in red reads as
  a problem when it is the opposite. The headline number in `StatCard` stays in
  the foreground colour: it is the thing being read.
- **`--grade-1` … `--grade-6` are a ramp, not six colours.** Dark green →
  green → yellow-green → yellow → orange → red, meaningful only in order.
  Reach for them when something is *a position on a scale*; keep
  `success` / `warning` / `destructive` for states that mean one thing
  (approved, pending, rejected). `Badge` exposes them as `variant="grade-3"`.
  The dark block lifts every stop — `#15803D` is L 29%, dark enough to
  disappear on the dark surface, which is the logo-subtitle mistake again.

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

## Credential expiry (Module 1)

**Credentials** watches the `expires_at` already stored on every 201-file
document. `EmployeeDocument::isExpired()` answers one document at a time and
only once it is too late; `CredentialExpiryScanner` looks *forward* instead —
config-driven and database-free, the same shape as `AttendanceExceptionScanner`.

- **The warning window is per document type**, in `config/credentials.php`, and
  it is not cosmetic: an LTO licence renewal wants weeks of lead time (60 days),
  a certificate does not (30). Widening a window is a config edit.
- `blocking_types` marks documents whose expiry legally stops the employee
  working — a driver with a lapsed licence may not drive, and the liability is
  the company's. That is a harder flag than an expired certificate.
- Scoped through `EmployeeService::scopedQuery()`, so it doubles as
  self-service: an employee sees their own licence running out, no second
  screen needed.
- It gets **its own topbar indicator, not the bell.** A link has one
  destination, and the bell already means "leave waiting on you". The indicator
  hides at zero — an always-lit icon stops being read.
- `--warning-foreground` was added for its badge and **flips between modes**:
  near-white on light mode's darker orange, near-black on dark mode's brighter
  amber, which would otherwise sit near 2.5:1.

## 201 file completeness (Module 1)

**201 File Status** answers what Credentials doesn't: not "what is about to
lapse" but "what was never filed". `OnboardingChecker` is config-driven and
database-free, the same shape as the other scanners — `config/onboarding.php`
holds what a complete file needs, so a new client audit demanding another
document is a config edit.

- **Requirements are per position.** A dispatcher does not need a driver's
  licence; a driver may not legally work without one, matched on a fragment of
  the position title.
- The same **blocking** distinction as Credentials: a missing contract or
  licence stops deployment, a missing résumé is untidy.
- Missing **government numbers** are reported too, and deliberately as
  non-blocking — they don't stop the person working, they stop the company
  filing for them. Compliance already catches this at remittance time, when
  the filing is due; this catches it while it is still cheap to fix.
- A complete file is not a finding. Listing every compliant employee would
  bury the ones that aren't.

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

Seven screens share `SectionTabs`: **Daily Records** (DTR + CSV import),
**Overtime** (file / approve / reject), **Shifts & Schedules**, **Holidays**,
**Reports** (per-employee aggregation, CSV export), **Exceptions**, and
**History**. Only HR records or corrects time; approvers are HR or the
employee's own supervisor, never the requester.

A shift still referenced by a schedule or a time record is **deactivated**
instead of deleted, so attendance history keeps its shift.

**Holidays** is small but load-bearing, and it is shared across three modules:
`LeaveService::workingDays()` skips holidays when costing a request,
`AttendanceCalculator` marks the day's status from them, and `PayrollCalculator`
pays the Labor Code premium (regular ×2.0, special non-working ×1.3). A year
with nothing recorded is therefore not an empty screen — it silently charges
employees leave credits for days they should not be charged for, so the screen
warns when the *next* year has no holidays yet. A holiday with attendance
already recorded against it cannot be deleted, only edited: removing it would
leave those records classified against a rule that no longer exists. Validating
the (date, name) key needs `whereDate`, not `Rule::unique` — see the
date-cast-column gotcha below.

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

**Credits accrue, they are not handed out.** The screen used to grant every
active employee a full year's entitlement on 1 January — wrong in the direction
that costs money, since someone hired in November started with the same fifteen
days as someone who had worked all year. `LeaveAccrualCalculator` earns credits
per **whole calendar month** of service instead: a 15-day type accrues 1.25 days
a month, and rates and any waiting period live in `config/leave.php`.

- **Whole calendar months, not anniversaries.** 1 January to 31 December is 364
  days — one short of twelve anniversary months — so anniversary counting would
  leave a full-year employee at 11/12 of their entitlement.
- `LeaveAccrualService::accrue()` **recomputes rather than adds**, so it is safe
  to re-run and needs no "already accrued this month" state to keep in step.
- A balance already spent past what accrual grants is **held at the used figure,
  not reduced**: dropping earned below used would invent a negative balance and
  imply the approved leave was never valid. Those cases are reported to HR
  instead.

## Payroll (Module 4)

**What counts as "already earned" is defined once**, on
`PayrollRun::REPORTABLE` / `scopeReportable()`: approved and paid, never a
draft. 13th-month pay, compliance remittances, and final pay all have to agree
on it, and they used to each keep a private copy of the list — which is how
final pay came to quote 13th month against payslips the 13th-month screen did
not even show. Read it from the model; do not re-write the condition.

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

**13th-month pay** (PD 851) is `ThirteenthMonthCalculator` — database-free and
unit tested, like the other calculators. The rule is one line, *total basic
salary earned ÷ 12*, and the whole difficulty is in **earned**: a payslip's
`basic_pay` is the nominal period salary, because `PayrollCalculator` takes
lateness, undertime, absences, and unpaid leave off *separately* as deductions.
Using `basic_pay` alone would pay a full 13th month to someone absent a month
unpaid, so those four deductions are subtracted back out. Allowances, overtime,
night differential, and holiday premium are excluded — it is computed on basic
salary, not gross. Pro-rating needs no special case: a mid-year hire simply has
fewer payslips. Like Compliance, only *approved* and *paid* runs count, and the
screen states the 24 December deadline rather than leaving it as a date the
reader has to know.

**Compliance** is the remittance and BIR reporting screen: SSS (R-3),
PhilHealth (RF-1), Pag-IBIG (MCRF), and the BIR alphalist, each with a CSV
export carrying a control total. `ComplianceReportBuilder` is database-free and
**reads figures back from stored payslips, never recomputes them** — otherwise
a new SSS circular in `config/payroll.php` would silently rewrite what was
already remitted. Only *approved* and *paid* runs are reportable; a draft is
still being corrected. An employee missing the relevant government number is
flagged, because the filing cannot include them until it is on their 201 file.

**Separation & Final Pay** closes the lifecycle the rest of the system opens.
`FinalPayCalculator` is database-free like the rest: unpaid salary + pro-rated
13th month + convertible leave, less outstanding loans. `SeparationService`
gathers those inputs from the modules that already know them — payroll for what
was paid, leave for what was not taken, compensation for what is still borrowed.

- **Figures are snapshotted on the row, not recomputed on read**, the same
  reason payslips are: a later change to salary, leave credits, or a loan
  balance must not rewrite a settlement already handed over.
- **Separation pay is deliberately excluded.** It is owed only for authorised
  causes at rates that depend on which cause applies, and getting that wrong in
  either direction is a labour case — that is a decision HR records, not
  arithmetic the system performs silently.
- The **loan deduction is capped at what the settlement holds.** Anything left
  is a debt to collect, not a negative cheque to hand someone; the breakdown
  says how much still stands.
- Status **follows the clearance checklist** rather than being set by hand, so
  the two cannot disagree. Blocking items (`config/separation.php`) hold up
  release; the rest are recorded only — withholding a final pay over an
  unreturned lanyard is not a defensible reason to miss a statutory deadline.
- Release is the point of no return, and follows payroll's **separation of
  duties**: HR staff prepare, an *admin* releases. It freezes the figures,
  settles the loans against the deduction, and marks the employee separated and
  inactive.
- The list counts down against DOLE Labor Advisory 06-20's 30 days, which is
  what turns it from a table into a work queue.

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
default; check `DB_PORT` in `.env` before assuming). Tests default to
in-memory SQLite regardless of the app's own connection (see `phpunit.xml`),
so a Postgres-only bug (like the `ilike` operator) won't show up in a normal
`php artisan test` run.

**`composer test:pgsql` runs the same suite against real Postgres**, in the
`primepower_hris_test` database. It only overrides `DB_CONNECTION` and
`DB_DATABASE` — host, port, and credentials come from `.env`, so no secret is
committed. PHPUnit's `<env>` entries do not override a variable already set in
the environment, which is what lets the override work at all. Run it before
trusting anything that touches raw SQL; the whole suite passes on both drivers
today, and that is worth keeping true.

The old `database/database.sqlite` is kept only as a pre-migration backup
under `storage/app/backups/` (gitignored, not the live source of truth).

**Foreign keys are indexed deliberately, not automatically.** Postgres indexes
the primary key side of a relationship and leaves the foreign key column bare,
so `2026_08_11_000001_index_foreign_keys` adds the ones the app actually joins
and filters on — supervisor scoping, payslips by employee, audit logs by user.
Five are left un-indexed on purpose (`departments.head_employee_id`,
`positions.department_id`, `kpis.department_id`, `kpis.position_id`,
`separations.processed_by`): small tables where the index costs writes for a
scan the planner would choose anyway, and nothing filters on the column —
`separations.processed_by` is only ever eager-loaded, which reads `users` by
its primary key. Adding a foreign key means deciding which of those two cases
it is.

Seed accounts (password `password`): `admin@primepower.test`,
`hr@primepower.test`, and `employee@primepower.test` — a rank-and-file login
with a supervisor above it, so the self-service half (own payslip, own leave,
own 201 file) and the approval routing can both be exercised. The supervisor
accounts are the seeded department heads; their emails are Faker-generated, so
read one out of the `users` table.

**The seeded payroll run is carried through to *paid*.** Everything downstream
of payroll reads finalised runs only, so a run left at `for_approval` leaves
13th-month pay, compliance, final pay, and every employee's payslip screen
empty on a fresh install — which looks broken rather than pending.

## Known gaps

All five modules are functional. Still outstanding: separation pay for
authorised causes (deliberately left to HR, see Payroll above); peer and
subordinate reviews are supported by the schema and scoring but have no
assignment UI (only self and supervisor are created at rollout); email
notifications (the bell and the credential indicator are in-app only).

Movable holidays — Maundy Thursday, Good Friday, and the two Eids — are
deliberately *not* seeded: they follow the liturgical and lunar calendars and
are fixed by annual proclamation, so HR adds them from Timekeeping → Holidays
once Malacañang publishes them. Only the fixed-date holidays under RA 9492 are
seeded, currently through 2027.
