import { Link } from '@inertiajs/react';
import { CalendarClock, UserCheck, UserPlus, Users, UserX } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardBody, CardHeader, StatCard } from '@/Components/ui';
import { formatDate, initials } from '@/lib/utils';

/**
 * Active headcount by department.
 *
 * One measure across categories, so this is a single-series magnitude chart:
 * every bar wears the same hue (--chart-1) and there is no legend — colour here
 * would encode nothing. Counts are direct-labelled, so identity never rests on
 * colour alone.
 */
function HeadcountChart({ data }) {
    const max = Math.max(...data.map((row) => row.count), 1);
    const total = data.reduce((sum, row) => sum + row.count, 0);

    if (data.length === 0) {
        return <p className="text-sm text-muted-foreground">No departments configured yet.</p>;
    }

    return (
        <div className="space-y-3">
            {data.map((row) => {
                const share = total > 0 ? Math.round((row.count / total) * 100) : 0;

                return (
                    <div
                        key={row.name}
                        className="group grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3"
                        title={`${row.name}: ${row.count} active (${share}% of headcount)`}
                    >
                        <span className="truncate text-xs text-muted-foreground">
                            {row.name}
                        </span>

                        {/* Track is recessive; the fill is the only saturated mark. */}
                        <span className="h-2.5 w-full overflow-hidden rounded-sm bg-muted">
                            <span
                                className="block h-full rounded-r-[4px] bg-chart-1 transition-[width] duration-500 group-hover:opacity-90"
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

export default function Dashboard({ statistics, headcountByDepartment, recentHires }) {
    const stats = [
        { label: 'Total Employees', value: statistics.total, icon: Users },
        { label: 'Active', value: statistics.active, icon: UserCheck },
        { label: 'On Leave', value: statistics.on_leave, icon: CalendarClock },
        { label: 'Probationary', value: statistics.probationary, icon: UserX },
    ];

    return (
        <AppLayout title="Dashboard" breadcrumbs={[{ label: 'Overview' }]}>
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {stats.map((stat) => (
                    <StatCard key={stat.label} {...stat} />
                ))}
            </div>

            <div className="grid gap-5 lg:grid-cols-2">
                <Card>
                    <CardHeader
                        title="Active Headcount by Department"
                        description="Employees with an active record."
                    />
                    <CardBody>
                        <HeadcountChart data={headcountByDepartment} />
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader
                        title="Recent Hires"
                        description="Latest five employees onboarded."
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
                            <ul className="space-y-1">
                                {recentHires.map((hire) => (
                                    <li key={hire.id}>
                                        <Link
                                            href={`/hr/employees/${hire.id}`}
                                            className="flex items-center gap-3 rounded-md p-2 transition-colors hover:bg-secondary/60"
                                        >
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
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

                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                {formatDate(hire.date_hired)}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardBody>
                </Card>
            </div>
        </AppLayout>
    );
}
