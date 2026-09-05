# PrimePower Manpower — HRIS (Core Transaction 2)

Fleet & Transportation HRIS. **All five modules are built**: Employee
Information, Timekeeping & Attendance, Leave & Absence, Payroll & Compensation,
and Performance Management.

## PrimePower is a manpower agency, not a single employer

This shapes Modules 1 and 4 and is easy to miss from the schema alone. The
workforce splits two ways, on `employees.employment_category`:

- **`internal`** — the staff who run the agency: HR, admin, accounting, plus
  the drivers crewing PrimePower's own vehicles. Filed against a department.
- **`external`** — deployed to one of the **client companies** in `clients`.
  Still PrimePower's employees, on PrimePower's payroll and contributions, but
  the client is who they report to and who is billed for them.

- **A client is master data, beside Departments** (`/hr/clients`), reusing the
  `manageOrganization` gate — in an agency a client *is* org structure, and the
  people allowed to shape one are the people allowed to shape the other.
  Anything with staff filed against it is deactivated, never deleted, so
  payroll and attendance keep the client they were filed under.
- **`client_id` is prohibited on internal staff**, not merely ignored. A stale
  client left on someone brought in-house keeps them in that client's billing
  and headcount — an error nobody would think to go looking for.
- **Deployment is a single `client_id`, with no history.** A move between
  clients therefore rewrites which client a *past* payslip is grouped under.
  Taken deliberately for a workforce that does not move often; the upgrade is a
  `deployments` table with start/end dates and a `clientAsOf()` read, the same
  shape as `SalaryAdjustmentService::rateAsOf()`.
- **There is no national minimum wage in the Philippines.** Each region's
  RTWPB issues its own wage order, which is what clients mean by a "provincial
  rate". `config('payroll.wage_regions')` holds the floors;
  `Employee::wageRegion()` resolves own posting → client's site →
  `default_wage_region`. Used to **flag, never to enforce**, like a position's
  salary band — and the figures go stale with every new wage order, so treat
  them as this system's assumption rather than as the law.
- **Deployment Readiness (`/hr/deployment`) is the composition screen.** It
  answers "can this person be sent to a client tomorrow?", which no single
  module can: it needs credentials, 201-file completeness, and employment
  standing at once. `DeploymentReadinessChecker` **re-uses
  `CredentialExpiryScanner` and `OnboardingChecker` rather than re-deriving
  either** — if it made its own judgement about a lapsed licence, the three
  screens would eventually disagree about the same driver. Both scanners run
  once over the whole set and are indexed by employee; a per-person loop would
  be two queries each. `blocked` here is not a louder warning: a driver whose
  licence has lapsed may not lawfully drive, so it is the one place the system
  says "this would be wrong" rather than "someone should look".
- **Nothing is destroyed by a delete button.** Employees and clients are both
  soft-deleted, and `/hr/archive` is the master list of what has gone —
  employees and clients on one screen, because "what did we delete" is the
  question, not "what did we delete from Employees". Restore reuses
  `EmployeeService::restore()`, which the API restore already calls, so the
  two entry points cannot drift on reactivating the login. Admin-only, behind
  `EmployeePolicy::viewArchive` (separate from `restore` because it is asked
  of the class, before any record is in hand).
- **`archive.restore_window_days` is a label, not a deadline.**
  `purge_after_days` is deliberately null and nothing is ever deleted on a
  timer: employment records must be kept three years under the Labor Code's
  IRR and payroll records ten under the NIRC, and an employee row anchors
  payslips and BIR alphalists that outlive any UI retention window. The 30
  days only decide whether a row reads as a recent mistake or as history.
- **A client with staff on it is *deactivated*, not archived** — it stays
  listed and selectable in reports, and only stops being offered for new
  deployments. Archiving it would strand the headcount and payslips grouped
  under it. Only a client nobody was deployed to leaves the working list.
- **One payroll run, split by who it is billed to.** `breakdownByClient()` on
  the run screen groups totals per client plus internal staff, and the payslip
  list filters by `client_id` / `employment_category`. Deliberately *not* a
  separate run per client: a run is one statutory filing, and splitting it
  would mean five SSS remittances for one month.

## Stack

Laravel 12 + Inertia 2 + React 18 + Tailwind 3, served by Herd at
`http://core2.test` (use `http://` — the site is not secured for TLS).

**Architecture:** Inertia renders the UI *and* a token-authenticated REST API
lives at `/api/v1`. Both entry points call the same Service class, so behaviour
can't drift between them. Controllers stay thin: authorize, delegate, respond.

**The API is where Core 2 meets the rest of ISMERS**, and it is documented for
the other teams in `docs/INTEGRATION.md`. Two doors accept writes — Core 1
proposes a hire (`POST /endorsements`), Core 3 posts a loan for payroll to
deduct (`POST /loans`) — and everything else is read-only.

- **Every published figure comes from the service that already computes it for
  our own screens.** `/drivers` reads `LicenseVerifier`, `/deployment-readiness`
  reads `DeploymentReadinessChecker`, `/payroll/runs` reads
  `PayrollRun::scopeReportable()`. Nothing is re-derived for the API, because a
  consumer and one of our screens disagreeing about the same driver would mean
  one of them is running its own copy of the rules — and the disagreement would
  surface on a dispatch sheet rather than on a screen somebody is watching.
- **`DriverResource` is not `EmployeeResource` with extra fields.** Fleet wants
  one answer before a run is assigned — may this driver lawfully take this
  vehicle, on this shift — so it carries DL codes, conditions, and expiry, and
  carries no salary, bank details, or government numbers at all. An integration
  that hands over more than the consumer needs is the failure noticed after a
  breach rather than before one.
- **A draft payroll run answers `409`, not `404`.** The run exists; it is not
  disbursable yet. That is the difference between "retry later" and "wrong id",
  and Finance needs to be able to tell them apart.
- **Loans are amortised here and nowhere else.** Core 3 approves the loan and
  answers to the employee for it; only this system can take money off a
  payslip. A design where Core 3 kept its own balance and told us what to
  deduct each period fails the first time a run is recomputed — the deduction
  applies twice and the two balances part company with nobody watching.
- **`/analytics/workforce` returns shapes, never people.** A dashboard needs
  counts, and an endpoint that hands over the directory to draw a bar chart is
  the endpoint that will one day be the way the directory left.

Leave and performance are Inertia-only today; the pattern for extending the API
is in `Http/Controllers/Api/`, and the Service layer each one would call
already exists.

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
- **A list screen's "create" action sits with its filters**, at the top-right
  of the table's own card — never in `AppLayout`'s `actions` slot, and never
  as a floating button (both were tried; the filter row is where it landed).
  The card takes one of two shapes depending on what the screen already has:
  - **Has a filter row** (Employees, Leave, Timekeeping, Overtime): the button
    ends that flex row, pushed right with `ml-auto`. Match the row's own
    breakpoint — `lg:ml-auto` where the row is `lg:flex-row`, `sm:ml-auto`
    where it is `sm:flex-row` — or it detaches on the size in between.
  - **Filters live in `CardHeader`'s `action` prop** (Departments, Positions,
    Salaries, Separations, Holidays): the button joins that same flex group.
  A screen with no filters at all (Payroll Runs, API Tokens, Users) still uses
  the `CardHeader` `action` slot, so the button lands in the same place either
  way. Workflow actions (Submit, Approve, Release, Print) are not create
  actions and stay where they are.
- **`CardHeader` stacks below `sm`, and its `action` has to cope with a narrow
  line.** The action slot holds real controls, not just a button: Departments
  puts a 224px search box *and* a "New Department" button in it, and Positions
  adds a department filter on top of that — about 530px of content. A 375px
  phone leaves 301px there once page and card padding come off, and the slot
  was `shrink-0`, so the row overflowed the card and pushed the whole page
  sideways while truncating the title to nothing. The header stacks now, which
  buys the action a full-width line; the actions that hold two or more controls
  stack themselves the same way (`flex flex-col gap-2 sm:flex-row`) and their
  fixed widths are `w-full sm:w-56`, because a full-width line is still only
  301px and a 224px box beside a button does not fit in it.
- **A fixed `w-*` on a filter control is a mobile bug unless it is inside
  something that already copes** — a `flex-wrap` row, a table cell (tables
  scroll), or a modal. Write `w-full sm:w-52`, not `w-52`.
- **Seven columns is seven columns.** The leave calendar and the date picker
  cannot become one column on a phone without ceasing to be calendars, so the
  calendar scrolls inside `min-w-[560px]` instead of shrinking to 38px a day —
  narrower than the pill naming who is off, which would truncate every entry to
  nothing. The negative margin on that scroller is what lets it run to the
  card's edge rather than stopping inside its padding.
- **A label that only exists on desktop belongs in `hidden sm:inline`.** Every
  `AppLayout` `actions` button already does this, which is why the topbar holds
  up on a phone: the icon stays, the word goes.
- **A module with several screens is one sidebar entry with `children`**, which
  the sidebar renders as an expandable dropdown — not a flat link per screen.
  Payroll's seven screens live this way, same shape as Employee Information,
  Timekeeping, Leave, and Performance. Every child `href` is picked up by
  `ALL_HREFS` in `navigation.js` automatically, which is what lets `bestMatch()`
  highlight the right entry when its screen is open. Children filter by role
  like every other nav entry.
- **Those entries sit in four labelled groups** — Employee Management, Time &
  Attendance, Payroll & Performance, System — above an unlabelled Dashboard.
  The grouping is presentational: it is what gives the sidebar its rhythm, and
  it moves no module, route, or permission. Five modules under one heading read
  as a flat list of five things rather than as a system with parts.
- **A fifth group, AI & Analytics, holds the screens that read across modules
  rather than maintaining one.** Credentials, 201 File Status, Attendance
  Exceptions, and Deployment Readiness each run a config-driven rule engine
  over data another module owns, and none of them owns a table. They used to
  sit under whichever module they happened to read from — Credentials under
  Employee Information, Exceptions under Timekeeping — which filed them by
  their input rather than by what they are. Moving them changed no route,
  controller, or permission: `ALL_HREFS` is derived from every group, so
  `bestMatch()` still lights the right entry.
- **Settings is no exception — its seven sections are sidebar `children` too.**
  This went the other way first: a flat link to `/settings/appearance`, on the
  reasoning that `SettingsLayout` already rendered the same seven beside the
  page and a sidebar copy would duplicate them. Living with it showed the
  duplication was the cheaper problem. The in-page column cost the settings
  forms width they needed — at 1024px the app sidebar takes 260px and the
  section list took another 224px, leaving about 468px, squeezed at exactly
  the width where a two-column layout was meant to start helping. The in-page
  list is gone; the sidebar is the only copy again, and settings pages get the
  same full-width canvas as every other screen. `SETTINGS_SECTIONS` stays
  exported as the single description of what Settings *is*: the sidebar
  children mirror it, and the two must agree on labels **and** on `roles` —
  Appearance and Security are open to every signed-in user, the other five are
  admin-only, and the server enforces the same split with a 403.
- Chart marks use `--chart-1`, held apart from `--primary` because chart fills
  have to sit inside an OKLCH lightness band that `--primary` misses in dark mode.
- **A summary tile that counts something must be able to show it.** Every
  figure on a list screen links to the rows it counted, through
  `withFilters()` — same path, same filters, one more narrowing. A tile that
  counted 53 late days in March and then opened an unfiltered list has not
  answered the question it raised, it has replaced it.
  - **The link has to return the number on the tile**, and twice it did not.
    "Days Present" counts three statuses (`present`, `late`, `undertime`) and
    linked to `status=present`, reading 1,230 and opening 581. "Awaiting
    Action" counts two (`pending`, `supervisor_approved`). Both were found by
    clicking the tile and counting, not by reading the code. Where the count
    crosses a filter, the filter is *added* to match it — `attended`, `late`,
    `awaiting`, `blocking` — rather than the tile being quietly re-scoped.
  - **Filters that narrow the same axis are cleared together.** Drilling into
    Late and then into Absences would otherwise ask for both and return the
    days somebody was absent *and* late, which is none of them. The dropdowns
    clear the tile-only keys too.
  - **A tile-only filter needs a visible chip.** `late` and `attended` match
    something no dropdown can show, so the filter row draws a removable pill
    when one is on. A list silently narrowed by an invisible filter is a list
    nobody can explain.
  - **Screens whose whole table fits on one page do not link at all** —
    Departments, Positions, Clients. Filtering a ten-row table hides rows the
    reader can already see.
- **Tone is valence, and zero is grey.** A tile in amber is a thing somebody
  has to do something about; `info` is a state, not a fault (an approved
  absence, a scheduled raise); `destructive` is reserved for what is unlawful
  or past a statutory deadline — a lapsed licence, a final pay past DOLE's 30
  days. Every count drops to `muted` at zero, because "0 blocked" in red reads
  as a problem when it is the opposite.
- **A share beats a count wherever there is a denominator.** `MeterCard` says
  "1,230 · 65%" where a `StatCard` said "1,230": 38 active means nothing until
  you know the roster is 41 rather than 400, and the same 40 exceptions read
  differently at 2 critical than at 30.
- **Dashboard tiles take a `tone`.** `StatCard` and `SplitStatCard` colour the
  icon tile; `SplitStatCard` also colours each figure, and `MeterCard`'s `tone`
  colours the bar and badge while `iconTone` handles the tile. Tone means
  *valence*, not decoration — an approved absence is `info`, not `warning`, and
  a stat at **zero drops to grey by itself**, because "0 absent" in red reads as
  a problem when it is the opposite. The headline number in `StatCard` stays in
  the foreground colour: it is the thing being read.
- **The dashboard reads in four bands**, top to bottom: headline `StatCard`s,
  then the `SplitStatCard` / `MeterCard` detail row, then charts, then the
  three summary cards. `StatTile` and `TilePreview` are the units the summary
  cards are built from — a row of tinted figures over the single most recent
  record. One preview row, never a list: the card is a glance, and the screen
  behind it is where the rest lives.
- **`TrendChart` is one series only.** The fill under the line reads as "this
  quantity"; two overlapping fills stop meaning anything. It paints with
  `currentColor` so the caller sets `text-chart-1` and the SVG gradient stays
  on a token — a gradient stop needs a real colour value, and `currentColor`
  is the only way to give it one without a hex. Its axis is padded away from
  the data rather than anchored at zero: headcount moving 34 → 39 against a
  0-based axis is a flat line, and the change is the thing being shown.
- **Company-wide figures on the dashboard are gated.** Total net pay,
  everyone's leave counts, and the payroll stage breakdown are HR's view of
  the organisation, behind `can.viewCompanyFigures` (`isHrAdmin()`). They are
  withheld in the *controller*, not hidden in the component — the tile used to
  print the month's total net to every signed-in role, which is the same line
  `EmployeePolicy::viewSensitive` draws for salary on a record. A withheld
  summary arrives as `null` and its card is not drawn; payroll arrives zeroed
  so the four-column headline row keeps its shape.
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

## Document scanner (Module 1, AI)

**The one AI feature in the system.** `DocumentScanner` reads a scanned 201-file
upload — LTO licence, NBI clearance, PhilSys ID, medical certificate, contract —
and proposes `type`, `title`, `issued_at`, and `expires_at` on the upload form.

It exists because `CredentialExpiryScanner` is only as good as the `expires_at`
someone typed: a licence keyed a year late is a driver the system believes is
legal to dispatch.

- **It never writes to the database.** It fills a form; HR corrects it; the
  existing `StoreEmployeeDocumentRequest` validates the save exactly as it does
  a hand-typed entry. Same shape as PayrollReadiness warning without blocking.
- **Everything the model returns is untrusted input.** The type is checked
  against `EmployeeDocument::TYPES` (a hallucinated type becomes `null`), dates
  are re-parsed through Carbon, and **the name check runs in PHP, not in the
  prompt** — catching a document filed under the wrong employee should not
  depend on the thing being checked.
- **A null is a valid answer.** The prompt says so explicitly, and the form
  only fills fields that came back non-null — overwriting with a null would
  erase a correction HR had already typed.
- **It runs on the host, not in the cloud.** `SCANNER_DRIVER=ollama` (the
  default) sends the image to Ollama on `127.0.0.1` — `glm-ocr`, 0.9B and
  2.2 GB, small enough for a 4 GB card and built for documents rather than
  chat. The decision is not really about cost: a 201-file scan is a
  photograph of somebody's PhilSys ID or NBI clearance, and posting that to a
  third-party API is a cross-border transfer of personal data under RA 10173.
  Locally the image never leaves the machine.
- **Three drivers, one shape.** `ollama` (local, default), `gemini` (hosted,
  free tier), `anthropic` (hosted, paid). All three are constrained by the
  same JSON schema, so everything downstream of `read()` is driver-agnostic —
  and a test asserts the normalised keys are identical across the three,
  because a drift would otherwise only appear in production on whichever
  driver the suite does not exercise.
- **`gemini` exists because Ollama cannot be deployed.** It has to be
  installed and running on whatever serves the app, and a small VPS cannot
  hold even a 2.2 GB vision model — so on a deployed instance the scanner
  goes dark. Gemini runs from anywhere. The cost is exactly what Ollama was
  chosen to avoid: the scan leaves the country, which under RA 10173 needs
  consent and disclosure. Switching a real deployment to it is a decision
  someone makes deliberately, not by editing an env file.
- **The deployment driver had never been run, and did not work.** `gemini`
  was posting an OpenAI-shaped body (`input`, `response_format`) to
  `/v1beta/interactions`, which is not a Gemini endpoint — Gemini names the
  model in the URL (`/v1beta/models/{model}:generateContent`) and takes
  `contents[].parts[].inline_data`, `systemInstruction`, and
  `generationConfig`. Nothing caught it because every scanner
  test stubs `read()`, which is right for the rules around it and leaves the
  envelope untested — and the only driver anyone runs locally is Ollama. The
  driver that exists *for deployment* was therefore the one with no test.
  `ScannerDriverRequestTest` now asserts what each driver puts on the wire.
- **The free tier is 20 scans a day, per model.** Measured, not read: Google's
  429 body names the quota — `GenerateRequestsPerDayPerProjectPerModel-FreeTier`,
  value `20`. Enough to demonstrate the feature and nowhere near enough to run
  an HR department on, which makes the free tier a *development* driver rather
  than a deployment one. Paid billing lifts it; so does going back to Ollama.
- **Gemini is retried and the other two are not**, because only this one shares
  a quota. Six identical scans on a real key came back three answered and three
  429, so a driver that gave up on the first would look broken half the time
  while being perfectly configured. Only 429 and 5xx are waited out — a 400 or
  a 401 is an answer, not a queue.
  - **`retry()` needs its exception to be thrown.** The first attempt passed
    `throw: false` to keep a bad key falling through to the existing handler,
    which quietly turned the retry into a no-op: Laravel only retries what
    throws. The throw is caught below instead, and logged with the same two
    fields a plain failure carries.
  - The 429 body says "this model is currently experiencing high demand",
    which reads as load and is a quota. The status code is the honest signal;
    the prose is not.
- **The schema field was wrong too, and that was found later still.** Gemini
  has *two* of them and they take different dialects: `responseSchema` is an
  OpenAPI 3.0 subset whose `type` is a single value, and `responseJsonSchema`
  takes real JSON Schema. This schema is full of `["string", "null"]` unions
  and carries `additionalProperties`, so on `responseSchema` every request came
  back `INVALID_ARGUMENT` — "Proto field is not repeating, cannot start list".
  It is `responseJsonSchema` now, which is what lets all three drivers be sent
  the *same* schema, which is the only reason everything downstream of `read()`
  can stay driver-agnostic.
  - **Gemini validates the body before the API key**, so a wrong schema and a
    wrong key are indistinguishable from outside — both simply fail. That is
    also what makes the envelope testable without a key at all: send a
    deliberately invalid one and read which error comes back.
  - CLAUDE.md previously recorded the opposite ("the schema itself was fine"),
    which was asserted from the documentation rather than from a request. The
    docs describe `responseJsonSchema`'s dialect; the code was sending
    `responseSchema`.
- **The caption is read along with the number, and must not refuse the card.**
  A real scan returned `document_number` as `"NBI ID NO.: N2G4-25-123456"`.
  `numberMatches()` compared for equality, so that read as a *contradiction* —
  and a contradicted number blocks the upload, which meant the correct
  document, correctly read, was refused because of the words printed beside
  the number. It compares by containment now, and `documentNumber()` strips
  the caption off what the form shows. A false negative is the expensive
  direction here; the stored numbers are 9–16 characters, so a coincidental
  substring match is not a real risk.
- **What a document *is* is decided from five sources, strongest first.** It
  used to be two — a keyword in the printed heading, else the model's own key.
  Three more were already in data the scanner returns and were going unread.
  `DocumentScanner::resolveType()` tries, in order:
  1. **A number already on this employee's 201 file.** If the printed number
     matches what HR typed into the licence field, the paper is a licence.
     The only signal here with a human behind it rather than the model.
  2. **The document's own printed heading** — evidence about the paper, from
     the paper.
  3. **The shape of the number**, against `type_defining_formats`.
  4. **How long it is valid for**, against `validity_months`. Two dates the
     model transcribed separately, so the gap is not something it can bend to
     fit a guess: five years is a licence and nothing else in a 201 file runs
     that long.
  5. **The model's own key**, last, measured wrong 4/4 on an NBI clearance
     whose heading it transcribed correctly every time.
  - `type_certain` is true only for the first two, and that is what the upload
    form refuses over. A shape is shared between cards and a validity period
    overlaps between types; blocking on either would refuse correct filings.
    The panel names the source either way, because "this is a Clearance" and
    "this is a Clearance because its heading says so" are different claims.
  - **`type_defining_formats` is deliberately shorter than `number_formats`.**
    A pattern good enough to *check* a number once the type is known is not
    tight enough to *decide* it: the passport shape `[a-z]{1,2}\d{6,9}` is a
    fair check on a filed government ID and it swallowed a medical certificate
    numbered "MC-2026-4471" — two letters, eight digits, a perfect fit for a
    rule never meant to classify. Only the LTO licence and the 16-digit
    PhilSys PSN are unmistakable enough to survive the shorter list.
  - **The issuing authority is the strongest thing printed, and it is
    printed in full.** The keyword list began as abbreviations and filed a
    real NBI clearance as a licence: its letterhead reads "REPUBLIC OF THE
    PHILIPPINES / Department of Justice / National Bureau of Investigation"
    and the word "NBI" is nowhere on it. `title_keywords` now carries the
    agencies as they write themselves — DOJ/NBI, PNP, LTO, PSA, DOH, SSS,
    BIR, PRC, DFA — not the shorthand people say.
  - **The heading is returned as well as used** (`heading`), and shown on the
    panel. It used to be replaced by the derived label and thrown away, which
    is what made a wrong type impossible to explain: three attempts were made
    at improving the classifier while the one piece of evidence saying what to
    fix was being discarded. It named the NBI bug in one second.
  - **A guess the document contradicts is discarded, not replaced.** A
    résumé carries no ID number and does not expire; a PSA civil registry
    document does not expire either (`type_cannot_have`). When every positive
    signal has declined and the model answers "resume" while reporting a
    printed number, the reading has argued with itself — the type comes back
    **null**, which the form leaves blank, rather than a second guess. This is
    the miss that prompted it: a real government ID, no heading keyword, an
    unrecognisable number, no dates, filed as a Résumé and looking confident.
    The rule covers only the two types where the claim is absolute; a contract
    genuinely has an end date, so ruling it out would discard correct readings
    to catch wrong ones.
  - The check applies **only to the model's guess**. Evidence from the paper
    or the 201 file is not second-guessed by an absence: a licence whose
    expiry the model failed to read is still a licence.
  - Where real documents overlap, the rule **declines** rather than inventing
    certainty — a one-year validity fits both a clearance and a medical, so it
    decides nothing and the guess stands. A range narrowed to force an answer
    would be manufacturing confidence.
- **Whether the document has already lapsed is said at the moment it is
  filed.** The check only existed *afterwards* — `CredentialExpiryScanner`
  reads stored rows — so a licence two years out of date was uploaded, looked
  correct on the panel, and surfaced on another screen later. `expiry` now
  reports `expired` / `expiring` / `valid` with the day count and whether the
  type blocks work, reading `credentials.warning_days` and
  `credentials.blocking_types` rather than holding a second opinion: the
  upload panel, the Credentials screen and Deployment Readiness must not
  disagree about the same licence. **Reported, never refused** — an expired
  document is filed deliberately often enough (for the record, or during a
  renewal) that blocking the upload would leave the 201 file emptier than the
  truth, and Deployment Readiness is the screen that stops somebody being
  sent out. Shown per row in the batch filer too, where forty expiry dates is
  forty chances to let one through.
- **A type that cannot expire is never given a date.** `type_cannot_have`
  states one fact — a résumé has no ID number and does not lapse, a PSA civil
  registry document does not lapse — and it does two jobs from it: it rejects
  the model's guess when the document contradicts it, and it clears the field
  once the type is settled *by any means*. The second half matters on its own,
  because a PSA certificate identified from its letterhead is correctly typed
  and the model can still have read a date off it: PSA paper prints an issue
  date and a registry date, and a misread of either arrives looking like an
  expiry. Kept, it would put a birth certificate into
  `CredentialExpiryScanner`'s renewal queue to be chased forever for a
  renewal that does not exist. The panel then says **"Does not expire"**
  rather than "Not found", which would read as a failed reading rather than a
  fact — and the list it reads is shared from the same config, so the screen
  and the scanner cannot disagree about which documents lapse.
- **An expiry date already in the past refuses the upload**, alongside the
  name and type checks. It reads the **form field**, not the scan, and that is
  the design: the model misreads this date — a real upload returned "Jul 25,
  2024" from a line that was a date of birth — so blocking on what the scanner
  said would refuse a current document over a bad reading with no way out.
  Blocking on the field means correcting the date lifts the block, which is
  precisely the recovery a misread needs, and it also catches a past date
  typed by hand with no scan at all. The refusal is stated **on the field**
  rather than only in the scan panel, since a disabled button with no reason
  beside it is where somebody stops trusting the screen. The escape hatch for
  a document that genuinely must be filed expired is the one the name check
  already uses: upload it as a PDF, which the scanner never reads.
- **A PSA certificate is read a second time, in its own terms.** The general
  prompt asks for an ID card's fields — a number, an issue date, an expiry —
  and a Certificate of Live Birth has none of them, so the model answered with
  whatever sat nearby: the receiving date became `expires_at` and the mother's
  occupation became the `note`. It was not misreading the page; it was
  answering questions the page does not have. `readCivilRegistry()` runs only
  once the type has resolved to `psa`, and asks by the form's own numbered
  labels — item 1 the child, item 6 the mother's maiden name, item 13 the
  father. One extra call, on a handful of uploads.
- **A birth certificate names the employee's child, so the name check cannot
  be the gate.** `scanner.names_may_differ` exempts `psa` from the refusal —
  the check still runs and HR is still told whose name is on the paper. The
  question worth asking is instead **whether the employee is a parent on it**,
  compared with the same `nameMatches()` rules rather than a second set.
- **Two parents sharing given names is a misread, not a coincidence.**
  Measured: on a certificate whose father is "Leopoldo Jr. Arong Pikit Pikit",
  the mother came back as "Leopoldo Jr. Arong Bontigao" — her surname from the
  right box, his given names bled in. Compared on the **first two tokens**;
  everything-but-the-last was tried first and missed it, because his surname is
  two words and hers is one. Which box was misread cannot be told from the
  values, so neither is trusted and the parent check returns null rather than
  an answer — the same choice the validity ranges make where documents overlap.
- **The small model is good at reading and poor at judging**, and the design
  leans on that split. Numbers and dates come back right; free text does not.
  So the **title is derived from the validated `type`** (`scanner.labels`)
  rather than taken from what the model wrote — the type is checked against
  `EmployeeDocument::TYPES`, the free text is checked against nothing, so
  between the two the derived label is the sounder source. `text()` also
  flattens newlines and caps length: these land in single-line inputs, where
  a newline silently becomes a space and a block of OCR output arrives
  looking like a deliberate answer.
- **A dark feature is not a broken one**: with no driver configured (or no
  key on the anthropic driver) `can.scanDocuments` is false, the button is
  never drawn, and the endpoint 404s. Uploading by hand works exactly as
  before. `isEnabled()` deliberately asks config, not the network — pinging
  Ollama would make the button truthful but put an HTTP call in every page
  render and tie the test suite to what happens to be running. A server that
  is down is handled where every other failure is: `read()` logs and returns
  null, the form stays empty, HR types the fields.
- **`php artisan scanner:check` asks whether the configured driver is really
  there.** `isEnabled()` deliberately reads config rather than the network, and
  the cost of that is a deployment with `OLLAMA_HOST` still set but no Ollama
  behind it: the button draws and every scan fails silently. This is the other
  half of that bargain — one real image through the real driver, run once after
  a deploy or an env change, reporting *switched off* and *configured but
  broken* as the different answers they are. Not automatic and it gates
  nothing.
- Images only, ≤5 MB (`config/scanner.php`). A PDF or DOCX upload skips the
  scanner rather than failing.
- **Setup**: `winget install Ollama.Ollama` then `ollama pull glm-ocr`. The
  server starts itself on boot. First scan after a reboot takes ~40 s while
  the model loads into VRAM; every one after that is ~3 s, which is why
  `scanner.ollama.timeout` is 180 and not 30.
- `DocumentScanner::read()` is `protected` for one reason: the SDK's
  `MessagesService` is `final`, so tests stub that single method to exercise
  every rule around it without a key or a network call.

## Measuring the scanner (AI & Analytics)

**Scanner Accuracy (`/hr/scan-accuracy`)** answers how well the one AI feature
actually works — and it is the instrument that produces the accuracy figures a
write-up has to report, so it is one job with two deliverables.

- **The model's own `confidence` is not used, because it carries nothing.**
  Asked across six documents it answered "high" six times, including on the
  readings that were wrong. A dashboard built on a model's opinion of itself
  reports the model's mood. So accuracy is taken from the only thing that
  carries information: **whether HR kept the value or typed over it.**
- `document_scans` stores the proposal when the scan runs and completes it when
  the document is filed. **The row is written at scan time on purpose** — a
  scan somebody abandoned is a real failure, usually a reading bad enough to
  start over, and counting only the scans that ended in a filed document would
  flatter every figure on the screen.
- The upload posts a `scan_id` and nothing else about the proposal. The values
  are read back from the row server-side and the lookup is scoped to that
  employee, so a form cannot attach a stranger's measurement or claim an
  outcome it did not have.
- Only `type`, `title`, `issued_at`, `expires_at` are compared. The number and
  the name are *checks* rather than stored values, so there is nothing to
  compare them against, and counting them would score the scanner as always
  wrong on two of six fields.
- Comparison is case- and whitespace-insensitive: rewarding a trailing space
  would understate the scanner rather than measure it.
- **The headline is the clean rate**, not a per-field average — "how often does
  this just work" is the question a reader actually has. Duration is the
  **median**, because the first scan after a reboot loads the model into VRAM
  and takes forty seconds, and one of those misdescribes every other scan.
- The by-source table is where the five-way type precedence is *checked*
  against outcomes rather than asserted. If the ordering is wrong, it shows
  there.
- Behind `viewAuditLog` rather than a new permission: it is the same class of
  thing, a record of what the system and its users did.

## Hiring comes from Core 1 (Module 1)

**PrimePower does not hire into this system directly.** Core 1 recruits; Core 2
employs. A hire arrives over the API as an **endorsement**, waits in an inbox,
and becomes an employee only when somebody here approves it — so the act of
putting a person on the payroll has a decision, a decider, and a date attached
to it.

- **`/hr/employees/create` is unreachable without an endorsement**, and that is
  the point. It was left open at first and the rule was immediately cosmetic:
  the queue was one way in among two, and the second kept no record of who
  accepted anybody or why. A bare visit now redirects to the inbox rather than
  403ing — the person *is* allowed to create employees, they are just in the
  wrong place to start.
- **Bulk import stays open, because it is a different act.** Digitising a
  workforce that already works here is not hiring: there is no endorsement for
  somebody on their sixth year. `/hr/employees/import` and the batch document
  filer are untouched.
- **Approving opens the form; it does not create the record outright.** Core 1
  cannot know the basic salary, the pay frequency, the employment category, or
  which client is billed, and those are not fields to default silently on
  somebody about to be paid. `EndorsementController::show` names them under
  "Still needed here" *before* the click, rather than meeting the reviewer as
  six required fields afterwards.
- **The employee is still created by `EmployeeService::create()`.** Approving
  is creating, and that has always gone through one place — employee
  numbering, the photo, and the optional login all live there. `EndorsementService`
  deliberately does not create employees; it hands the form its starting values
  and links the record afterwards. A second creation path would eventually
  disagree with the first about one of the three.
- **`endorsement_id` is the only thing the form asserts about the endorsement.**
  The values are re-read from the row server-side and the ability re-checked —
  the same shape as `scan_id` on a document upload, and for the same reason: a
  form must not be able to claim what it was given.
- **What Core 1 may *not* send is as deliberate as what it may.**
  `basic_salary`, `client_id`, `department_id`, `position_id`,
  `employment_category`, `employment_status`, and `pay_frequency` are absent
  from `StoreEndorsementRequest` and dropped from the payload. Accepting them
  would let another system decide what PrimePower pays and who it bills, over
  an API token, with nobody here having agreed to either.
- **Everything else is optional, down to a reference and a name.** Refusing to
  *receive* somebody is the one outcome this queue exists to avoid: a
  recruitment system that cannot hand a candidate over because it does not know
  this system's pay frequency has not been integrated with, it has been locked
  out — and the workaround is re-typing the record by hand, which is what the
  handover was for. Missing detail is a gap on the review screen, not a refusal.
  Everything a payroll record needs is still required by `StoreEmployeeRequest`,
  at approval, where it always was.
- **`reference` is Core 1's own identifier, and it is what makes a retry safe.**
  A timeout on their side is indistinguishable from a failure, so they resend —
  and two rows for one person is two approvals, two employee numbers, and one
  person paid twice. A resend returns the existing row with **200** rather than
  201, so they can tell a duplicate from a fresh submission without either being
  an error. A resend after a decision returns the decided row: an answer already
  given is not undone by the sender repeating the question.
- **A decided endorsement is closed.** `EmployeeEndorsementPolicy::decide()`
  requires `isPending()`, so re-deciding cannot create a second employee from
  one endorsement or overwrite who was recorded as approving the first.
- **A rejection needs a reason.** Core 1 reads the outcome back from the row,
  and a recruiter told only "rejected" sends the same candidate again — the
  queue fills with the same unstated argument.
- **A named client makes the hire external, and that is not cosmetic.**
  `client_id` is *prohibited* on internal staff rather than ignored, so
  prefilling the client while leaving the category at its 'internal' default
  would hand back a form that refuses to save, with the error on a field the
  reviewer never touched. `formDefaults()` sets both together for that reason.
- **Master data is matched exactly, or not at all.** Core 1 sends "Driver" in
  words; this system files against a `position_id`. An unmatched title leaves
  the select empty rather than inventing a row — a spreadsheet must not reshape
  the org chart behind `manageOrganization`'s back, and another system is no
  more entitled to. Deactivated positions and clients are skipped: they are kept
  so history keeps what it was filed under, not so a new hire can be filed
  against them. The match is exact rather than fuzzy, unlike
  `DocumentScanner::nameMatches()` — a near miss between "Driver" and "Driver
  (Heavy Vehicle)" is two salary bands, and master data is chosen from a list
  and has one spelling.
- **`EmployeeEndorsement` is not the employees table with a status column.** An
  endorsement has no employee number, no salary, no contributions, and may never
  have any — filing it as a half-built employee would put it in the scope of
  every query meaning "our workforce", and the first one somebody forgot to
  exclude a rejected candidate from would be a stranger on a remittance.
- **The inbox is a work queue, so it opens on what is waiting.** `pending` is
  the default filter; `all` is an explicit choice. The summary tiles count the
  whole table, not the filtered view.
- **`php artisan endorsements:simulate` files one without Core 1**, going
  through `EndorsementService::receive()` rather than inserting a row — a
  shortcut that wrote straight to the table would prove the screen renders and
  nothing else. It prints the equivalent `curl` afterwards, which is what Core 1
  actually sends.

## Digitising an existing workforce (Module 1)

Two ways in on the Employees list, plus the paper form on the create screen —
the same act at different scale rather than three separate features. They sit
beside each other for that reason; there is no "Add Employee" among them any
more, because a *new hire* comes from Core 1 (above) and these are for a
workforce that already exists.

- **Bulk import (`/hr/employees/import`)** — `EmployeeImporter`. The columns
  are the columns `DataExportController` writes: export, correct the
  spreadsheet, import. A round trip that does not round-trip is a trap.
  - **A preview writes nothing**, unlike `AttendanceImporter` which commits as
    it reads. A malformed DTR row is a day somebody re-keys; forty employees
    created by accident are forty records and forty burnt employee numbers.
    The file is uploaded twice and re-validated on commit, so nothing the
    browser sends in between decides what is created.
  - Employee numbers are taken **at commit**, never at preview — previewing
    twice must not spend two.
  - `error` refuses the row, `warning` still creates it, and the split follows
    the system's own line: a missing government number does not stop somebody
    working, so it must not stop them being recorded either.
  - **`employment_category` is required, never defaulted.** Filing deployed
    staff as internal by omission keeps them off a client's headcount — the
    same reason the form refuses it.
  - An unknown department or position is a **warning**, and the employee is
    left unfiled. Inventing master data would let a spreadsheet reshape the
    org chart behind `manageOrganization`'s back.
  - **Dates are read day-first.** `Carbon::parse()` reads a slashed date
    month-first, so "15/02/2026" throws and "05/02/2026" silently becomes
    2 May — a hire date six weeks out, which moves regularisation, 13th-month
    proration, and the first payslip with it. Unambiguous slashed dates are
    read from the numbers; a genuinely ambiguous one is read day-first *and
    reported*, so the only case that can be wrong is the one somebody is asked
    to look at.
- **Paper 201 forms (`Scan form` on the create screen)** —
  `DocumentScanner::scanEmployeeForm()`. A spreadsheet imports in bulk; a
  filing cabinet does not. Same driver, different prompt and schema — the
  three drivers now take both as arguments so there is still **one** place the
  Gemini envelope can be wrong.
  - Only blank fields are filled, so a second scan cannot overwrite a
    correction.
  - **A value copied from a neighbouring line is dropped.** Measured: on a
    sheet with no RELIGION and no PERMANENT ADDRESS the model answered
    "Mother" (the emergency contact's relationship) and the SSS number. Two
    checks catch it — an address that is almost all digits is somebody's ID
    number wearing an address's label, and a value appearing in two unrelated
    fields is a copy rather than a coincidence, where the field with no shape
    of its own loses. `present_address` matching `permanent_address` is
    exempt: "same as present address" is what people write.
- **Batch filing (`/hr/employees/documents/batch`)** — `BulkDocumentFiler`.
  The single-document upload already knows whose file it is; here the question
  runs the other way — *whose is this?*
  - It reuses `DocumentScanner::nameMatches()` (made public for exactly this)
    rather than re-deriving the token and Levenshtein rules, the same reasoning
    that has `DeploymentReadinessChecker` reuse the two scanners.
  - **An ID number outranks a name**, as on the upload form.
  - **Two people fitting one name is not a match.** Taking the first would file
    the document under a coin toss, and the error is silent afterwards.
  - Behind `EmployeePolicy::fileDocumentBatch`, a class-level ability for the
    same reason `viewArchive` is one: it is asked before any record is in hand.
    The scope is re-derived at the write, never trusted from the form.
## Record checks (AI & Analytics)

**Record Checks (`/hr/record-checks`)** asks the question the other two checkers
cannot: not what is *missing* (`OnboardingChecker`) or what is *lapsing*
(`CredentialExpiryScanner`), but **where the records disagree with each
other** — a number keyed against two people, a licence filed twice, a document
dated before the employee was born, a scan that named somebody the file does
not.

- **There is no model behind this screen, deliberately.** Every finding is a
  regex or a string comparison: a TIN with eleven digits is wrong for a reason
  that can be written down, and a rule that can be written down should not be
  inferred by something that might hallucinate it. The one non-trivial
  comparison — are these two names the same person — reuses
  `DocumentScanner::nameMatches()` rather than restating it, so this screen and
  the upload form cannot disagree about the same employee.
- `config/integrity.php` holds the agencies own lengths: SSS 10, PhilHealth 12,
  Pag-IBIG 12, TIN 9 or 12, licence a letter and ten digits. **Length and
  digits only** — none of the four publishes a checksum, and inventing a check
  digit rule would refuse real numbers to look clever.
- **A duplicate number is looked for across every record, not only the ones in
  scope.** A collision with somebody this user cannot see is still a collision,
  and hiding it would let the duplicate survive because of who happened to be
  looking. It is reported on *both* records: until somebody says which is
  right, both are wrong.
- **A missing number is not a finding here.** That is `OnboardingChecker` job,
  and saying it twice on two screens teaches people to ignore both.
- Only `single_copy_types` are checked for duplicates. Clearances and
  certificates accumulate legitimately — a fresh NBI clearance every year —
  and a superseded copy whose expiry has passed is a renewal with its history
  kept, which is correct.
- **`EmployeeFactory` issued three letters and eight digits** for a licence,
  which is not a shape the LTO produces, and this screen rightly flagged every
  seeded driver. The factory now issues `?##-##-######`. It made the same
  mistake twice over: its restriction codes were `1,2` and `3,8` from the
  retired numeric scheme, which against the real DL codes are simply codes LTO
  does not issue — so it now seeds real ones, and an expiry on the seeded
  employee's own birthday. Synthetic either way; the point is that it should
  look like what is really issued.

## Driver's licences (Module 1)

Modelled on a real card rather than on what the schema happened to hold. Two
things came out of that, and the second is the important one.

- **DL Codes replaced "restriction codes".** The record held one free-text box
  named after the retired numeric scheme (1–8), which a card issued today does
  not carry. A current licence prints two panels: **DL Codes**
  (A, A1, B, B1, B2, C, D, BE, CE — the vehicle classes) and **Conditions**
  (1–5). For a fleet operator the first is not paperwork: a DL code is the
  legal ceiling on what somebody may be put behind the wheel of, and putting a
  code-A holder on a truck is the same class of problem as dispatching a lapsed
  licence. `config/licenses.php` holds both lists in the agency's own wording.
- **Condition 4 reaches scheduling.** "Daylight driving only" means that driver
  cannot lawfully take a night run — which is neither a missing document nor a
  lapsed one, so nothing on Deployment Readiness would have said it and the
  dispatcher would have found out at the depot. `operational_conditions`
  surfaces it there as a **warning**, not a block: it rules out some runs, not
  the roster.
- **A licence expires on the holder's birthday**, which is checkable against
  the employee's own `birth_date` — a mismatch means one of the two was keyed
  wrong. Reported, never enforced: renewals around a birthday and extensions
  granted by memorandum are real enough that refusing the entry would reject
  correct records to catch wrong ones.
- The number is an agency code, a two-digit year, and a six-digit serial
  (`N02-24-001292`), stored without the dashes — which is why the integrity
  rule reads a letter and ten digits.

### Verifying one against LTO

**LTO publishes no API an employer can call, and this system does not pretend
it does.** LTMS is citizen-facing: a holder signs in to manage their own
licence. The commercial "LTO verification APIs" that advertise otherwise are
private wrappers whose data source the agency does not vouch for. Building
against one, or scraping the portal, would put a green tick on this screen that
nobody could account for.

So the claim is split in two, and the screen labels each half as what it is:

- **Structure** — `LicenseVerifier`, automatic and database-free like the other
  scanners. The number's shape, the DL codes, the conditions, the birthday
  rule. This catches a typo, a transposition, and a card filed under the
  retired scheme. **It cannot catch a well-made forgery and never claims to** —
  which is why the method is `isStructurallySound()` rather than `isValid()`.
- **Authenticity** — a person checks the LTMS portal and records what it said,
  against their name and the date. The note is **required and free text** on
  purpose: "active", "suspended until March", and "no record found" are three
  different answers, only one of them is good news, and a boolean loses two of
  them.

`verification_valid_days` marks a check **stale**, not expired. A licence can
be suspended the day after somebody looked at it, so the date says "nobody has
checked this in a year" — not "this licence is now invalid".

Findings appear on the employee's own screen and on Record Checks, which reuses
`LicenseVerifier` rather than restating the rules, the same reasoning that has
that screen reuse `DocumentScanner::nameMatches()`.

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

## The org directory (Module 1)

**Org Directory (`/hr/directory`) is a colleague's screen, not HR's record.**
It answers "who is in Operations, and how do I reach them" — a question
everybody has and that the employee directory beside it refuses to answer for
anybody but HR.

- **The widening and the narrowing are one decision.** `EmployeePolicy::viewDirectory`
  returns true for every signed-in user — a deliberate departure from
  `viewAny`, where a supervisor sees only their reports and an employee only
  themselves. That scoping is right for a screen carrying salary, government
  numbers, and the 201 file; it is useless for a directory, which is a list
  nobody can read if it holds one person. What makes the widening safe is that
  the *fields* narrow to match, in `DirectoryController::card()`: a name, an
  employee number, a position, a department, a client, and a work contact.
  Widening the audience without narrowing the fields would be a leak;
  narrowing the fields without widening the audience would be pointless.
- **`DirectoryController` deliberately does not call `EmployeeService::scopedQuery()`.**
  That service narrows by role because it feeds screens with sensitive fields.
  Applying it here would give every non-HR user a directory of themselves. The
  protection on this screen is the field list, not the row list — and
  `DirectoryTest` asserts it by searching the whole rendered payload for a
  salary, a TIN, an address, and a bank number.
- **Grouped department → position → person**, because that is the shape of the
  question: somebody looking for "a driver at Metro Fleet" is walking down the
  org chart, not searching a flat list of 41 names. The grouping is built
  server-side so the page does not re-derive it on every keystroke.
- **Somebody with no department is still listed.** A new hire filed before
  their department was decided is still a colleague to reach, and dropping
  them would make the directory quietly wrong rather than visibly incomplete.
- **Separated and inactive staff are not.** A directory is for reaching people
  who are here; somebody who has left is a record, which is what the archive
  and the employee screen are for.

## Master data (Module 1)

**Departments** and **Positions** are the org structure every employee record
is filed against, and every other module reads: KPI scoping, payroll grouping,
and the salary band Salaries & Adjustments checks a new rate against.

They used to be two tables stacked on one cramped Settings page. They are two
full-width screens under Employee Information now — HR maintains them while
filing people, not while configuring the system — and `/settings/organization`
redirects to `/hr/departments` rather than 404ing.

- Both reuse the **`manageOrganization` gate**, not a new permission: moving a
  screen does not change who is allowed to shape the org chart. Supervisors and
  employees get a 403, so the nav entries carry `roles` to match.
- **Anything in use is deactivated, never deleted** — a department with
  employees or positions under it, a position somebody holds. History has to
  keep the department and job title it was filed under. Only a genuinely unused
  row is removed, and the confirm dialog says which of the two will happen
  *before* the click.
- Search uses `scopeSearch` on both models with the **`ilike`/`like` driver
  switch**, the same shape as `Employee::scopeSearch` — a plain `like` matches
  case-sensitively on Postgres and would silently return nothing.
- The **summary tiles count the whole table, not the filtered view**. A summary
  that moves while you type is not a summary.
- Positions with no salary band are **counted, not flagged as an error**. A
  band is optional and advisory, but a rate keyed against a bandless position
  has nothing to be compared to, which is worth seeing.

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

Seven screens, one sidebar entry with `children`: **Daily Records** (DTR + CSV
import), **Overtime** (file / approve / reject), **Shifts & Schedules**,
**Holidays**, **Reports** (per-employee aggregation, CSV export),
**Exceptions**, and **History**. Only HR records or corrects time; approvers
are HR or the employee's own supervisor, never the requester.

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

**The employee files; HR or an admin decides.** One step, and nobody signs off
on their own leave — HR included.

- **Everybody files their own, HR included.** `LeaveRequestPolicy::create`
  requires an employee record rather than exempting `isHrAdmin()`, and
  `StoreLeaveRequestRequest` refuses an `employee_id` that is not the filer's.
  It used to work the other way: HR passed the policy on its own and the form
  offered a picker for whose leave to file. That made HR both the filer and the
  sole approver of the same request, which is the one thing the rest of this
  module is built to prevent — and it put a "File Leave" button on the screen
  HR opens to *decide* on other people's leave. An HR staff member who is on
  the roster still files their own here, like everybody else.
- **The supervisor endorsement is gone.** It was step one of two: the
  supervisor endorsed, then HR confirmed, and only that second step ever moved
  credits. The first signature therefore bought a delay rather than a decision,
  so `endorse` and `confirm` collapsed into one ability, `decide`, which only
  `isHrAdmin()` holds. Supervisors still **see** their reports' leave —
  `view` is untouched, and reading is not deciding.
- **`supervisor_approved` stays on the model, and it is not dead code.** Rows
  sitting in it when the rule changed are real requests somebody is waiting on,
  so `decide` accepts that status too and `OPEN_STATUSES` still reserves their
  credits. No new row ever enters it.
- `LeaveService::workingDays()` skips holidays and the employee's rest days, so a
  Friday-to-Monday request over a weekend costs two days, not four. It reads the
  schedule through `TimekeepingService` — Module 2 and 3 share one calendar.
- **Open requests reserve credits.** `availableCredits()` subtracts days on
  requests still awaiting a decision, so the same credit cannot be filed against
  twice before either is approved.
- Cancelling or rejecting an already-approved request hands the credits back.
- Unpaid types (`is_paid = false`) never touch the ledger.
- Attachments live on the private disk and download through
  `hr.leave.attachment` after a policy check, same as 201-file documents.
- **The topbar bell only lights for HR now.** It counts every request awaiting
  a decision. Supervisors used to be counted for their own reports' pending
  requests, which was right while endorsing was a step they took; with the
  decision HR's alone, a badge they cannot act on only teaches them to ignore
  the bell. Shared lazily from `HandleInertiaRequests`, so guests never run the
  query.

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

**Salaries & Adjustments — where the rate is set.** `basic_salary` used to be a
bare field on the employee form: HR typed over it, and the old rate, the date
it changed, and the reason were gone. The `salary_adjustments` history is now
the record, and `basic_salary` is a **cache of today's rate**. The two answer
different questions and must not be swapped:

- `basic_salary` — "what does this employee earn now?" (forms, directory)
- `SalaryAdjustmentService::rateAsOf()` — "what were they on over this
  period?" **Money reads this one.** `PayrollService::gatherInputs()` and
  `SeparationService` both call it, so a raise keyed in late cannot rewrite a
  period that closed before it took effect.
- `previous_salary` is **stored, not derived** from the preceding row: the row
  records a decision, and an audit needs the two figures that were on the paper
  that was signed.
- Back-dating takes its "from" figure from the rate in force *on the effective
  date*, not from the employee's current field.
- A **future-dated** adjustment does not move `basic_salary` until its date.
  `php artisan salaries:apply-due` recomputes the cache (safe to re-run, same
  shape as leave accrual). Money never depends on it — a missed run costs a
  stale figure on a form, never a wrong payslip.
- Deleting reads `previous_salary` **before** the delete and applies it
  directly when no history is left: with an empty history `rateAsOf()` falls
  back to `basic_salary`, which still holds the rate that adjustment set, so
  removing an employee's only adjustment would otherwise keep the raise.
- Position salary bands are shown and flagged, never enforced — HR pays outside
  a band deliberately often enough that refusing the entry would be wrong.
- Admin-only to delete: re-pointing someone's rate is a change to what they are
  paid, not a tidy-up. Supervisors are shut out entirely, matching
  `EmployeePolicy::viewSensitive`.

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

Seven sections under `/settings`, sharing `SettingsLayout` (section list on the
left). Company-wide sections are **admin-only**; Appearance and Security belong
to every signed-in user.

- Values live in a **key/value `settings` table**, namespaced (`company.name`),
  JSON-valued, read through one cached map. A new preference is a new key in
  `Setting::DEFAULTS`, not a migration.
- **Users & Access** is the only place besides the employee form where a login
  is created — an admin cannot demote or deactivate themselves, and
  deactivating revokes API tokens.
- **Organization moved out.** Departments and positions are master data under
  Employee Information now (see below); `/settings/organization` redirects to
  `/hr/departments` so old links still land.
- **Security** replaced the starter kit's `/profile`, which now redirects there.
  `email_verified_at` is guarded, so clearing it on an email change has to
  happen outside the mass-assignment payload.
- **Only an admin renames themselves.** `SettingPolicy::renameSelf` gates the
  Name field on Settings > Security; HR staff, supervisors, and employees see
  their name stated rather than editable. `users.name` and the employee record
  are meant to name the same person, and **nothing in this system reconciles
  them** — Record Checks compares scanned documents to employees, not logins to
  employees — so a drift is silent and permanent, which is a reason to prevent
  it rather than to detect it. An admin keeps the field because an admin need
  not be an employee at all: a pure system account has no 201 file to be held
  to, and locking it would leave a wrong name with nowhere to be fixed.
  - **Email and password are deliberately outside this.** They are credentials
    rather than a display name — what you sign in with, and where a reset is
    sent — and the Security screen exists so every signed-in user manages their
    own. Changing an address already forces re-verification.
  - The rule is enforced in `updateProfile`, not just hidden: `name` is dropped
    from the validation rules for anyone who may not set it, so a posted name
    is **ignored rather than refused**. Refusing would fail an email change over
    a field the person cannot see, and nothing wrong is stored either way.
- **Appearance** (theme, sidebar default) is per-device and lives in
  `localStorage`, not the database.

## Security

The access rules themselves are Module 1's (`scopedQuery()` + policies, salary
behind `viewSensitive`). What follows is the layer underneath them.

- **Authentication is Fortify's.** Signing in, signing out, and the password
  reset flow are `laravel/fortify` routes; the Inertia pages are pointed at
  them from `FortifyServiceProvider`. Its config is trimmed rather than left
  at defaults, and each trim is a rule this system already had:
  `Features::registration()` is **off** — installing Fortify reopened
  `/register`, which this system deliberately 404s, because HR provisions
  every login from the employee form; `fortify.home` points at `/dashboard`,
  since Fortify defaults to a `/home` that does not exist here; and
  **`limiters.login` is null**, which is not "unlimited". Naming a limiter
  makes Fortify apply the `throttle` middleware and skip
  `EnsureLoginIsNotThrottled`, and only that action fires Laravel's `Lockout`
  event — the one `RecordAuthenticationEvents` writes to the audit log. Same
  five attempts either way; nulling it buys the trail. Password confirmation
  moved to Fortify's `/user/confirm-password` for the same reason: it is not
  an optional feature, so the hand-written pair became unreachable duplicates.
- **`composer.json` pins `config.platform.php` to 8.2.12.** Without it,
  installing Fortify resolved Symfony 8.1 packages that require PHP >= 8.4.1,
  and `artisan` stopped booting at all. The pin makes Composer resolve for the
  PHP that actually runs here rather than the newest that satisfies the
  constraint graph.
- **The password floor is set once**, in `AppServiceProvider::definePasswordPolicy()`.
  Unconfigured, `Password::defaults()` means `min:8` and nothing else, and all
  four auth controllers plus the Security screen defer to it — so that one
  callback is the whole policy. `uncompromised()` (the Have I Been Pwned
  lookup) is production-only: in tests it would put the network in the path of
  every password assertion, and locally it fails open anyway.
- **Never generate a password with `Str::password()`.** Its pool contains every
  character class but guarantees none, so roughly one call in twenty produces
  something the policy above rejects — and the three places that hand a
  generated password to a person (the employee form, Users & Access create and
  reset) would be issuing credentials the account holder cannot keep. Use
  `User::generatePassword()`, which seeds one character per required class.
- **A password somebody else chose is temporary, and that is enforced.**
  `users.must_change_password` marks a login provisioned by a third party —
  the seeder, the employee form, a Users & Access create or reset. All four
  deliver the password through a channel that keeps a copy: a chat message, a
  spoken sentence, a console someone can scroll back through. So the password
  is known to two people from the moment it exists, and an instruction to
  change it is not a control. `RequirePasswordChange` is: it holds the account
  on `/settings/security` and lets through exactly three routes — that screen,
  the PUT that changes the password, and logout. Logout is there because
  trapping someone in a session they cannot leave is worse than the risk being
  managed, and signing out reduces exposure rather than adding to it.
  - The flag is cleared **only** by `SecurityController::updatePassword()`, so
    the hold is lifted by the act that removes the reason for it rather than by
    a "done" button reachable without changing anything. That same path also
    revokes the account's API tokens, since a token issued while the shared
    password was live was issued to whoever held it — rotating one and leaving
    the other is half a rotation.
  - **The API stack is deliberately outside this.** A Sanctum token is an
    unattended credential on a biometric device with nobody at the other end to
    type a new password; holding it would take the timeclock down rather than
    secure it.
  - It defaults to false, so deploying the migration does not lock out logins
    that already chose their own password.
- **Authentication is audited alongside model changes.** `Auditable` answers
  "who changed this record"; `RecordAuthenticationEvents` answers "who signed
  in, and who tried and failed", both into `audit_logs`. A failed attempt
  against an unknown address has no user row to point at, which is why
  `auditable_id` is nullable — that entry is the one worth keeping, since it is
  what somebody guessing at addresses looks like. The attempted address is
  recorded; the attempted password never is.
- **Reading personal data is audited, not only changing it.** `Auditable`
  answers "who changed this record" and `RecordAuthenticationEvents` answers
  "who signed in"; `DataAccessLogger` answers **"who read it"** — an
  `accessed` row for every 201-file document opened (`download` and `preview`
  recorded separately, because taking a copy away and reading it on screen are
  different acts) and an `exported` row for every CSV that leaves carrying
  many people. Every access gate in Modules 1, 2, and 4 was already correct;
  what was missing was the trace afterwards, which is the half of RA 10173
  accountability that "we gated it properly" does not answer. An export sets
  `auditable_type` and leaves `auditable_id` null — the same split a failed
  sign-in uses, since it is about many rows rather than one. The logger runs
  *after* the gate, so a refused request is never recorded as an access.
- **`/api/v1` is rate limited per token**, via `throttle:api` and
  `AppServiceProvider::defineApiRateLimit()`. Every endpoint there was gated
  and none was paced: an authorised token could walk the whole employee
  directory and its documents as fast as the server answered, and these are
  unattended credentials on biometric devices. Keyed off a hash of the bearer
  string rather than `$request->user()` — `ThrottleRequests` carries a
  middleware priority and `Authenticate` does not, so the limiter can be asked
  for its key before a user is resolved, and keying off a null user silently
  drops every token into one shared per-address bucket. Ceiling is
  `sanctum.rate_limit` (60/min), far above a device's real use.
- The Security screen's log **defaults to record changes, not everything**.
  Sign-ins vastly outnumber edits and the window is 50 rows, so an unfiltered
  view would push every change off the screen by mid-morning.
- **API tokens expire** — `config/sanctum.php` sets a year, where Sanctum's own
  default is never. These are unattended machine credentials on biometric
  devices; an expiry short enough to be inconvenient is one that gets worked
  around by never rotating. Sanctum measures from `created_at`, so the setting
  reaches tokens already issued.
- **Ten minutes of nobody being there signs the session out.**
  `config('session.lifetime')` is the whole enforcement — Laravel refreshes
  `last_activity` on every request, so it is a true idle window rather than a
  fixed expiry, and it holds whether or not any JavaScript is running. Short on
  purpose: this is an HRIS on office machines that get walked away from, and
  what sits on the screen is salary, government numbers, and 201 files — the
  same data `EmployeePolicy::viewSensitive` guards on the way in, left visible
  to whoever sits down next.
  - **`IdleTimeout.jsx` is the courtesy half, not the rule.** It warns 60
    seconds out and signs out through `POST /logout/idle`, which lands on the
    login screen *saying why*. An unexplained login screen reads as a crash,
    and that is the reaction that gets a timeout switched off.
  - **It compares timestamps; it never counts down.** A laptop closed at 4pm
    and opened at 9am has an interval that simply did not fire, and a
    decrementing counter would wake believing no time had passed — on exactly
    the machine somebody else has since sat down at.
  - **Activity is shared across tabs through `localStorage`.** Typing in one
    tab is being present at the computer; without sharing it, a second tab
    signs the person out mid-sentence in the first.
  - **Being active is not the same as talking to the server**, and that gap is
    what `GET /session/keepalive` closes. Reading a long payslip is activity to
    the person and silence to Laravel, whose session would expire underneath a
    countdown that still looked healthy — so real activity refreshes the server
    once the last request is older than half the window. At most one request
    every five minutes for somebody actually working.
  - `mousemove` is deliberately **not** an activity event. A trackpad brushed
    by a sleeve is not somebody at the desk, and counting it is how an idle
    timeout quietly stops timing out.
  - **The number is shared, never restated.** `HandleInertiaRequests` publishes
    `idle.timeout` from the same config the server expires on. Two copies would
    drift the first time one was tuned, and silently in the worst direction: a
    screen counting down from ten against a session that died at five.
  - **A 419 now lands on the login screen too.** The timeout cannot cover the
    case it exists for — a tab whose session Laravel expired with no browser
    running — and the first click after that used to open Inertia's black
    overlay containing "Page Expired", which is a dead end with nothing on it
    saying to sign in again. `router.on('invalid')` in `app.jsx` catches it.
  - **API tokens are outside this**, like `RequirePasswordChange`:
    `session.lifetime` only reaches session auth, and an unattended biometric
    device has nobody at the other end to sign in again.
- `SecurityHeaders` is **deliberately not a full CSP.** A real `script-src`
  needs a nonce threaded through the Vite tags and the Inertia root, and a
  half-written one breaks the payslip print view. The three directives it does
  set — `frame-ancestors`, `object-src`, `base-uri` — cannot break a script or
  a stylesheet. HSTS is sent only when `$request->secure()`: over plain
  `http://core2.test` it would pin the dev host to TLS it does not serve.

**Before deploying**, `APP_DEBUG` must be `false` (a stack trace prints the
database password), `SESSION_SECURE_COOKIE` true, and `SESSION_ENCRYPT`
considered — session payloads are plaintext in the `sessions` table today.

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
- **Infinite scroll needs `preserveUrl`.** The Employee Directory loads more
  rows with `<WhenVisible>` + `Inertia::merge(...)->append('data', 'id')`
  instead of numbered pages — merge is server-driven and only applies on a
  *partial* reload, so a full visit (first load, or a filter/sort change)
  renders fresh instead of showing a stitched-together mix of two result sets.
  `WhenVisible`'s `params` must carry `preserveUrl: true`, or every
  scroll-triggered fetch pushes `?page=2`, `?page=3`… onto the URL and browser
  history: Back needs one press per page loaded, and refreshing mid-scroll
  re-renders as a full visit showing only that lone page instead of everything
  loaded so far.
- **File uploads over PUT.** Browsers can't send multipart on PUT — put
  `_method: 'put'` in the `useForm` data and `post()` with `forceFormData: true`.
- **Documents are on the private disk.** Never link to `/storage/...` for a 201
  file; they're streamed through `hr.employees.documents.download` (forces a
  save) or `…documents.preview` (serves `inline` for the in-app viewer) after
  the same `view` policy check — two routes rather than one with a flag, so
  neither can change the other by accident. `EmployeeDocumentResource` decides
  `preview_as` (`image` / `pdf` / `text` / `null`) from the **stored mime type**,
  not the filename, and a `null` offers download only — a viewer that renders a
  blank frame for a `.docx` is worse than no viewer. Employee *photos* stay
  public so avatars don't cost a PHP request per row.
- **A soft-deleted employee is hidden from `belongsTo` but not from a `join`.**
  The payroll run screen joins `employees` to sort by surname, and a join
  ignores the soft-delete scope — so an archived employee's payslip stayed in
  the list while `$payslip->employee` came back null. Three screens fataled on
  it and the SSS R-3 exported a line carrying money with no person attached,
  which is worse than omitting them: the filing looks complete and cannot be
  reconciled. `Payslip::employee()` and `PerformanceReview::employee()` are
  therefore declared `->withTrashed()`: a financial or appraisal record may
  never forget whose it is, and keeping archived people *out of a list* is the
  job of the query that builds the list, not of the record's own memory.
  Guarded by `ArchivedEmployeeHistoryTest`.
- **Archiving a supervisor orphans their reports.** `EmployeeService::delete()`
  deactivates the login but leaves `supervisor_id` pointing at the archived
  row, so those employees' leave has nobody who can endorse it. Departments and
  positions refuse deletion while in use; an employee who supervises somebody
  does not, and that inconsistency is unresolved.
- **Tests that read a default date range must pin "now".** The exceptions
  screen defaults to the current month, so a test filing attendance at
  `now()->subDay()` and reading with no filter passes for 27 days and fails on
  the 1st. `AttendanceExceptionTest` travels to mid-month in `setUp()` for
  exactly this reason. The same trap caught a hard-coded `2026-08-01`
  assertion in `SecurityTest`, which had been passing by coincidence.
- **`ilike` is Postgres-only.** Tests run on SQLite — pick the operator from
  `getDriverName()`, as `Employee::scopeSearch` does.
- **Factory sequences.** Batch `create()` runs every `definition()` before the
  first insert, so a DB-derived counter hands out duplicates. `EmployeeFactory`
  counts in memory instead.
- **A name used but never imported fails only in the browser**, and takes
  more with it than you expect. `npm run check` runs
  `scripts/check-imports.mjs` for exactly this. Two have shipped: a lucide icon
  used without its import blanked one screen, and a `roles: [ROLE.ADMIN]`
  written into `navigation.js` — where no such constant exists — blanked
  **every** screen, because every page imports that config and a
  ReferenceError at module load takes the whole bundle down. The build is
  silent on both. The checker looks at three shapes — `<Component>` usage,
  bare `CONSTANT.property` reads, and plain `helper()` calls — each added
  after the previous version let one through. The third was a `withFilters()`
  used in a page that never imported it. It strips comments and string
  literals before scanning, because `{count} day(s) of leave` is JSX prose
  that reads to a regex exactly like a call.
- **Lucide icon names don't fail the build.** A misspelled icon imports as
  `undefined` and only blows up at render. Vite will not warn you.
- **A listener method named `handle*` in `app/Listeners` registers itself.**
  Laravel discovers listeners there by scanning for that prefix and reading the
  type hint — so a class that is *also* registered explicitly fires twice, and
  writes two audit rows for one sign-in. `RecordAuthenticationEvents` names its
  methods `record*` for exactly this reason. Nothing errors; the rows simply
  double, which is why it is caught by a counting test rather than by the suite
  going red on its own.
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

Seed accounts (password `password` **on a local machine only** — see below):
`admin@primepower.test`, `hr@primepower.test`, and `employee@primepower.test` —
a rank-and-file login with a supervisor above it, so the self-service half (own
payslip, own leave, own 201 file) and the approval routing can both be
exercised. The supervisor accounts are the seeded department heads; their emails
are Faker-generated, so read one out of the `users` table.

**`password` is local-only, and the seeder enforces that rather than trusting
it.** The fixed password is the whole point of a seed account on a development
machine, and it is indefensible anywhere else — this is coursework in a
repository people read, so a seeded deployment would be publishing its own
administrator account. `DatabaseSeeder::seededPassword()` returns `password`
under `local` and `testing` and generates a distinct one per login otherwise,
printed once to the console by `reportIssuedPasswords()` and nowhere else. It
cannot be recovered afterwards: it is hashed on the way in.

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

**Core 1's half of the handover is not in this repository.** This system
receives endorsements, decides on them, and publishes the outcome back; what
Core 1 has to build is one POST and one GET against `/api/v1/endorsements`
(above). Until it does, `php artisan endorsements:simulate` files one through
the same service the API calls, so the receiving half stands on its own. There
is no push *to* Core 1 when a decision is taken — they poll for it, which is
the right default for two systems that cannot assume each other is up, and the
upgrade is a webhook on the `decided_at` write.
