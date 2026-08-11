# PrimePower Manpower — HRIS

A Human Resource Information System for a fleet & transportation manpower
company, covering the full employee lifecycle: **Employee Information,
Timekeeping & Attendance, Leave & Absence, Payroll & Compensation, and
Performance Management.**

## Stack

- **Laravel 12** — application framework
- **Inertia 2 + React 18** — the UI. Inertia renders pages *and* a
  token-authenticated REST API lives at `/api/v1`; both call the same Service
  layer so behaviour can't drift between them
- **Tailwind 3** — every colour is a semantic design token, so light and dark
  mode both work without per-component overrides
- **PostgreSQL** — the application database

## Architecture

```
Request ─┬─ Http/Controllers/EmployeeController      (Inertia -> Pages/…)
         └─ Http/Controllers/Api/EmployeeController  (JSON  -> Resources)
                              │
                       Services/EmployeeService      ← all business logic
                              │
                          Models/…                   ← Auditable, scopes
```

Controllers stay thin — authorize, delegate, respond. Business rules that
need to be unit-tested in isolation (attendance calculation, statutory
contributions, payroll arithmetic, performance scoring, DTR anomaly
detection) live in dedicated, database-free calculator/scanner classes.
Policy-tunable values (statutory rates, exception thresholds, credential
renewal windows) live in `config/`, not in code.

## Roles

Four roles, enforced in two places that must agree — `scopedQuery()` narrows
what a *list* returns, and each model's Policy guards an *individual* record:

| Role | Sees |
|---|---|
| `admin` | Every record; only admin may archive or approve payroll |
| `hr_staff` | Every record; provisions logins, processes payroll (never approves their own run) |
| `supervisor` | Own record + direct reports; endorses leave and overtime |
| `employee` | Own record only — self-service |

Self-registration is disabled by design; HR provisions logins from the
employee form.

## Getting started

Requires PHP 8.2+, Composer, Node 18+, and PostgreSQL.

```bash
git clone <this-repo>
cd Core2

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Edit `.env` — switch `DB_CONNECTION` to `pgsql` and fill in real credentials
(a fresh clone defaults to SQLite so it boots with zero setup, but this
project is built and tested against Postgres):

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=primepower_hris
DB_USERNAME=postgres
DB_PASSWORD=
```

```bash
php artisan migrate --seed
npm run build
php artisan serve
```

Seed accounts (password `password`): `admin@primepower.test`,
`hr@primepower.test`.

## Testing

```bash
php artisan test          # SQLite, in-memory — fast, runs anywhere
composer test:pgsql       # same suite against real Postgres
```

`composer test:pgsql` catches driver-specific bugs (raw SQL, operators like
`ILIKE`) that an in-memory SQLite run can't see. It targets a
`primepower_hris_test` database using the same host/credentials as `.env`.

## Formatting

```bash
vendor/bin/pint        # PHP
npm run format          # JS/JSX
npm run check           # everything
```

## Known gaps

13th-month pay and final-pay computation; peer and subordinate performance
reviews (schema and scoring support them, but rollout only creates self and
supervisor evaluations); a holidays management screen (2026 holidays are
seeded, no UI yet); leave credit accrual on a schedule (currently allocated in
bulk per year); email notifications (in-app only, via the topbar bell and
credential-expiry indicator).
