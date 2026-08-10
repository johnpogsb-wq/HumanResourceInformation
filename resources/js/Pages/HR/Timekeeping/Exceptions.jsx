import { router } from '@inertiajs/react';
import { AlertTriangle, ShieldCheck, TriangleAlert, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
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

export default function Exceptions({ exceptions, summary, filters, departments, types }) {
    const applyFilter = (key, value) => {
        router.get(
            '/hr/timekeeping/exceptions',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const stats = [
        {
            label: 'Flagged Records',
            value: summary.total,
            icon: TriangleAlert,
            hint: `${summary.employees_flagged} employee(s) involved`,
        },
        {
            label: 'Critical',
            value: summary.critical,
            icon: AlertTriangle,
            hint: 'Missing punches, frequent absence',
        },
        {
            label: 'Warning',
            value: summary.warning,
            icon: ShieldCheck,
            hint: 'Worth a second look',
        },
        {
            label: 'Employees Flagged',
            value: summary.employees_flagged,
            icon: Users,
        },
    ];

    return (
        <AppLayout
            title="Timekeeping & Attendance"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Timekeeping', href: '/hr/timekeeping' },
                { label: 'Exceptions' },
            ]}
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

                    <Field label="Exception Type" className="w-full sm:w-56">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.type ?? ''}
                                onChange={(event) => applyFilter('type', event.target.value)}
                                placeholder="All types"
                                options={types}
                            />
                        )}
                    </Field>

                    <p className="pb-2 text-xs text-muted-foreground lg:ml-auto">
                        Scanned {formatDate(filters.from)} – {formatDate(filters.to)}
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Type</TH>
                            <TH>Date</TH>
                            <TH>Detail</TH>
                            <TH>Severity</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {exceptions.length === 0 ? (
                            <TableEmpty
                                colSpan={5}
                                icon={ShieldCheck}
                                title="Nothing flagged"
                                description="No missing punches, unusual hours, or attendance patterns in this range."
                            />
                        ) : (
                            exceptions.map((item, index) => (
                                <TR
                                    key={`${item.type}-${item.employee_id}-${item.log_date}-${index}`}
                                >
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(item.employee_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {item.employee_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {item.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD>
                                        <Badge variant="muted">
                                            {types.find((type) => type.value === item.type)
                                                ?.label ?? item.type}
                                        </Badge>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {item.log_date ? formatDate(item.log_date) : '—'}
                                    </TD>

                                    <TD className="max-w-md text-sm text-foreground">
                                        {item.detail}
                                    </TD>

                                    <TD>
                                        <Badge
                                            variant={
                                                item.severity === 'critical'
                                                    ? 'destructive'
                                                    : 'warning'
                                            }
                                        >
                                            {item.severity}
                                        </Badge>
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
