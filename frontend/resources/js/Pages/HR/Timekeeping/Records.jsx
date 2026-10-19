import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    AlarmClock,
    ArrowLeft,
    CalendarX,
    CircleAlert,
    ClipboardList,
    FilePenLine,
    Lock,
    Plus,
    Printer,
    ScanFace,
    Upload,
    UserCheck,
    Users,
} from 'lucide-react';

import AppLayout from '@/Layouts/AppLayout';
import {
    Button,
    Card,
    DateInput,
    Field,
    Input,
    Modal,
    Pagination,
    SearchInput,
    Select,
    StatCard,
    Table,
    TableEmpty,
    TBody,
    TD,
    TFoot,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { cn, formatDate, withFilters } from '@/lib/utils';
import { DayStatus, minutes, TIMEKEEPING_CRUMBS } from './Partials/shared';

const BLANK = { employee_id: '', work_date: '', time_in: '', time_out: '', remarks: '' };

export default function Records({
    view,
    logs,
    roster,
    sheet,
    search,
    filters,
    summary,
    statuses,
    employees,
    can,
}) {
    const [editing, setEditing] = useState(null);
    const [importing, setImporting] = useState(false);
    const form = useForm(BLANK);
    const upload = useForm({ file: null });

    const apply = (changes) =>
        router.get(
            withFilters('/hr/timekeeping', filters, { ...changes, page: undefined }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // Choosing who to read clears the status, so the DTR opens whole.
    const openEmployee = (id) =>
        router.get(
            withFilters('/hr/timekeeping', filters, { employee: id, status: undefined }),
        );

    const openNew = (prefill = {}) => {
        form.clearErrors();
        form.setData({
            ...BLANK,
            work_date: new Date().toISOString().slice(0, 10),
            ...prefill,
        });
        setEditing({ id: 'new', employee: prefill.employee ?? null });
    };

    const openEdit = (log) => {
        form.clearErrors();
        form.setData({
            employee_id: log.employee.id,
            work_date: log.work_date,
            time_in: log.time_in ?? '',
            time_out: log.time_out ?? '',
            remarks: log.remarks ?? '',
        });
        setEditing(log);
    };

    const close = () => setEditing(null);

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: close };

        if (editing.id === 'new') {
            form.post('/hr/timekeeping/records', options);
        } else {
            form.put(`/hr/timekeeping/records/${editing.id}`, options);
        }
    };

    const remove = (log) => {
        if (
            !window.confirm(
                `Delete ${log.employee.name}'s record for ${formatDate(log.work_date)}?`,
            )
        ) {
            return;
        }

        router.delete(`/hr/timekeeping/records/${log.id}`, { preserveScroll: true });
    };

    const submitImport = (event) => {
        event.preventDefault();
        upload.post('/hr/timekeeping/records/import', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                upload.reset();
                setImporting(false);
            },
        });
    };

    const isNew = editing?.id === 'new';

    return (
        <AppLayout
            title="Daily Time Records"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Daily Time Records' }]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden">
                <StatCard
                    label="Days worked"
                    value={summary.worked}
                    icon={UserCheck}
                    tone={summary.worked > 0 ? 'success' : 'muted'}
                />
                <StatCard
                    label="Late days"
                    value={summary.late}
                    icon={AlarmClock}
                    tone={summary.late > 0 ? 'warning' : 'muted'}
                    href={withFilters('/hr/timekeeping', filters, { status: 'late' })}
                />
                <StatCard
                    label="Absences"
                    value={summary.absent}
                    icon={CalendarX}
                    tone={summary.absent > 0 ? 'destructive' : 'muted'}
                    href={withFilters('/hr/timekeeping', filters, { status: 'absent' })}
                />
                <StatCard
                    label="No time-out"
                    value={summary.incomplete}
                    icon={CircleAlert}
                    tone={summary.incomplete > 0 ? 'destructive' : 'muted'}
                    href={withFilters('/hr/timekeeping', filters, { status: 'incomplete' })}
                />
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap lg:items-end print:hidden">
                    <Field label="From" className="lg:w-40">
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={filters.from}
                                onChange={(event) => apply({ from: event.target.value })}
                            />
                        )}
                    </Field>
                    <Field label="To" className="lg:w-40">
                        {({ id }) => (
                            <DateInput
                                id={id}
                                value={filters.to}
                                onChange={(event) => apply({ to: event.target.value })}
                            />
                        )}
                    </Field>
                    {employees.length > 1 && (
                        <Field label="Employee" className="lg:w-64">
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={filters.employee ?? ''}
                                    onChange={(event) =>
                                        apply({ employee: event.target.value, view: undefined })
                                    }
                                    placeholder="All employees"
                                    options={employees}
                                />
                            )}
                        </Field>
                    )}
                    {view !== 'roster' && (
                        <Field label="Status" className="lg:w-44">
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={filters.status ?? ''}
                                    onChange={(event) => apply({ status: event.target.value })}
                                    placeholder="All statuses"
                                    options={statuses}
                                />
                            )}
                        </Field>
                    )}
                    {view === 'roster' && (
                        <div className="lg:w-64">
                            <SearchInput
                                defaultValue={search}
                                onChange={(event) => apply({ search: event.target.value })}
                                placeholder="Search name or employee number…"
                                aria-label="Search employees"
                            />
                        </div>
                    )}

                    <div className="flex flex-wrap gap-2 lg:ml-auto">
                        {view === 'employee' && sheet && (
                            <Button variant="outline" onClick={() => window.print()}>
                                <Printer className="h-4 w-4" />
                                Print DTR
                            </Button>
                        )}
                        {can.fileCorrection && !can.manage && (
                            <Button variant="outline" href="/hr/timekeeping/corrections">
                                <FilePenLine className="h-4 w-4" />
                                Request a correction
                            </Button>
                        )}
                        {can.manage && (
                            <>
                                <Button variant="outline" onClick={() => setImporting(true)}>
                                    <Upload className="h-4 w-4" />
                                    Import CSV
                                </Button>
                                <Button
                                    onClick={() =>
                                        openNew(
                                            sheet
                                                ? {
                                                      employee_id: sheet.employee.id,
                                                      employee: sheet.employee,
                                                  }
                                                : {},
                                        )
                                    }
                                >
                                    <Plus className="h-4 w-4" />
                                    Add Record
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {view === 'roster' && <RosterTable roster={roster} onOpen={openEmployee} />}

                {view === 'employee' && (
                    <EmployeeSheet
                        sheet={sheet}
                        filters={filters}
                        canGoBack={employees.length > 1}
                        canManage={can.manage}
                        onAdd={(date) =>
                            openNew({
                                employee_id: sheet.employee.id,
                                employee: sheet.employee,
                                work_date: date,
                            })
                        }
                        onEdit={openEdit}
                        onDelete={remove}
                    />
                )}

                {view === 'days' && (
                    <DaysTable
                        logs={logs}
                        canManage={can.manage}
                        onOpen={openEmployee}
                        onEdit={openEdit}
                    />
                )}
            </Card>

            <Modal
                show={editing !== null}
                onClose={close}
                title={isNew ? 'Add Time Record' : 'Edit Time Record'}
            >
                <form onSubmit={submit} className="space-y-4">
                    {isNew && !editing.employee ? (
                        <Field label="Employee" required error={form.errors.employee_id}>
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.employee_id}
                                    onChange={(event) =>
                                        form.setData('employee_id', event.target.value)
                                    }
                                    placeholder="Choose an employee"
                                    options={employees}
                                    required
                                />
                            )}
                        </Field>
                    ) : (
                        editing && (
                            <p className="text-sm text-foreground">
                                {editing.employee.name}
                                {!isNew && ` · ${formatDate(editing.work_date)}`}
                            </p>
                        )
                    )}

                    {isNew && (
                        <Field label="Date" required error={form.errors.work_date}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.work_date}
                                    onChange={(event) =>
                                        form.setData('work_date', event.target.value)
                                    }
                                    required
                                />
                            )}
                        </Field>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Time in"
                            error={form.errors.time_in}
                            hint="Blank for an absence, rest day or holiday"
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="time"
                                    value={form.data.time_in}
                                    onChange={(event) =>
                                        form.setData('time_in', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                        <Field
                            label="Time out"
                            error={form.errors.time_out}
                            hint="Earlier than time in = next morning"
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="time"
                                    value={form.data.time_out}
                                    onChange={(event) =>
                                        form.setData('time_out', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                    </div>

                    <Field label="Remarks" error={form.errors.remarks}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={form.data.remarks}
                                onChange={(event) =>
                                    form.setData('remarks', event.target.value)
                                }
                                placeholder="e.g. Keyed from the paper logbook"
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Save Record
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                show={importing}
                onClose={() => setImporting(false)}
                title="Import from the biometric device"
                description="A CSV with the columns employee_number, date, time_in, time_out. Rows inside a closed cutoff or for an unknown employee are skipped and named."
            >
                <form onSubmit={submitImport} className="space-y-4">
                    <Field label="CSV file" required error={upload.errors.file}>
                        {({ id }) => (
                            <Input
                                id={id}
                                type="file"
                                accept=".csv,text/csv"
                                onChange={(event) =>
                                    upload.setData('file', event.target.files[0])
                                }
                                required
                            />
                        )}
                    </Field>

                    <pre className="overflow-x-auto rounded-md bg-secondary/50 p-3 text-xs text-muted-foreground">
                        {
                            'employee_number,date,time_in,time_out\nEMP-0001,2026-09-01,07:58,17:04'
                        }
                    </pre>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setImporting(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" loading={upload.processing}>
                            Import
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}

/** Everyone in scope, with their totals for the range. */
function RosterTable({ roster, onOpen }) {
    const rows = roster?.data ?? [];

    return (
        <>
            <Table>
                <THead>
                    <TR>
                        <TH>Employee</TH>
                        <TH className="text-right">Days worked</TH>
                        <TH className="text-right">Late</TH>
                        <TH className="text-right">Absent</TH>
                        <TH className="text-right">No time-out</TH>
                        <TH className="text-right">Hours</TH>
                        <TH className="text-right">Past shift</TH>
                        <TH className="text-right">DTR</TH>
                    </TR>
                </THead>
                <TBody>
                    {rows.length === 0 ? (
                        <TableEmpty
                            colSpan={8}
                            icon={Users}
                            title="Nobody matches"
                            description="Try clearing the search."
                        />
                    ) : (
                        rows.map((row) => (
                            <TR key={row.id}>
                                <TD>
                                    <button
                                        type="button"
                                        onClick={() => onOpen(row.id)}
                                        className="text-left"
                                    >
                                        <span className="block text-sm font-medium text-foreground hover:text-primary hover:underline">
                                            {row.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {row.number}
                                            {row.position ? ` · ${row.position}` : ''}
                                            {row.assignment ? ` · ${row.assignment}` : ''}
                                        </span>
                                    </button>
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {row.recorded === 0 ? (
                                        <span className="text-muted-foreground">
                                            No records
                                        </span>
                                    ) : (
                                        row.worked
                                    )}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    <Count value={row.late} tone="text-warning" />
                                    {row.late_minutes > 0 && (
                                        <span className="block text-xs text-muted-foreground">
                                            {minutes(row.late_minutes)}
                                        </span>
                                    )}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    <Count value={row.absent} tone="text-destructive" />
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    <Count value={row.incomplete} tone="text-destructive" />
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {row.hours ? row.hours.toFixed(2) : '—'}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {minutes(row.overtime_minutes)}
                                </TD>
                                <TD className="text-right">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => onOpen(row.id)}
                                    >
                                        View DTR
                                    </Button>
                                </TD>
                            </TR>
                        ))
                    )}
                </TBody>
            </Table>

            <Pagination links={roster?.links ?? []} meta={roster} />
        </>
    );
}

function Count({ value, tone }) {
    return value > 0 ? (
        <span className={cn('font-medium', tone)}>{value}</span>
    ) : (
        <span className="text-muted-foreground">—</span>
    );
}

/** One employee's DTR: every date in the range, recorded or not. */
function EmployeeSheet({ sheet, filters, canGoBack, canManage, onAdd, onEdit, onDelete }) {
    if (!sheet) {
        return (
            <p className="p-8 text-center text-sm text-muted-foreground">
                That employee is not in your records.
            </p>
        );
    }

    const { employee, days, totals } = sheet;

    return (
        <>
            <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    {canGoBack && (
                        <Link
                            href={withFilters('/hr/timekeeping', filters, {
                                employee: undefined,
                                status: undefined,
                            })}
                            className="mb-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline print:hidden"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" /> All employees
                        </Link>
                    )}
                    <h2 className="text-lg font-semibold text-foreground">{employee.name}</h2>
                    <p className="text-sm text-muted-foreground">
                        {employee.number}
                        {employee.position ? ` · ${employee.position}` : ''}
                        {employee.assignment ? ` · ${employee.assignment}` : ''}
                    </p>
                    {employee.shift && (
                        <p className="text-xs text-muted-foreground">{employee.shift}</p>
                    )}
                </div>
                <p className="text-sm text-muted-foreground sm:text-right">
                    Daily Time Record
                    <span className="block font-medium text-foreground">
                        {formatDate(sheet.from)} – {formatDate(filters.to)}
                    </span>
                    {sheet.truncated && (
                        <span className="block text-xs text-warning">
                            Showing the last 93 days of the range
                        </span>
                    )}
                </p>
            </div>

            <Table>
                <THead>
                    <TR>
                        <TH>Date</TH>
                        <TH>Time in</TH>
                        <TH>Break</TH>
                        <TH>Time out</TH>
                        <TH>Status</TH>
                        <TH className="text-right">Hours</TH>
                        <TH className="text-right">Late</TH>
                        <TH className="text-right">Undertime</TH>
                        <TH className="text-right">Past shift</TH>
                        {canManage && <TH className="text-right print:hidden">Actions</TH>}
                    </TR>
                </THead>
                <TBody>
                    {days.length === 0 ? (
                        <TableEmpty
                            colSpan={canManage ? 10 : 9}
                            icon={ClipboardList}
                            title="No days match this status"
                            description="Clear the status filter to see the whole range."
                        />
                    ) : (
                        days.map((day) => {
                            const log = day.log;

                            return (
                                <TR
                                    key={day.date}
                                    className={cn(
                                        !log && (day.rest_day || day.holiday) && 'bg-muted/30',
                                    )}
                                >
                                    <TD className="whitespace-nowrap">
                                        <p className="text-sm font-medium text-foreground">
                                            {formatDate(day.date)}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {day.day}
                                            {day.holiday ? ` · ${day.holiday}` : ''}
                                            {!day.holiday && day.rest_day ? ' · Rest day' : ''}
                                        </p>
                                    </TD>
                                    {log ? (
                                        <>
                                            <TD className="whitespace-nowrap text-sm tabular-nums">
                                                {log.time_in ?? '—'}
                                            </TD>
                                            <TD className="whitespace-nowrap text-sm tabular-nums text-muted-foreground">
                                                {log.break_start
                                                    ? `${log.break_start} – ${log.break_end ?? '…'}`
                                                    : '—'}
                                            </TD>
                                            <TD className="whitespace-nowrap text-sm tabular-nums">
                                                {log.time_out ?? '—'}
                                                {log.next_day_out && (
                                                    <span className="ml-1 text-xs text-muted-foreground">
                                                        +1
                                                    </span>
                                                )}
                                            </TD>
                                            <TD>
                                                <div className="flex items-center gap-1.5">
                                                    <DayStatus status={log.status} />
                                                    {log.punches > 0 && (
                                                        <ScanFace
                                                            className="h-3.5 w-3.5 text-muted-foreground"
                                                            aria-label="Punched on the time clock"
                                                        />
                                                    )}
                                                    {log.locked && (
                                                        <Lock
                                                            className="h-3.5 w-3.5 text-muted-foreground"
                                                            aria-label="Cutoff closed"
                                                        />
                                                    )}
                                                </div>
                                                {log.remarks && (
                                                    <p className="mt-1 max-w-56 truncate text-xs text-muted-foreground">
                                                        {log.remarks}
                                                    </p>
                                                )}
                                            </TD>
                                            <TD className="text-right text-sm tabular-nums">
                                                {log.hours ? log.hours.toFixed(2) : '—'}
                                            </TD>
                                            <TD className="text-right text-sm tabular-nums">
                                                {minutes(log.late_minutes)}
                                            </TD>
                                            <TD className="text-right text-sm tabular-nums">
                                                {minutes(log.undertime_minutes)}
                                            </TD>
                                            <TD className="text-right text-sm tabular-nums">
                                                {minutes(log.overtime_minutes)}
                                            </TD>
                                        </>
                                    ) : (
                                        <TD
                                            colSpan={8}
                                            className="text-sm text-muted-foreground"
                                        >
                                            {day.future
                                                ? '—'
                                                : day.holiday || day.rest_day
                                                  ? 'No work scheduled'
                                                  : 'No record'}
                                        </TD>
                                    )}
                                    {canManage && (
                                        <TD className="whitespace-nowrap text-right print:hidden">
                                            {log && !log.locked && (
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            onEdit({ ...log, employee })
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="text-destructive"
                                                        onClick={() =>
                                                            onDelete({ ...log, employee })
                                                        }
                                                    >
                                                        Delete
                                                    </Button>
                                                </div>
                                            )}
                                            {!log && !day.future && !day.locked && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => onAdd(day.date)}
                                                >
                                                    Add
                                                </Button>
                                            )}
                                        </TD>
                                    )}
                                </TR>
                            );
                        })
                    )}
                </TBody>
                <TFoot>
                    <TR>
                        <TD colSpan={5} className="text-sm font-medium text-foreground">
                            {totals.worked} day(s) worked · {totals.absent} absent
                        </TD>
                        <TD className="text-right text-sm font-semibold tabular-nums">
                            {totals.hours ? totals.hours.toFixed(2) : '—'}
                        </TD>
                        <TD className="text-right text-sm font-semibold tabular-nums">
                            {minutes(totals.late_minutes)}
                        </TD>
                        <TD className="text-right text-sm font-semibold tabular-nums">
                            {minutes(totals.undertime_minutes)}
                        </TD>
                        <TD className="text-right text-sm font-semibold tabular-nums">
                            {minutes(totals.overtime_minutes)}
                        </TD>
                        {canManage && <TD className="print:hidden" />}
                    </TR>
                </TFoot>
            </Table>

            <div className="hidden grid-cols-2 gap-10 px-4 pb-6 pt-12 text-sm print:grid">
                <div className="border-t border-foreground pt-1 text-center">
                    Employee's signature
                </div>
                <div className="border-t border-foreground pt-1 text-center">
                    Verified by (supervisor / HR)
                </div>
            </div>
        </>
    );
}

/** Matching days across everyone — what a status tile counted. */
function DaysTable({ logs, canManage, onOpen, onEdit }) {
    const rows = logs?.data ?? [];

    return (
        <>
            <Table>
                <THead>
                    <TR>
                        <TH>Date</TH>
                        <TH>Employee</TH>
                        <TH>In / Out</TH>
                        <TH>Status</TH>
                        <TH className="text-right">Hours</TH>
                        <TH className="text-right">Late</TH>
                        <TH className="text-right">Undertime</TH>
                        <TH className="text-right">Past shift</TH>
                        {canManage && <TH className="text-right">Actions</TH>}
                    </TR>
                </THead>
                <TBody>
                    {rows.length === 0 ? (
                        <TableEmpty
                            colSpan={canManage ? 9 : 8}
                            icon={ClipboardList}
                            title="No time records match"
                            description="Try a wider date range or another status."
                        />
                    ) : (
                        rows.map((log) => (
                            <TR key={log.id}>
                                <TD className="whitespace-nowrap">
                                    <p className="text-sm font-medium text-foreground">
                                        {formatDate(log.work_date)}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {log.day}
                                        {log.shift ? ` · ${log.shift}` : ''}
                                    </p>
                                </TD>
                                <TD>
                                    <button
                                        type="button"
                                        onClick={() => onOpen(log.employee.id)}
                                        className="text-left"
                                    >
                                        <span className="block text-sm text-foreground hover:text-primary hover:underline">
                                            {log.employee.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {log.employee.number}
                                        </span>
                                    </button>
                                </TD>
                                <TD className="whitespace-nowrap text-sm tabular-nums text-foreground">
                                    {log.time_in ?? '—'} – {log.time_out ?? '—'}
                                    {log.next_day_out && (
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            +1
                                        </span>
                                    )}
                                </TD>
                                <TD>
                                    <div className="flex items-center gap-1.5">
                                        <DayStatus status={log.status} />
                                        {log.locked && (
                                            <Lock
                                                className="h-3.5 w-3.5 text-muted-foreground"
                                                aria-label="Cutoff closed"
                                            />
                                        )}
                                    </div>
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {log.hours ? log.hours.toFixed(2) : '—'}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {minutes(log.late_minutes)}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {minutes(log.undertime_minutes)}
                                </TD>
                                <TD className="text-right text-sm tabular-nums">
                                    {minutes(log.overtime_minutes)}
                                </TD>
                                {canManage && (
                                    <TD className="text-right">
                                        {!log.locked && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => onEdit(log)}
                                            >
                                                Edit
                                            </Button>
                                        )}
                                    </TD>
                                )}
                            </TR>
                        ))
                    )}
                </TBody>
            </Table>

            <Pagination links={logs?.links ?? []} meta={logs} />
        </>
    );
}
