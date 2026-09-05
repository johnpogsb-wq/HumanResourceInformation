import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    CalendarX,
    Clock,
    Plus,
    Timer,
    TriangleAlert,
    Upload,
    UserCheck,
    X,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    DateInput,
    Field,
    Input,
    Modal,
    Pagination,
    Select,
    MeterCard,
    SplitStatCard,
    StatCard,
    Table,
    TableEmpty,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { cn, formatDate, initials } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

/** 95 -> "1h 35m"; 0 stays a dash so the table reads as mostly empty. */
const duration = (minutes) => {
    if (!minutes) return '—';

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return hours > 0 ? `${hours}h ${rest}m` : `${rest}m`;
};

/**
 * 1296.18 -> "1,296"; 69.63 -> "69.6".
 *
 * The stored figures carry two decimals because they are sums of minutes
 * divided by 60, not because anyone needs hundredths of an hour. Below ten
 * the first decimal still says something — 8.5 hours is half a shift — and
 * above it the thousands separator does more for legibility than any digit
 * to the right of the point.
 */
const hours = (value) => {
    const number = Number(value) || 0;

    return number >= 100
        ? Math.round(number).toLocaleString()
        : number.toFixed(1).replace(/\.0$/, '');
};

/**
 * Filters the tiles can set that the dropdowns cannot show.
 *
 * Each one counts something no single status names — `attended` is three
 * statuses, `late` is a column rather than a status — which is exactly why
 * each needs a chip: a list narrowed by a filter with no visible control is
 * a list nobody can explain.
 *
 * They narrow the same axis as `status`, so setting any one clears the rest.
 */
const TILE_FILTERS = [
    { key: 'attended', label: 'Days present only', tone: 'success' },
    { key: 'late', label: 'Late days only', tone: 'warning' },
];

/** The keys that cannot be combined — see TILE_FILTERS. */
const EXCLUSIVE = ['status', ...TILE_FILTERS.map(({ key }) => key)];

const BLANK_ENTRY = {
    employee_id: '',
    log_date: '',
    shift_id: '',
    time_in: '',
    break_out: '',
    break_in: '',
    time_out: '',
    status: '',
    remarks: '',
};

export default function Index({
    logs,
    summary,
    filters,
    departments,
    shifts,
    employees,
    statuses,
    can,
}) {
    const [entryOpen, setEntryOpen] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);

    const entry = useForm(BLANK_ENTRY);
    const upload = useForm({ file: null });

    // Per-row import problems, flashed back after the batch runs.
    const importErrors = usePage().props.importErrors ?? [];

    const submitImport = (event) => {
        event.preventDefault();

        upload.post('/hr/timekeeping/import', {
            preserveScroll: true,
            onSuccess: () => {
                upload.reset();
                setImportOpen(false);
            },
        });
    };

    const applyFilter = (key, value) => {
        router.get(
            '/hr/timekeeping',
            {
                ...filters,
                // Touching one exclusive control clears the others, for the
                // same reason drillTo does: they narrow one axis, and leaving
                // one behind quietly ands them together.
                ...(EXCLUSIVE.includes(key)
                    ? Object.fromEntries(EXCLUSIVE.map((k) => [k, undefined]))
                    : {}),
                [key]: value || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const submitEntry = (event) => {
        event.preventDefault();

        entry.post('/hr/timekeeping', {
            preserveScroll: true,
            onSuccess: () => {
                entry.reset();
                setEntryOpen(false);
            },
        });
    };

    const rows = logs.data ?? [];
    const meta = logs.meta ?? {};

    /*
     * Clicking a figure opens the rows it counted, inside the same date range.
     *
     * Carrying the filters through is the whole point: a tile that counted 53
     * late days in March and then opened an unfiltered list has not answered
     * the question it raised, it has replaced it.
     */
    const drillTo = (extra) => {
        const params = new URLSearchParams(
            Object.entries({
                ...filters,
                /*
                 * Every exclusive key is cleared before the tile sets its own.
                 * Without this, drilling into Late and then into Absences
                 * would ask for both at once and return the days somebody was
                 * absent *and* late — which is none of them.
                 */
                ...Object.fromEntries(EXCLUSIVE.map((key) => [key, undefined])),
                ...extra,
            }).filter(([, value]) => Boolean(value)),
        );

        return `/hr/timekeeping?${params.toString()}`;
    };

    // 157 of 205 is the reading that means something; 157 on its own is a
    // number whose scale the reader has to go and find.
    const attendanceRate = summary.records > 0 ? (summary.present / summary.records) * 100 : 0;

    return (
        <AppLayout
            title="Timekeeping & Attendance"
            breadcrumbs={[{ label: 'Human Resource' }, { label: 'Timekeeping & Attendance' }]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {/* Attendance as a share, because that is the question — "157
                    present" says nothing until you know whether the range held
                    160 records or 400. The bar does the arithmetic. */}
                <MeterCard
                    label="Days Present"
                    value={summary.present}
                    percent={attendanceRate}
                    badge={`${Math.round(attendanceRate)}%`}
                    icon={UserCheck}
                    tone={attendanceRate >= 90 ? 'success' : 'warning'}
                    iconTone="success"
                    hint={`of ${summary.records} record(s) in range`}
                    href={drillTo({ attended: '1' })}
                />

                {/* Warning, and grey at zero — "0 absent" in amber reads as a
                    problem when it is the opposite. */}
                <StatCard
                    label="Absences"
                    value={summary.absent}
                    icon={CalendarX}
                    tone={summary.absent > 0 ? 'warning' : 'muted'}
                    hint={
                        summary.records > 0
                            ? `${((summary.absent / summary.records) * 100).toFixed(1)}% of the range`
                            : undefined
                    }
                    href={drillTo({ status: 'absent' })}
                />

                <StatCard
                    label="Late Instances"
                    value={summary.late}
                    icon={TriangleAlert}
                    tone={summary.late > 0 ? 'warning' : 'muted'}
                    hint={`${duration(summary.late_minutes)} lost in total`}
                    /* `late`, not `status=late`: the tile counts late minutes,
                       and a day marked `undertime` can carry them too. */
                    href={drillTo({ late: '1' })}
                />

                {/* Two figures that only mean something beside each other —
                    70 overtime hours is a different story against 1,296 worked
                    than against 200. */}
                <SplitStatCard
                    label="Hours Worked"
                    icon={Timer}
                    tone="info"
                    stats={[
                        { label: 'Total', value: hours(summary.total_hours) },
                        {
                            label: 'Overtime',
                            value: hours(summary.overtime_hours),
                            tone: 'warning',
                        },
                    ]}
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap lg:items-end">
                    <Field label="From" className="w-full sm:w-40">
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={filters.from ?? ''}
                                onChange={(event) => applyFilter('from', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="To" className="w-full sm:w-40">
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={filters.to ?? ''}
                                onChange={(event) => applyFilter('to', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Employee" className="w-full sm:w-56">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.employee_id ?? ''}
                                onChange={(event) =>
                                    applyFilter('employee_id', event.target.value)
                                }
                                placeholder="All employees"
                                options={employees.map((employee) => ({
                                    value: employee.id,
                                    label: employee.full_name,
                                }))}
                            />
                        )}
                    </Field>

                    <Field label="Department" className="w-full sm:w-48">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.department_id ?? ''}
                                onChange={(event) =>
                                    applyFilter('department_id', event.target.value)
                                }
                                placeholder="All departments"
                                options={departments.map((department) => ({
                                    value: department.id,
                                    label: department.name,
                                }))}
                            />
                        )}
                    </Field>

                    <Field label="Status" className="w-full sm:w-44">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.status ?? ''}
                                onChange={(event) => applyFilter('status', event.target.value)}
                                placeholder="All statuses"
                                options={statuses.map((status) => ({
                                    value: status,
                                    label: titleCase(status),
                                }))}
                            />
                        )}
                    </Field>

                    {/* The filters with no control of their own.
                        Both are set by the tiles above and match something no
                        single status names, so no dropdown here can show
                        them — and a list silently narrowed by something
                        invisible is the worst of both. These say the filter is
                        on and give it an off switch. */}
                    {TILE_FILTERS.filter(({ key }) => filters[key]).map(
                        ({ key, label, tone }) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => applyFilter(key, '')}
                                className={cn(
                                    'flex h-9 shrink-0 items-center gap-1.5 self-end rounded-full border px-3 text-xs font-medium transition-colors',
                                    tone === 'warning'
                                        ? 'border-warning/30 bg-warning/10 text-warning hover:bg-warning/20'
                                        : 'border-success/30 bg-success/10 text-success hover:bg-success/20',
                                )}
                            >
                                {label}
                                <X className="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                        ),
                    )}

                    {/* Actions end the filter row: `lg:ml-auto` pushes them
                        right of the last filter, and the row's `items-end`
                        lines them up with the inputs rather than the labels. */}
                    {can.manage && (
                        <div className="flex gap-2 lg:ml-auto">
                            <Button variant="outline" onClick={() => setImportOpen(true)}>
                                <Upload className="h-4 w-4" />
                                Import
                            </Button>
                            <Button onClick={() => setEntryOpen(true)}>
                                <Plus className="h-4 w-4" />
                                Record Time
                            </Button>
                        </div>
                    )}
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Date</TH>
                            <TH>Shift</TH>
                            <TH>In</TH>
                            <TH>Out</TH>
                            <TH className="text-right">Hours</TH>
                            <TH className="text-right">Late</TH>
                            <TH className="text-right">UT</TH>
                            <TH className="text-right">OT</TH>
                            <TH>Status</TH>
                            {can.manage && <TH className="text-right">Actions</TH>}
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={can.manage ? 11 : 10}
                                icon={Clock}
                                title="No time records found"
                                description="Adjust the date range or filters, or record a time entry."
                            />
                        ) : (
                            rows.map((log) => (
                                <TR key={log.id}>
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(log.employee?.full_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {log.employee?.full_name ?? '—'}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {log.employee?.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(log.log_date)}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {log.shift?.name ?? '—'}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm tabular-nums text-foreground">
                                        {log.time_in ?? '—'}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm tabular-nums text-foreground">
                                        {log.time_out ?? '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {log.hours_worked ? log.hours_worked.toFixed(2) : '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums">
                                        <span
                                            className={
                                                log.late_minutes
                                                    ? 'text-destructive'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {duration(log.late_minutes)}
                                        </span>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums">
                                        <span
                                            className={
                                                log.undertime_minutes
                                                    ? 'text-warning'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {duration(log.undertime_minutes)}
                                        </span>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums">
                                        <span
                                            className={
                                                log.overtime_minutes
                                                    ? 'text-success'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {duration(log.overtime_minutes)}
                                        </span>
                                    </TD>

                                    <TD>
                                        <Badge status={log.status} />
                                    </TD>

                                    {can.manage && (
                                        <TD>
                                            <div className="flex justify-end">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => setPendingDelete(log)}
                                                >
                                                    Delete
                                                </Button>
                                            </div>
                                        </TD>
                                    )}
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={meta.links ?? []} meta={meta} />
            </Card>

            {/* Manual time entry */}
            <Modal
                show={entryOpen}
                onClose={() => setEntryOpen(false)}
                title="Record Time Entry"
                description="Late, undertime, overtime, and night differential are computed from the shift."
                maxWidth="2xl"
            >
                <form onSubmit={submitEntry} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Employee" required error={entry.errors.employee_id}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={entry.data.employee_id}
                                    onChange={(event) =>
                                        entry.setData('employee_id', event.target.value)
                                    }
                                    placeholder="Select employee"
                                    error={entry.errors.employee_id}
                                    options={employees.map((employee) => ({
                                        value: employee.id,
                                        label: employee.full_name,
                                    }))}
                                />
                            )}
                        </Field>

                        <Field label="Date" required error={entry.errors.log_date}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={entry.data.log_date}
                                    onChange={(event) =>
                                        entry.setData('log_date', event.target.value)
                                    }
                                    error={entry.errors.log_date}
                                />
                            )}
                        </Field>
                    </div>

                    <Field
                        label="Shift"
                        hint="Leave blank to use the employee's assigned schedule."
                        error={entry.errors.shift_id}
                    >
                        {({ id }) => (
                            <Select
                                id={id}
                                value={entry.data.shift_id}
                                onChange={(event) =>
                                    entry.setData('shift_id', event.target.value)
                                }
                                placeholder="From schedule"
                                options={shifts.map((shift) => ({
                                    value: shift.id,
                                    label: `${shift.name} (${String(shift.start_time).slice(0, 5)}–${String(shift.end_time).slice(0, 5)})`,
                                }))}
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-4">
                        {[
                            ['time_in', 'Time In'],
                            ['break_out', 'Break Out'],
                            ['break_in', 'Break In'],
                            ['time_out', 'Time Out'],
                        ].map(([field, label]) => (
                            <Field key={field} label={label} error={entry.errors[field]}>
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        type="time"
                                        value={entry.data[field]}
                                        onChange={(event) =>
                                            entry.setData(field, event.target.value)
                                        }
                                        error={entry.errors[field]}
                                    />
                                )}
                            </Field>
                        ))}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Status Override"
                            hint="Leave blank to derive from the punches."
                            error={entry.errors.status}
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={entry.data.status}
                                    onChange={(event) =>
                                        entry.setData('status', event.target.value)
                                    }
                                    placeholder="Derive automatically"
                                    options={statuses.map((status) => ({
                                        value: status,
                                        label: titleCase(status),
                                    }))}
                                />
                            )}
                        </Field>

                        <Field label="Remarks" error={entry.errors.remarks}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={entry.data.remarks}
                                    onChange={(event) =>
                                        entry.setData('remarks', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setEntryOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={entry.processing}>
                            Save Entry
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Biometric / CSV import */}
            <Modal
                show={importOpen}
                onClose={() => setImportOpen(false)}
                title="Import Time Records"
                description="Upload a biometric device export. Each row is computed exactly like a hand-keyed entry."
                maxWidth="lg"
            >
                <form onSubmit={submitImport} className="space-y-4">
                    <div className="rounded-lg border border-border bg-secondary/40 p-4">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Expected columns
                        </p>
                        <code className="scrollbar-thin block overflow-x-auto whitespace-pre text-xs text-foreground">
                            employee_number,date,time_in,time_out{'\n'}
                            PPM-2026-0001,2026-08-01,08:00,17:00
                        </code>
                        <p className="mt-2 text-xs text-muted-foreground">
                            <span className="font-medium text-foreground">employee_number</span>{' '}
                            and <span className="font-medium text-foreground">date</span> are
                            required. <code>break_out</code>, <code>break_in</code>, and{' '}
                            <code>device_id</code> are optional. A bad row is skipped and
                            reported — the rest of the file still imports.
                        </p>
                    </div>

                    <Field label="CSV File" required hint="Max 5 MB" error={upload.errors.file}>
                        {({ id }) => (
                            <input
                                id={id}
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(event) =>
                                    upload.setData('file', event.target.files[0] ?? null)
                                }
                                className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-xs file:font-medium file:text-secondary-foreground hover:file:bg-secondary/70"
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setImportOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={upload.processing}>
                            Import
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Rows the importer could not read */}
            {importErrors.length > 0 && (
                <Card className="mt-5 border-destructive/30">
                    <div className="px-5 py-4">
                        <p className="mb-2 text-sm font-semibold text-destructive">
                            Skipped rows from the last import
                        </p>
                        <ul className="space-y-1">
                            {importErrors.map((message, index) => (
                                <li key={index} className="text-xs text-muted-foreground">
                                    {message}
                                </li>
                            ))}
                        </ul>
                    </div>
                </Card>
            )}

            {/* Delete confirmation */}
            <Modal
                show={Boolean(pendingDelete)}
                onClose={() => setPendingDelete(null)}
                title="Delete this time record?"
                maxWidth="md"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setPendingDelete(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(`/hr/timekeeping/${pendingDelete.id}`, {
                                    preserveScroll: true,
                                    onFinish: () => setPendingDelete(null),
                                })
                            }
                        >
                            Delete
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-muted-foreground">
                    The record for{' '}
                    <span className="font-medium text-foreground">
                        {pendingDelete?.employee?.full_name}
                    </span>{' '}
                    on {formatDate(pendingDelete?.log_date)} will be removed. Payroll figures
                    computed from it will change.
                </p>
            </Modal>
        </AppLayout>
    );
}
