import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarX, Clock, Plus, Timer, TriangleAlert, UserCheck } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    Field,
    Input,
    Modal,
    Pagination,
    Select,
    StatCard,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatDate, initials } from '@/lib/utils';

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
    const [pendingDelete, setPendingDelete] = useState(null);

    const entry = useForm(BLANK_ENTRY);

    const applyFilter = (key, value) => {
        router.get(
            '/hr/timekeeping',
            { ...filters, [key]: value || undefined },
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

    const stats = [
        {
            label: 'Days Present',
            value: summary.present,
            icon: UserCheck,
            hint: `${summary.records} record(s) in range`,
        },
        {
            label: 'Absences',
            value: summary.absent,
            icon: CalendarX,
        },
        {
            label: 'Late Instances',
            value: summary.late,
            icon: TriangleAlert,
            hint: `${duration(summary.late_minutes)} total`,
        },
        {
            label: 'Overtime Hours',
            value: summary.overtime_hours,
            icon: Timer,
            hint: `${summary.total_hours} hours worked`,
        },
    ];

    return (
        <AppLayout
            title="Timekeeping & Attendance"
            breadcrumbs={[{ label: 'Human Resource' }, { label: 'Timekeeping & Attendance' }]}
            actions={
                can.manage && (
                    <Button size="sm" onClick={() => setEntryOpen(true)}>
                        <Plus className="h-4 w-4" />
                        <span className="hidden sm:inline">Record Time</span>
                    </Button>
                )
            }
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {stats.map((stat) => (
                    <StatCard key={stat.label} {...stat} />
                ))}
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap lg:items-end">
                    <Field label="From" className="w-full sm:w-40">
                        {({ id }) => (
                            <Input
                                id={id}
                                type="date"
                                value={filters.from ?? ''}
                                onChange={(event) => applyFilter('from', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="To" className="w-full sm:w-40">
                        {({ id }) => (
                            <Input
                                id={id}
                                type="date"
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
                                <Input
                                    id={id}
                                    type="date"
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
