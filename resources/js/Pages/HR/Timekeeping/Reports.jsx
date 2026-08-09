import { router } from '@inertiajs/react';
import { CalendarDays, Clock, Download, Timer, TrendingDown } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import SectionTabs from '@/Pages/HR/Timekeeping/Partials/SectionTabs';
import {
    Button,
    Card,
    Field,
    Input,
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
    String(value ?? '').replace(/\b\w/g, (character) => character.toUpperCase());

const duration = (minutes) => {
    if (!minutes) return '—';

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return hours > 0 ? `${hours}h ${rest}m` : `${rest}m`;
};

export default function Reports({ rows, summary, filters, departments, periods }) {
    const applyFilter = (key, value) => {
        router.get(
            '/hr/timekeeping/reports',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const exportUrl = `/hr/timekeeping/reports/export?${new URLSearchParams(
        Object.entries(filters).filter(([, value]) => value !== null && value !== undefined),
    ).toString()}`;

    const stats = [
        {
            label: 'Total Hours',
            value: summary.total_hours,
            icon: Clock,
            hint: `${summary.records} record(s)`,
        },
        { label: 'Days Present', value: summary.present, icon: CalendarDays },
        {
            label: 'Absences',
            value: summary.absent,
            icon: TrendingDown,
            hint: `${duration(summary.late_minutes)} late total`,
        },
        { label: 'Overtime Hours', value: summary.overtime_hours, icon: Timer },
    ];

    return (
        <AppLayout
            title="Timekeeping & Attendance"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Timekeeping', href: '/hr/timekeeping' },
                { label: 'Reports' },
            ]}
            actions={
                <Button size="sm" variant="outline" external href={exportUrl}>
                    <Download className="h-4 w-4" />
                    <span className="hidden sm:inline">Export CSV</span>
                </Button>
            }
        >
            <SectionTabs />

            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {stats.map((stat) => (
                    <StatCard key={stat.label} {...stat} />
                ))}
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap lg:items-end">
                    <Field label="Period" className="w-full sm:w-40">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.period}
                                onChange={(event) => applyFilter('period', event.target.value)}
                                options={periods.map((period) => ({
                                    value: period,
                                    label: titleCase(period),
                                }))}
                            />
                        )}
                    </Field>

                    {filters.period === 'custom' ? (
                        <>
                            <Field label="From" className="w-full sm:w-40">
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        type="date"
                                        value={filters.from}
                                        onChange={(event) =>
                                            applyFilter('from', event.target.value)
                                        }
                                    />
                                )}
                            </Field>

                            <Field label="To" className="w-full sm:w-40">
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        type="date"
                                        value={filters.to}
                                        onChange={(event) =>
                                            applyFilter('to', event.target.value)
                                        }
                                    />
                                )}
                            </Field>
                        </>
                    ) : (
                        <Field
                            label="Anchor Date"
                            hint="Any date inside the period"
                            className="w-full sm:w-44"
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="date"
                                    value={filters.anchor}
                                    onChange={(event) =>
                                        applyFilter('anchor', event.target.value)
                                    }
                                />
                            )}
                        </Field>
                    )}

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

                    <p className="pb-2 text-xs text-muted-foreground lg:ml-auto">
                        Covering {formatDate(filters.from)} – {formatDate(filters.to)}
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Department</TH>
                            <TH className="text-right">Present</TH>
                            <TH className="text-right">Absent</TH>
                            <TH className="text-right">Late</TH>
                            <TH className="text-right">Late Time</TH>
                            <TH className="text-right">Undertime</TH>
                            <TH className="text-right">OT Hrs</TH>
                            <TH className="text-right">Night Hrs</TH>
                            <TH className="text-right">Total Hrs</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={10}
                                icon={CalendarDays}
                                title="No attendance in this period"
                                description="Pick a different period, or record time on the Daily Records tab."
                            />
                        ) : (
                            rows.map((row) => (
                                <TR key={row.employee_id}>
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(row.full_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {row.full_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {row.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {row.department ?? '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {row.days_present}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums">
                                        <span
                                            className={
                                                row.days_absent
                                                    ? 'text-destructive'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {row.days_absent}
                                        </span>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {row.late_count}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {duration(row.late_minutes)}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {duration(row.undertime_minutes)}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {row.overtime_hours || '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {row.night_diff_hours || '—'}
                                    </TD>

                                    <TD className="text-right text-sm font-medium tabular-nums text-foreground">
                                        {row.total_hours}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>
        </AppLayout>
    );
}
