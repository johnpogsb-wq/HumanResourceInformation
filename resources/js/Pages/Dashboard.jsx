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
    StatTile,
    TilePreview,
    TrendChart,
} from '@/Components/ui';
import { cn, formatCurrency, formatDate, initials } from '@/lib/utils';

/** Fixed order, never cycled — the set is only validated for four slots. */
const SERIES = ['bg-chart-1', 'bg-chart-2', 'bg-chart-3', 'bg-chart-4'];
const SERIES_TEXT = ['text-chart-1', 'text-chart-2', 'text-chart-3', 'text-chart-4'];

/**
 * First point to last, for the badge beside the trend chart.
 *
 * Null rather than zero when there is nothing to compare — a badge reading
 * "+0" on a one-point series claims a stability that was never measured.
 */
function trendDelta(series) {
    if (!Array.isArray(series) || series.length < 2) return null;

    return (Number(series.at(-1).value) || 0) - (Number(series[0].value) || 0);
}

/**
 * Where a percentage sits on the good -> bad ramp.
 *
 * Presentational only — these cut-offs colour a bar, they do not decide
 * anything. The rules that carry consequences (chronic lateness, absence
 * trends) live in config/timekeeping.php and are deliberately not restated
 * here, so nobody can mistake a shade for a threshold.
 */
function gradeForPercent(percent) {
    const value = Number(percent) || 0;

    if (value >= 95) return 'grade-1';
    if (value >= 90) return 'grade-2';
    if (value >= 80) return 'grade-3';
    if (value >= 70) return 'grade-4';
    if (value >= 50) return 'grade-5';

    return 'grade-6';
}

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
    headcountTrend,
    statusMix,
    recentHires,
    attendanceToday,
    approvals,
    payroll,
    leaveToday,
    leaveSummary,
    payrollSummary,
    onboardingSummary,
    can,
}) {
    const headcountChange = statistics.headcount_change ?? 0;

    // Company-wide summaries arrive as null for a role that may not read them,
    // so the card is never drawn empty — it is simply not there.
    const companyCards = [leaveSummary, payrollSummary].filter(Boolean).length;

    return (
        <AppLayout title="Dashboard" breadcrumbs={[{ label: 'Overview' }]}>
            {/* Headline figures */}
            <div className="mb-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                {/* Each tile links to the screen its figure came from, so a
                    number that raises a question is one click from its
                    answer. */}
                <StatCard
                    floating
                    href="/hr/employees"
                    label="Total Employees"
                    value={statistics.total}
                    icon={Users}
                    hint={`${statistics.active} active · ${statistics.probationary} probationary`}
                    // Only shown when it moved — an arrow reading "0" every day
                    // is a line the eye learns to skip.
                    trend={
                        headcountChange === 0
                            ? undefined
                            : {
                                  direction: headcountChange > 0 ? 'up' : 'down',
                                  label: `${headcountChange > 0 ? '+' : ''}${headcountChange} in the last 30 days`,
                              }
                    }
                />
                <StatCard
                    floating
                    href="/hr/timekeeping"
                    label="Present Today"
                    value={attendanceToday.present}
                    icon={UserCheck}
                    tone="success"
                    hint={`of ${attendanceToday.expected} scheduled`}
                />
                {/* Being on approved leave is not a fault, so this stays
                    informational rather than a warning. */}
                <StatCard
                    floating
                    href="/hr/leave"
                    label="On Leave Today"
                    value={leaveToday.count}
                    icon={CalendarDays}
                    tone="info"
                    hint={leaveToday.count > 0 ? leaveToday.summary : 'Nobody is away'}
                />
                <StatCard
                    floating
                    href="/hr/payroll"
                    label="Latest Payroll"
                    // The company's total net is HR's figure. An employee gets
                    // the tile with an em dash rather than a tile that is
                    // missing, so the grid keeps its four columns.
                    value={can?.viewCompanyFigures ? formatCurrency(payroll.total_net) : '—'}
                    icon={Wallet}
                    tone="primary"
                    hint={
                        can?.viewCompanyFigures
                            ? (payroll.period ?? 'No payroll run yet')
                            : 'Visible to HR'
                    }
                />
            </div>

            {/* Operational detail */}
            <div className="mb-5 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                <SplitStatCard
                    floating
                    href="/hr/timekeeping"
                    label="Today's Attendance"
                    icon={Clock}
                    tone="success"
                    stats={[
                        { label: 'Present', value: attendanceToday.present, tone: 'success' },
                        { label: 'Late', value: attendanceToday.late, tone: 'warning' },
                        { label: 'Absent', value: attendanceToday.absent, tone: 'destructive' },
                    ]}
                />

                <MeterCard
                    floating
                    href="/hr/timekeeping/reports"
                    label="Attendance Rate"
                    value={`${attendanceToday.rate}%`}
                    percent={attendanceToday.rate}
                    icon={UserCheck}
                    iconTone="success"
                    tone={gradeForPercent(attendanceToday.rate)}
                    hint={`${attendanceToday.present} of ${attendanceToday.expected} on duty`}
                />

                {/* The bar takes the band's own colour, so the meter and the
                    badge cannot disagree about how the score reads. */}
                <MeterCard
                    floating
                    href="/hr/performance"
                    label="Avg Performance"
                    value={statistics.average_rating?.toFixed(2) ?? '—'}
                    percent={((statistics.average_rating ?? 0) / 5) * 100}
                    icon={ClipboardCheck}
                    iconTone="primary"
                    tone={statistics.performance_band_variant ?? 'primary'}
                    badge={statistics.performance_band ?? undefined}
                    hint="Latest completed review cycle"
                />

                {/* Anything waiting on a decision is a warning, not a fault —
                    and each drops to grey at zero, so an empty queue is quiet. */}
                {/* The one tile that spans three modules. Leave is where most
                    of the queue sits and where an approver goes first, so it
                    is the destination — the other two are a click further on
                    rather than unreachable. */}
                <SplitStatCard
                    floating
                    href="/hr/leave"
                    label="Awaiting Approval"
                    icon={CalendarClock}
                    tone="warning"
                    stats={[
                        { label: 'Leave', value: approvals.leave, tone: 'warning' },
                        { label: 'Overtime', value: approvals.overtime, tone: 'warning' },
                        { label: 'Reviews', value: approvals.reviews, tone: 'warning' },
                    ]}
                />
            </div>

            {/* Charts */}
            <div className="mb-5 grid gap-5 lg:grid-cols-3">
                <Card floating className="lg:col-span-2">
                    <CardHeader
                        title="Headcount Trend"
                        description="Active employees at each month end."
                        action={
                            trendDelta(headcountTrend) === null ? undefined : (
                                <Badge
                                    variant={
                                        trendDelta(headcountTrend) >= 0
                                            ? 'success'
                                            : 'destructive'
                                    }
                                >
                                    {trendDelta(headcountTrend) >= 0 ? '+' : ''}
                                    {trendDelta(headcountTrend)} over 12 months
                                </Badge>
                            )
                        }
                    />
                    <CardBody>
                        <TrendChart data={headcountTrend} valueLabel="Active headcount" />
                    </CardBody>
                </Card>

                <Card floating>
                    <CardHeader
                        title="Employment Status"
                        description="Across all records."
                        action={
                            <div className="text-right">
                                <p className="text-xl font-semibold tabular-nums leading-none text-foreground">
                                    {statistics.active}
                                </p>
                                <p className="mt-1 text-[10px] uppercase tracking-wide text-muted-foreground">
                                    Active
                                </p>
                            </div>
                        }
                    />
                    <CardBody>
                        <StatusDonut data={statusMix} />
                    </CardBody>
                </Card>
            </div>

            {/* Headcount by department keeps its own card — it is a different
                question from the trend above (who, not when). */}
            <div
                className={cn(
                    'mb-5 grid gap-5',
                    companyCards === 0 ? 'lg:grid-cols-1' : 'lg:grid-cols-3',
                )}
            >
                {leaveSummary && (
                    <Card floating className="lg:col-span-1">
                        <CardHeader
                            title="Leave Requests"
                            description="Filed this month."
                            action={
                                <Link
                                    href="/hr/leave"
                                    className="text-xs font-medium text-primary hover:underline"
                                >
                                    View all
                                </Link>
                            }
                        />
                        <CardBody>
                            <div className="flex gap-2">
                                <StatTile
                                    label="Pending"
                                    value={leaveSummary.pending}
                                    tone="warning"
                                />
                                <StatTile
                                    label="Approved"
                                    value={leaveSummary.approved}
                                    tone="success"
                                />
                                <StatTile
                                    label="Rejected"
                                    value={leaveSummary.rejected}
                                    tone="destructive"
                                />
                            </div>

                            <TilePreview
                                icon={CalendarDays}
                                tone="info"
                                title={leaveSummary.latest?.title}
                                subtitle={leaveSummary.latest?.subtitle}
                                badge={
                                    leaveSummary.latest && (
                                        <Badge status={leaveSummary.latest.status} />
                                    )
                                }
                                empty="No leave has been filed yet."
                            />
                        </CardBody>
                    </Card>
                )}

                {payrollSummary && (
                    <Card floating>
                        <CardHeader
                            title="Payroll"
                            description="Runs by stage."
                            action={
                                <Link
                                    href="/hr/payroll"
                                    className="text-xs font-medium text-primary hover:underline"
                                >
                                    View all
                                </Link>
                            }
                        />
                        <CardBody>
                            <div className="flex gap-2">
                                <StatTile
                                    label="Draft"
                                    value={payrollSummary.draft}
                                    tone="muted"
                                />
                                <StatTile
                                    label="For Approval"
                                    value={payrollSummary.for_approval}
                                    tone="warning"
                                />
                                <StatTile
                                    label="Released"
                                    value={payrollSummary.released}
                                    tone="success"
                                />
                            </div>

                            <TilePreview
                                icon={Wallet}
                                tone="primary"
                                title={payrollSummary.latest?.title}
                                subtitle={payrollSummary.latest?.subtitle}
                                badge={
                                    payrollSummary.latest && (
                                        <Badge status={payrollSummary.latest.status} />
                                    )
                                }
                                empty="No payroll run yet."
                            />
                        </CardBody>
                    </Card>
                )}

                <Card floating>
                    <CardHeader
                        title="201 File Health"
                        description="What needs filing."
                        action={
                            <Link
                                href="/hr/onboarding"
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                View all
                            </Link>
                        }
                    />
                    <CardBody>
                        <div className="flex gap-2">
                            <StatTile
                                label="New Hires"
                                value={onboardingSummary.new_hires}
                                tone="info"
                            />
                            <StatTile
                                label="Expiring"
                                value={onboardingSummary.expiring}
                                tone="warning"
                            />
                            <StatTile
                                label="No Documents"
                                value={onboardingSummary.without_documents}
                                tone="destructive"
                            />
                        </div>

                        <TilePreview
                            icon={UserPlus}
                            tone="success"
                            title={onboardingSummary.latest?.title}
                            subtitle={onboardingSummary.latest?.subtitle}
                            badge={
                                onboardingSummary.latest && (
                                    <Badge status={onboardingSummary.latest.status} />
                                )
                            }
                            empty="No employees on file yet."
                        />
                    </CardBody>
                </Card>
            </div>

            <div className="mb-5">
                <Card floating>
                    <CardHeader
                        title="Active Headcount by Department"
                        description="Employees with an active record."
                        action={
                            <Link
                                href="/hr/departments"
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                View departments
                            </Link>
                        }
                    />
                    <CardBody>
                        <HeadcountChart data={headcountByDepartment} />
                    </CardBody>
                </Card>
            </div>

            {/* Recent activity */}
            <Card floating>
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
