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

Four screens share `SectionTabs`: **Daily Records** (DTR + CSV import),
**Overtime** (file / approve / reject), **Shifts & Schedules**, and **Reports**
(per-employee aggregation, CSV export). Only HR records or corrects time;
approvers are HR or the employee's own supervisor, never the requester.

A shift still referenced by a schedule or a time record is **deactivated**
instead of deleted, so attendance history keeps its shift.

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

## Gotchas that have already cost time

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

Currently **SQLite** (`database/database.sqlite`), though the project targets
PostgreSQL — swap `DB_*` in `.env` and re-run `php artisan migrate:fresh --seed`.
Tests always use in-memory SQLite.

Seed accounts (password `password`): `admin@primepower.test`,
`hr@primepower.test`.

## Known gaps

All five modules are functional. Still outstanding: 13th-month pay and final-pay
computation; a BIR alphalist / remittance export; peer and subordinate reviews
are supported by the schema and scoring but have no assignment UI (only self and
supervisor are created at rollout); holidays management screen (2026 holidays are
seeded, but there is no UI); leave credit accrual on a schedule (credits are
allocated in bulk per year); email notifications (the bell is in-app only);
departments/positions CRUD; `/profile` still uses the old Breeze layout; the
project still runs on SQLite rather than PostgreSQL.
