import { Link } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarDays,
    ClipboardCheck,
    Clock,
    UserCheck,
    UserPlus,
    Users,
    Wallet,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Card,
    CardBody,
    CardHeader,
    MeterCard,
    SplitStatCard,
    StatCard,
} from '@/Components/ui';
import { cn, formatCurrency, formatDate, initials } from '@/lib/utils';

/** Fixed order, never cycled — the set is only validated for four slots. */
const SERIES = ['bg-chart-1', 'bg-chart-2', 'bg-chart-3', 'bg-chart-4'];
const SERIES_TEXT = ['text-chart-1', 'text-chart-2', 'text-chart-3', 'text-chart-4'];

/**
 * Active headcount by department.
 *
 * One measure across categories, so every bar wears the same hue — colour would
 * encode nothing here. Counts are direct-labelled, so identity never rests on
 * colour alone.
 */
function HeadcountChart({ data }) {
    const max = Math.max(...data.map((row) => row.count), 1);
    const total = data.reduce((sum, row) => sum + row.count, 0);

    if (data.length === 0) {
        return <p className="text-sm text-muted-foreground">No departments configured yet.</p>;
    }

    return (
        <div className="space-y-3.5">
            {data.map((row) => {
                const share = total > 0 ? Math.round((row.count / total) * 100) : 0;

                return (
                    <div
                        key={row.name}
                        className="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3"
                        title={`${row.name}: ${row.count} active (${share}% of headcount)`}
                    >
                        <span className="truncate text-xs text-muted-foreground">
                            {row.name}
                        </span>

                        <span className="h-2.5 w-full overflow-hidden rounded-sm bg-muted">
                            <span
                                className="block h-full rounded-r-[4px] bg-chart-1 transition-[width] duration-500"
                                style={{
                                    width: `${Math.max((row.count / max) * 100, row.count > 0 ? 3 : 0)}%`,
                                }}
                            />
                        </span>

                        <span className="w-8 text-right text-xs font-medium tabular-nums text-foreground">
                            {row.count}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

/**
 * Employment status mix as a donut.
 *
 * Part-to-whole across four categories, so identity *is* the job here — hence
 * the categorical palette. Every slice is also named and counted in the legend,
 * so the chart never depends on colour alone.
 */
function StatusDonut({ data }) {
    const total = data.reduce((sum, row) => sum + row.count, 0);

    if (total === 0) {
        return <p className="text-sm text-muted-foreground">No employee records yet.</p>;
    }

    const radius = 56;
    const circumference = 2 * Math.PI * radius;
    let offset = 0;

    return (
        <div className="flex flex-col items-center gap-5 sm:flex-row sm:items-center">
            <div className="relative shrink-0">
                <svg
                    viewBox="0 0 140 140"
                    className="h-36 w-36 -rotate-90"
                    role="img"
                    aria-label={`Employment status: ${data.map((r) => `${r.label} ${r.count}`).join(', ')}`}
                >
                    {data.map((row, index) => {
                        const length = (row.count / total) * circumference;
                        // A 2px gap keeps neighbouring slices from merging.
                        const dash = `${Math.max(length - 2, 0)} ${circumference - Math.max(length - 2, 0)}`;
                        const thisOffset = offset;
                        offset += length;

                        if (row.count === 0) return null;

                        return (
                            <circle
                                key={row.label}
                                cx="70"
                                cy="70"
                                r={radius}
                                fill="none"
                                strokeWidth="16"
                                className={cn(
                                    'stroke-current',
                                    SERIES_TEXT[index % SERIES.length],
                                )}
                                strokeDasharray={dash}
                                strokeDashoffset={-thisOffset}
                            />
                        );
                    })}
                </svg>

                <div className="absolute inset-0 grid place-items-center">
                    <div className="text-center">
                        <p className="text-2xl font-semibold tabular-nums text-foreground">
                            {total}
                        </p>
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground">
                            Total
                        </p>
                    </div>
                </div>
            </div>

            <dl className="w-full space-y-2">
                {data.map((row, index) => (
                    <div key={row.label} className="flex items-center gap-2.5">
                        <span
                            className={cn(
                                'h-2.5 w-2.5 shrink-0 rounded-sm',
                                SERIES[index % SERIES.length],
                            )}
                            aria-hidden="true"
                        />
                        <dt className="flex-1 truncate text-xs text-muted-foreground">
                            {row.label}
                        </dt>
                        <dd className="text-xs font-medium tabular-nums text-foreground">
                            {row.count}
                        </dd>
                        <dd className="w-9 text-right text-xs tabular-nums text-muted-foreground">
                            {Math.round((row.count / total) * 100)}%
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

export default function Dashboard({
    statistics,
    headcountByDepartment,
    statusMix,
    recentHires,
    attendanceToday,
    approvals,
    payroll,
    leaveToday,
}) {
    return (
        <AppLayout title="Dashboard" breadcrumbs={[{ label: 'Overview' }]}>
            {/* Headline figures */}
            <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Total Employees"
                    value={statistics.total}
                    icon={Users}
                    hint={`${statistics.active} active · ${statistics.probationary} probationary`}
                />
                <StatCard
                    label="Present Today"
                    value={attendanceToday.present}
                    icon={UserCheck}
                    hint={`of ${attendanceToday.expected} scheduled`}
                />
                <StatCard
                    label="On Leave Today"
                    value={leaveToday.count}
                    icon={CalendarDays}
                    hint={leaveToday.count > 0 ? leaveToday.summary : 'Nobody is away'}
                />
                <StatCard
                    label="Latest Payroll"
                    value={formatCurrency(payroll.total_net)}
                    icon={Wallet}
                    hint={payroll.period ?? 'No payroll run yet'}
                />
            </div>

            {/* Operational detail */}
            <div className="mb-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <SplitStatCard
                    label="Today's Attendance"
                    icon={Clock}
                    stats={[
                        { label: 'Present', value: attendanceToday.present },
                        { label: 'Late', value: attendanceToday.late },
                        { label: 'Absent', value: attendanceToday.absent, tone: 'muted' },
                    ]}
                />

                <MeterCard
                    label="Attendance Rate"
                    value={`${attendanceToday.rate}%`}
                    percent={attendanceToday.rate}
                    icon={UserCheck}
                    hint={`${attendanceToday.present} of ${attendanceToday.expected} on duty`}
                />

                <MeterCard
                    label="Avg Performance"
                    value={statistics.average_rating?.toFixed(2) ?? '—'}
                    percent={((statistics.average_rating ?? 0) / 5) * 100}
                    icon={ClipboardCheck}
                    badge={statistics.performance_band ?? undefined}
                    hint="Latest completed review cycle"
                />

                <SplitStatCard
                    label="Awaiting Approval"
                    icon={CalendarClock}
                    stats={[
                        { label: 'Leave', value: approvals.leave },
                        { label: 'Overtime', value: approvals.overtime },
                        { label: 'Reviews', value: approvals.reviews, tone: 'muted' },
                    ]}
                />
            </div>

            {/* Charts */}
            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader
                        title="Active Headcount by Department"
                        description="Employees with an active record."
                    />
                    <CardBody>
                        <HeadcountChart data={headcountByDepartment} />
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Employment Status" description="Across all records." />
                    <CardBody>
                        <StatusDonut data={statusMix} />
                    </CardBody>
                </Card>
            </div>

            {/* Recent activity */}
            <Card>
                <CardHeader
                    title="Recent Hires"
                    description="Latest five employees onboarded."
                    action={
                        <Link
                            href="/hr/employees"
                            className="text-xs font-medium text-primary hover:underline"
                        >
                            View directory
                        </Link>
                    }
                />
                <CardBody>
                    {recentHires.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 py-8 text-center">
                            <span className="grid h-11 w-11 place-items-center rounded-full bg-secondary text-muted-foreground">
                                <UserPlus className="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p className="text-sm font-medium text-foreground">
                                No employees yet
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Add your first employee record to get started.
                            </p>
                        </div>
                    ) : (
                        <ul className="grid gap-1 sm:grid-cols-2 xl:grid-cols-3">
                            {recentHires.map((hire) => (
                                <li key={hire.id}>
                                    <Link
                                        href={`/hr/employees/${hire.id}`}
                                        className="flex items-center gap-3 rounded-lg p-2 transition-colors hover:bg-secondary/60"
                                    >
                                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                                            {initials(hire.full_name)}
                                        </span>

                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium text-foreground">
                                                {hire.full_name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {hire.position ?? 'No position assigned'}
                                            </span>
                                        </span>

                                        <Badge variant="muted">
                                            {formatDate(hire.date_hired)}
                                        </Badge>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardBody>
            </Card>
        </AppLayout>
    );
}
