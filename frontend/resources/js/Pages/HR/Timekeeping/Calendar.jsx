import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlarmClock,
    CalendarX,
    ChevronLeft,
    ChevronRight,
    Clock,
    LogIn,
    LogOut,
    Mail,
    Send,
    UserCheck,
} from 'lucide-react';

import AppLayout from '@/Layouts/AppLayout';
import { Badge, Button, Card, Select, StatCard } from '@/Components/ui';
import { cn, withFilters } from '@/lib/utils';
import { minutes, TIMEKEEPING_CRUMBS } from './Partials/shared';

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** How a recorded day reads: tone is valence, the same language as Daily Time Records. */
const STATUS = {
    present: { label: 'Present', variant: 'success', dot: 'bg-success' },
    late: { label: 'Late', variant: 'warning', dot: 'bg-warning' },
    undertime: { label: 'Undertime', variant: 'warning', dot: 'bg-warning' },
    absent: { label: 'Absent', variant: 'destructive', dot: 'bg-destructive' },
    incomplete: { label: 'No time-out', variant: 'destructive', dot: 'bg-destructive' },
    on_leave: { label: 'On leave', variant: 'primary', dot: 'bg-primary' },
    holiday: { label: 'Holiday', variant: 'primary', dot: 'bg-primary' },
    rest_day: { label: 'Rest day', variant: 'muted', dot: 'bg-muted-foreground/40' },
};

const longDate = (date) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('en-PH', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });

/** The day to open on: today when it is in view, else the latest recorded day. */
function initialDay(calendar, today) {
    if (!calendar) return null;

    const days = calendar.days;

    return (
        days.find((day) => day.date === today)?.date ??
        [...days].reverse().find((day) => day.status)?.date ??
        days[0]?.date ??
        null
    );
}

export default function Calendar({ employees, employee, calendar, month, today, email, can }) {
    const [selected, setSelected] = useState(() => initialDay(calendar, today));
    const [sending, setSending] = useState(null);

    const go = (changes) =>
        router.get(
            '/hr/timekeeping/calendar',
            { employee: employee?.id, month, ...changes },
            { preserveScroll: true, replace: true },
        );

    const send = (url, data, question) => {
        if (!window.confirm(question)) return;

        router.post(url, data, {
            preserveScroll: true,
            onStart: () => setSending(url),
            onFinish: () => setSending(null),
        });
    };

    const day = calendar?.days.find((entry) => entry.date === selected) ?? null;
    const range = calendar && {
        employee: employee.id,
        from: `${calendar.month}-01`,
        to: calendar.days[calendar.days.length - 1].date,
    };

    return (
        <AppLayout
            title="Attendance Calendar"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Attendance Calendar' }]}
        >
            <Card className="mb-5">
                <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
                    <div className="flex items-center gap-1">
                        <button
                            type="button"
                            onClick={() => go({ month: calendar?.previous })}
                            disabled={!calendar}
                            aria-label="Previous month"
                            className="grid h-9 w-9 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </button>
                        <h2 className="min-w-36 text-center text-sm font-semibold text-foreground">
                            {calendar?.label ?? month}
                        </h2>
                        <button
                            type="button"
                            onClick={() => go({ month: calendar?.next })}
                            disabled={!calendar}
                            aria-label="Next month"
                            className="grid h-9 w-9 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                        >
                            <ChevronRight className="h-4 w-4" />
                        </button>
                    </div>

                    {employees.length > 1 && (
                        <div className="w-full lg:w-72">
                            <Select
                                value={employee?.id ?? ''}
                                onChange={(event) => go({ employee: event.target.value })}
                                options={employees}
                                aria-label="Employee"
                            />
                        </div>
                    )}

                    {can.email && employee && (
                        <div className="flex flex-col gap-2 sm:flex-row lg:ml-auto">
                            <Button
                                variant="outline"
                                loading={sending === '/hr/timekeeping/calendar/email'}
                                disabled={!email?.deliverable || sending !== null}
                                title={
                                    email?.deliverable
                                        ? `Send to ${email.recipient}`
                                        : 'No real email on this employee’s record'
                                }
                                onClick={() =>
                                    send(
                                        '/hr/timekeeping/calendar/email',
                                        { employee_id: employee.id, month },
                                        `Email ${employee.name}'s ${calendar.label} attendance to ${email.recipient}?`,
                                    )
                                }
                            >
                                <Mail className="h-4 w-4" />
                                Email to employee
                            </Button>
                            <Button
                                loading={sending === '/hr/timekeeping/calendar/email-all'}
                                disabled={sending !== null}
                                onClick={() =>
                                    send(
                                        '/hr/timekeeping/calendar/email-all',
                                        { month },
                                        `Email ${calendar.label} attendance to every employee with a time record that month? Each one receives only their own.`,
                                    )
                                }
                            >
                                <Send className="h-4 w-4" />
                                Email everyone
                            </Button>
                        </div>
                    )}
                </div>

                {employee && (
                    <div className="flex items-center gap-3 border-t border-border/60 px-4 py-3">
                        <div className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                            {employee.name
                                .split(' ')
                                .filter(Boolean)
                                .slice(0, 2)
                                .map((part) => part[0])
                                .join('')}
                        </div>
                        <div className="min-w-0">
                            <p className="truncate text-sm font-semibold text-foreground">
                                {employee.name}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {[
                                    employee.number,
                                    employee.position,
                                    employee.client ?? employee.department,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </div>
                        {can.email && (
                            <p
                                className={cn(
                                    'ml-auto hidden text-xs sm:block',
                                    email?.deliverable
                                        ? 'text-muted-foreground'
                                        : 'text-warning',
                                )}
                            >
                                {email?.deliverable
                                    ? `Emails go to ${email.recipient}`
                                    : email?.recipient
                                      ? `${email.recipient} cannot receive mail`
                                      : 'No email on file'}
                            </p>
                        )}
                    </div>
                )}
            </Card>

            {!calendar ? (
                <Card className="p-10 text-center text-sm text-muted-foreground">
                    There is nobody whose attendance you can view.
                </Card>
            ) : (
                <>
                    <div className="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="Days worked"
                            value={calendar.summary.worked}
                            icon={UserCheck}
                            tone={calendar.summary.worked > 0 ? 'success' : 'muted'}
                        />
                        <StatCard
                            label="Late days"
                            value={calendar.summary.late}
                            icon={AlarmClock}
                            tone={calendar.summary.late > 0 ? 'warning' : 'muted'}
                            hint={
                                calendar.summary.late_minutes > 0
                                    ? `${minutes(calendar.summary.late_minutes)} in total`
                                    : undefined
                            }
                            href={withFilters('/hr/timekeeping', range, { status: 'late' })}
                        />
                        <StatCard
                            label="Absences"
                            value={calendar.summary.absent}
                            icon={CalendarX}
                            tone={calendar.summary.absent > 0 ? 'destructive' : 'muted'}
                            href={withFilters('/hr/timekeeping', range, { status: 'absent' })}
                        />
                        <StatCard
                            label="Hours worked"
                            value={calendar.summary.hours}
                            icon={Clock}
                            tone={calendar.summary.hours > 0 ? 'primary' : 'muted'}
                        />
                    </div>

                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                        <Card className="p-3 sm:p-4">
                            <div className="grid grid-cols-7 gap-1 text-center">
                                {WEEKDAYS.map((weekday) => (
                                    <div
                                        key={weekday}
                                        className="pb-2 text-xs font-medium text-muted-foreground"
                                    >
                                        {weekday}
                                    </div>
                                ))}

                                {Array.from({ length: calendar.leading_blanks }, (_, index) => (
                                    <div key={`blank-${index}`} />
                                ))}

                                {calendar.days.map((entry) => (
                                    <DayCell
                                        key={entry.date}
                                        day={entry}
                                        isToday={entry.date === today}
                                        isSelected={entry.date === selected}
                                        onSelect={() => setSelected(entry.date)}
                                    />
                                ))}
                            </div>

                            <div className="mt-4 flex flex-wrap gap-x-4 gap-y-1.5 border-t border-border/60 pt-3 text-xs text-muted-foreground">
                                {['present', 'late', 'absent', 'on_leave', 'rest_day'].map(
                                    (key) => (
                                        <span
                                            key={key}
                                            className="inline-flex items-center gap-1.5"
                                        >
                                            <span
                                                className={cn(
                                                    'h-2 w-2 rounded-full',
                                                    STATUS[key].dot,
                                                )}
                                            />
                                            {STATUS[key].label}
                                        </span>
                                    ),
                                )}
                                <span className="inline-flex items-center gap-1.5">
                                    <span className="h-2 w-2 rounded-full border border-muted-foreground/60" />
                                    No record
                                </span>
                            </div>
                        </Card>

                        <DayDetail day={day} canFileCorrection={can.fileCorrection} />
                    </div>
                </>
            )}
        </AppLayout>
    );
}

function DayCell({ day, isToday, isSelected, onSelect }) {
    const status = day.status ? STATUS[day.status] : null;
    // A past working day with no row is "no record", never "absent": absence
    // is something somebody recorded, and the calendar does not invent one.
    const missing = !day.status && !day.future && !day.rest_day && !day.holiday;

    return (
        <button
            type="button"
            onClick={onSelect}
            aria-pressed={isSelected}
            aria-label={`${longDate(day.date)}${status ? `, ${status.label}` : ''}`}
            className="flex min-h-14 flex-col items-center gap-1 rounded-md py-1.5 transition-colors hover:bg-secondary/60"
        >
            <span
                className={cn(
                    'grid h-7 w-7 place-items-center rounded-full text-sm',
                    isSelected
                        ? 'bg-primary font-semibold text-primary-foreground'
                        : isToday
                          ? 'bg-primary/10 font-semibold text-primary'
                          : day.future
                            ? 'text-muted-foreground/60'
                            : 'text-foreground',
                )}
            >
                {day.day}
            </span>
            <span
                className={cn(
                    'h-1.5 w-1.5 rounded-full',
                    status?.dot,
                    missing && 'border border-muted-foreground/60',
                    !status && !missing && (day.holiday ? 'bg-primary/50' : 'bg-transparent'),
                )}
            />
            <span className="text-[10px] leading-none text-muted-foreground">
                {day.shift && !day.rest_day ? day.shift.code : ''}
            </span>
        </button>
    );
}

function DayDetail({ day, canFileCorrection }) {
    if (!day) {
        return (
            <Card className="p-6 text-sm text-muted-foreground">
                Pick a day to see its punches.
            </Card>
        );
    }

    const status = day.status ? STATUS[day.status] : null;
    const punched = day.time_in || day.time_out;

    const summary = day.future
        ? 'This day has not happened yet.'
        : day.time_in && day.time_out
          ? `Clocked in and out · ${day.hours} hour(s) worked in total`
          : day.time_in
            ? 'Clocked in, but no time-out was recorded.'
            : day.status
              ? `No punches — recorded as ${status.label.toLowerCase()}.`
              : day.rest_day || day.holiday
                ? 'No work scheduled.'
                : 'Nothing has been recorded for this day yet.';

    return (
        <Card className="self-start">
            <div className="flex items-start justify-between gap-3 border-b border-border/60 px-5 py-4">
                <div>
                    <p className="text-sm font-semibold text-foreground">
                        {longDate(day.date)}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {day.holiday
                            ? day.holiday
                            : day.rest_day
                              ? 'Rest day'
                              : day.shift
                                ? `Shift: ${day.shift.code} ${day.shift.start}–${day.shift.end}`
                                : 'No shift assigned'}
                    </p>
                </div>
                {status && <Badge variant={status.variant}>{status.label}</Badge>}
            </div>

            <div className="px-5 py-4">
                <p className="flex items-center gap-2 text-sm text-foreground">
                    <Clock className="h-4 w-4 shrink-0 text-primary" />
                    {summary}
                </p>

                {punched && (
                    <ol className="relative mt-4 space-y-5 border-l border-border pl-5">
                        <Punch
                            icon={LogIn}
                            label="Clock in"
                            time={day.time_in}
                            scheduled={day.shift?.start}
                            flag={
                                day.late_minutes > 0
                                    ? `Late ${minutes(day.late_minutes)}`
                                    : null
                            }
                        />
                        <Punch
                            icon={LogOut}
                            label="Clock out"
                            time={day.time_out}
                            nextDay={day.next_day_out}
                            scheduled={day.shift?.end}
                            flag={
                                day.undertime_minutes > 0
                                    ? `Left ${minutes(day.undertime_minutes)} early`
                                    : null
                            }
                        />
                    </ol>
                )}

                {(day.overtime_minutes > 0 || day.night_diff_minutes > 0) && (
                    <dl className="mt-4 grid grid-cols-2 gap-3 rounded-md bg-secondary/50 p-3 text-xs">
                        <div>
                            <dt className="text-muted-foreground">Past the shift</dt>
                            <dd className="font-medium text-foreground">
                                {minutes(day.overtime_minutes)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Night differential</dt>
                            <dd className="font-medium text-foreground">
                                {minutes(day.night_diff_minutes)}
                            </dd>
                        </div>
                    </dl>
                )}

                {(day.source || day.remarks) && (
                    <p className="mt-4 text-xs text-muted-foreground">
                        {day.source && <span className="capitalize">Source: {day.source}</span>}
                        {day.source && day.remarks && ' · '}
                        {day.remarks}
                    </p>
                )}

                {canFileCorrection && !day.future && (
                    <Link
                        href="/hr/timekeeping/corrections"
                        className="mt-4 inline-block text-xs font-medium text-primary hover:underline"
                    >
                        Something wrong? File a time correction
                    </Link>
                )}
            </div>
        </Card>
    );
}

function Punch({ icon: Icon, label, time, scheduled, nextDay = false, flag }) {
    return (
        <li className="relative">
            <span className="absolute -left-8 top-0 grid h-6 w-6 place-items-center rounded-full border border-border bg-card">
                <Icon className="h-3 w-3 text-muted-foreground" />
            </span>
            <p className="text-sm text-foreground">
                <span className="font-semibold">{time ?? '—'}</span>
                {nextDay && (
                    <span className="ml-1 text-xs text-muted-foreground">(next day)</span>
                )}
                <span className="ml-2 text-xs text-muted-foreground">
                    {label}
                    {scheduled ? ` · shift ${scheduled}` : ''}
                </span>
            </p>
            <div className="mt-1.5">
                {!time ? (
                    <Badge variant="destructive">Missing</Badge>
                ) : flag ? (
                    <Badge variant="warning">{flag}</Badge>
                ) : (
                    <Badge variant="success">Normal</Badge>
                )}
            </div>
        </li>
    );
}
