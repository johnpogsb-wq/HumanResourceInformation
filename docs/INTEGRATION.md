# Core Transaction 2 — Integration Guide

**PrimePower Manpower HRIS.** This is what Core 2 publishes to the rest of
ISMERS, and the two doors it accepts writes through.

Base URL: `https://<host>/api/v1`
Auth: `Authorization: Bearer <token>` (Laravel Sanctum)

---

## What this system owns

Core 2 is the record of **people, time, leave, and pay**. Nothing here should
be copied into another system: a headcount kept in two places disagrees within
a month, and the disagreement surfaces on a remittance or a dispatch sheet
rather than on a screen somebody is watching.

Every figure below is computed by the same service that renders our own
screens. If a consumer and one of our screens ever disagree about the same
driver, one of them is running its own copy of the rules.

---

## Getting a token

Each consuming team gets its own token. Ask Core 2's admin to issue one from
**Settings → Security → API Tokens**, or exchange credentials:

```http
POST /api/v1/login
Content-Type: application/json

{ "email": "integration.core3@primepower.test", "password": "…" }
```

```json
{ "token": "12|abc…", "user": { "id": 4, "role": "hr_staff" } }
```

**The token's role decides what it may see.** Salary, bank details, and
government numbers are behind `viewSensitive` — a token issued from an
`employee` account will get `403` on payroll. Ask for the role your
integration actually needs and no more.

Tokens expire after **one year** (`config/sanctum.php`) and are rate limited to
**60 requests/minute** per token.

---

## Core 1 — Client Acquisition, Recruitment & Deployment

### Sending us a hire

**PrimePower does not hire into Core 2 directly.** You recruit; we employ. Post
the candidate and somebody here approves or declines it — so the act of putting
a person on the payroll has a decision, a decider, and a date attached.

```http
POST /api/v1/endorsements
```

```json
{
  "reference": "C1-2026-ABC123",
  "first_name": "Maria",
  "last_name": "Santos",
  "email": "maria.santos@example.com",
  "mobile_number": "09171234567",
  "birth_date": "1995-04-12",
  "position_title": "Driver",
  "client_name": "Metro Fleet Logistics",
  "date_hired": "2026-10-01",
  "remarks": "Cleared final interview."
}
```

| | |
|---|---|
| **`201`** | filed, waiting on a decision |
| **`200`** | we already have this `reference` — the existing row comes back |

**Only `reference`, `first_name`, and `last_name` are required.** Everything
else is optional, down to the email. Refusing to *receive* somebody is the one
outcome this queue exists to avoid: a recruitment system that cannot hand a
candidate over because it does not know our pay frequency has not been
integrated with, it has been locked out.

**`reference` is your identifier, and it is what makes a retry safe.** A
timeout on your side is indistinguishable from a failure, so resend — two rows
for one person would be two employee numbers and one person paid twice.

**What you may not send.** `basic_salary`, `client_id`, `department_id`,
`position_id`, `employment_category`, `employment_status`, and `pay_frequency`
are dropped from the payload. Those decide what PrimePower pays and who it
bills; they are set here, by a person, at approval.

### Reading the outcome

```http
GET /api/v1/endorsements/{reference}
```

```json
{
  "data": {
    "reference": "C1-2026-ABC123",
    "status": "approved",
    "decision_note": null,
    "decided_at": "2026-09-12T09:14:00+08:00",
    "employee": { "id": 42, "employee_number": "PPM-2026-0042" }
  }
}
```

`status` is `pending`, `approved`, or `rejected`. A rejection always carries a
`decision_note` — you need to know what would have to change.

**Poll this; we do not push.** Two systems that cannot assume each other is up
should not depend on a webhook. If you need one later, it hangs off the
`decided_at` write.

### Who can be deployed

```http
GET /api/v1/deployment-readiness?status=ready&client_id=4
```

Answers "can this person be sent to a client tomorrow?" — which no single
module can, because it needs credentials, 201-file completeness, and employment
standing at once.

```json
{
  "data": [
    {
      "employee_id": 15,
      "employee_number": "PPM-2026-0015",
      "employee_name": "Juan Dela Cruz",
      "position": "Driver",
      "client": "Metro Fleet Logistics",
      "status": "blocked",
      "blocking_count": 1,
      "reasons": [
        { "blocking": true, "detail": "Driver's licence expired 12 days ago." }
      ]
    }
  ],
  "meta": { "total": 41, "ready": 22, "warning": 9, "blocked": 10 }
}
```

**`blocked` is not a louder warning.** A driver whose licence has lapsed may
not lawfully drive, so it is the one status here that means *this would be
wrong* rather than *somebody should look*.

---

## Fleet & Transportation Management

```http
GET /api/v1/drivers?available=1&client_id=4
```

Who may lawfully be put behind the wheel, and of what.

```json
{
  "data": [
    {
      "employee_id": 15,
      "employee_number": "PPM-2026-0015",
      "full_name": "Juan Dela Cruz",
      "client": "Metro Fleet Logistics",
      "licence": {
        "number": "N02-24-001292",
        "expires_at": "2028-11-29",
        "dl_codes": [
          { "code": "B", "label": "Up to 5000 kgs GVW / 8 seats" },
          { "code": "C", "label": "Goods over 3500 kgs GVW" }
        ],
        "conditions": [{ "code": "4", "label": "Daylight driving only" }],
        "is_expired": false,
        "expires_within_days": 815,
        "structurally_sound": true,
        "ltms_check": "verified",
        "ltms_checked_at": "2026-08-14"
      },
      "operational_restrictions": ["Daylight driving only"],
      "may_drive": true,
      "warning_window_days": 60
    }
  ]
}
```

**Three things decide a dispatch, and none is on an employee record:**

- **`dl_codes`** are the legal ceiling on the vehicle class. A code-A holder on
  a truck is the same class of problem as a lapsed licence.
- **`operational_restrictions`** rule out some runs. Condition 4 means that
  driver **cannot take a night run** — not a missing document, not a lapsed
  one, so nothing else would have told you.
- **`may_drive`** is the single field a dispatch screen should key on. `false`
  means assigning them would be unlawful.

**`structurally_sound` is not "valid".** It means the card is internally
consistent — the number's shape, the codes, the birthday rule. It cannot catch
a well-made forgery and never claims to. `ltms_check` is a *person's* answer
from the LTO portal, recorded with their name and the date, because **LTO
publishes no API an employer can call.**

Filter by `client_id` so a site dispatcher sees only their own, and
`available=1` for the ones who may drive today.

No salary, bank details, or government numbers are returned on this endpoint —
Fleet has no reason to hold them.

---

## Core 3 — Employee Development, Compliance & Benefits

### Government contributions (what to remit)

```http
GET /api/v1/payroll/runs/{run}/contributions
```

```json
{
  "data": [
    {
      "employee_id": 1,
      "employee_number": "PPM-2026-0001",
      "full_name": "Breana N. Dicki",
      "numbers": {
        "sss": "49-5872667-2",
        "philhealth": "07-849963398-4",
        "pagibig": "9164-1093-9556",
        "tin": "481-946-366-987"
      },
      "employee_share": {
        "sss": 875, "philhealth": 812.5, "pagibig": 100, "withholding_tax": 3671.89
      },
      "employer_share": { "sss": 1750, "philhealth": 812.5, "pagibig": 100 }
    }
  ],
  "meta": {
    "run_number": "PR-2026-0001",
    "employee_count": 41,
    "missing_numbers": ["PPM-2026-0038"]
  }
}
```

**These figures are read back from stored payslips, never recomputed.** A new
SSS circular would otherwise silently rewrite what was already remitted.

An employee missing a government number is **included with a null and listed in
`missing_numbers`**, deliberately — the filing cannot cover them until it is on
their 201 file, and dropping them would hide that from the system whose job it
is to notice.

Returns **`409`** if the run is still a draft. Only approved and paid runs are
reportable; a draft is still being corrected.

### Posting a loan for payroll to deduct

You approve the loan and answer to the employee for it. **Only Core 2 can take
money off a payslip**, so post it here.

```http
POST /api/v1/loans
```

```json
{
  "reference_number": "C3-LOAN-0001",
  "employee_id": 42,
  "type": "sss",
  "principal_amount": 24000,
  "monthly_amortization": 2000,
  "start_date": "2026-09-01"
}
```

`type` is one of `sss`, `pagibig`, `company`, `salary_advance`.
`monthly_amortization` may not exceed `principal_amount` — an amortisation
larger than the loan is a keying error that would otherwise surface one period
later, on somebody's pay.

Idempotent on `reference_number`: **`201`** the first time, **`200`** on a
resend. Two rows for one loan is the employee paying it twice.

```http
GET /api/v1/loans/{reference_number}
GET /api/v1/employees/{employee}/loans
```

**`amortised_on_payroll_approval` is always `true`, and it matters.** A loan
only moves when a payroll run is *approved*. A balance read mid-cycle is the
balance **before** this period's deduction — do not show it to an employee as
their current figure.

> Do **not** keep your own balance and tell us what to deduct each period. The
> first time a run is recomputed the deduction would apply twice and the two
> balances would part company with nobody watching.

---

## Financial Management System (Transaction Core)

### Runs available for disbursement

```http
GET /api/v1/payroll/runs?from=2026-08-01&to=2026-09-30
```

```json
{
  "data": [
    {
      "id": 1,
      "run_number": "PR-2026-0001",
      "status": "paid",
      "period": {
        "name": "Aug 21 – Sep 4, 2026",
        "start_date": "2026-08-21",
        "end_date": "2026-09-04",
        "pay_date": "2026-09-09"
      },
      "employee_count": 41,
      "total_gross": 940991.51,
      "total_deductions": 178091.89,
      "total_net": 762899.62,
      "approved_at": "2026-09-05T07:55:06+08:00"
    }
  ]
}
```

**Only approved and paid runs are listed.** A draft is still being corrected —
disbursing against one would be paying a figure this system has not agreed to
yet.

### The register behind one run

```http
GET /api/v1/payroll/runs/{run}/register
```

```json
{
  "data": [
    {
      "payslip_number": "PS-2026-0001",
      "employee_id": 1,
      "employee_number": "PPM-2026-0001",
      "full_name": "Breana N. Dicki",
      "gross_pay": 22951.01,
      "deductions_total": 5459.39,
      "net_pay": 17491.62,
      "bank_name": "BDO",
      "bank_account_number": "0012 3456 7890"
    }
  ],
  "meta": {
    "run_number": "PR-2026-0001",
    "employee_count": 41,
    "total_net": 762899.62,
    "control_total": 762899.62
  }
}
```

**Check `control_total` against your own sum of `net_pay`.** If they disagree,
stop — do not disburse a file you cannot reconcile.

`bank_name` and `bank_account_number` are **absent, not null**, for a token
that may not see them. Returns **`409`** on a draft run.

---

## Core 4 (Reports & Dashboards) and Business Intelligence

```http
GET /api/v1/analytics/workforce?from=2026-09-01&to=2026-09-30
```

```json
{
  "data": {
    "headcount": { "total": 41, "active": 38, "on_leave": 3, "probationary": 6 },
    "by_category": { "internal": 12, "external": 29 },
    "by_client": { "Metro Fleet Logistics": 14, "Visayas Island Transport": 15 },
    "attendance": {
      "records": 1886, "present": 1230, "absent": 41, "late": 327,
      "total_hours": 10255.26, "overtime_hours": 602.13
    },
    "leave": { "total": 24, "pending": 9, "approved": 6, "approved_days": 18 }
  },
  "meta": { "from": "2026-09-01", "to": "2026-09-30", "scope": "organisation" }
}
```

**Aggregates only — no names, no salaries, no government numbers.** A dashboard
needs shapes, not people, and an endpoint that hands over the directory to draw
a bar chart is the endpoint that will one day be the way the directory left.

`meta.scope` says whether the caller is seeing the whole organisation or only
what their role narrows to.

If you need per-person figures, use `GET /api/v1/employees` with a token whose
role permits it — and say why in your integration notes.

---

## Also available

| Endpoint | For |
|---|---|
| `GET /api/v1/employees` | the directory, paginated and filterable |
| `GET /api/v1/employees/{id}` | one record |
| `GET /api/v1/employees/statistics` | headcount tiles |
| `GET /api/v1/employees/{id}/documents` | 201-file index (metadata only) |
| `GET /api/v1/attendance` | DTR rows |
| `GET /api/v1/attendance/summary` | aggregated attendance |
| `POST /api/v1/attendance` | biometric devices post punches here |

---

## Conventions

**Errors.** `401` no or bad token · `403` the token's role may not see this ·
`404` no such record · `409` the record exists but is not in a state that can
answer (a draft payroll run) · `422` validation, with a `errors` object keyed by
field · `429` rate limited.

**Dates** are `YYYY-MM-DD`. **Timestamps** are ISO 8601 with the offset
(`+08:00`) — the system runs on `Asia/Manila`.

**Money** is a number, two decimals, in PHP. Never a formatted string.

**Every list response carries `data`.** Aggregates and totals ride in `meta`.

**Retries.** The two write endpoints are idempotent on a reference you supply.
Everything else is a `GET` and safe to repeat.

---

## Questions worth asking us before you build

- **Do you need names, or just counts?** If counts, use
  `/analytics/workforce` — it is cheaper for both of us and carries no
  personal data.
- **What role should your token have?** Ask for the least that works. It is
  easier to widen later than to explain a leak.
- **Are you about to store a copy of something here?** Say so first. Almost
  every field on these endpoints changes, and a stale copy is worse than a
  round trip.

Contact: the Core 2 team.
