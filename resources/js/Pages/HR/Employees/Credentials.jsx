import { Link, router } from '@inertiajs/react';
import { BadgeCheck, CalendarClock, ShieldAlert, ShieldX } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Card,
    Field,
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

/** "in 12 days" reads faster than a date the reader has to subtract from today. */
function countdown(days) {
    if (days < 0) return `${Math.abs(days)} day(s) overdue`;
    if (days === 0) return 'Today';

    return `in ${days} day(s)`;
}

export default function Credentials({
    credentials,
    summary,
    filters,
    departments,
    types,
    statuses,
}) {
    const applyFilter = (key, value) => {
        router.get(
            '/hr/credentials',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const stats = [
        {
            label: 'Needs Attention',
            value: summary.total,
            icon: CalendarClock,
            hint: 'Lapsed or inside its renewal window',
        },
        {
            label: 'Already Expired',
            value: summary.expired,
            icon: ShieldX,
            hint: 'No longer valid',
        },
        {
            label: 'Expiring Soon',
            value: summary.expiring,
            icon: ShieldAlert,
            hint: 'Still time to renew',
        },
        {
            label: 'Stops Work',
            value: summary.blocking,
            icon: ShieldX,
            hint: "Licence or medical — can't legally work",
        },
    ];

    return (
        <AppLayout
            title="Credential Expiry"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Credentials' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {stats.map((stat) => (
                    <StatCard key={stat.label} {...stat} />
                ))}
            </div>

            <Card>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:flex-wrap lg:items-end">
                    <Field label="Status" className="w-full sm:w-44">
                        {({ id }) => (
                            <Select
                                id={id}
                                value={filters.status ?? ''}
                                onChange={(event) => applyFilter('status', event.target.value)}
                                placeholder="All"
                                options={statuses}
                            />
                        )}
                    </Field>

                    <Field label="Document Type" className="w-full sm:w-52">
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

                    <Field label="Department" className="w-full sm:w-52">
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
                        Renewal windows are set per document type in configuration.
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH>Document</TH>
                            <TH>Expires</TH>
                            <TH>Countdown</TH>
                            <TH>Status</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {credentials.length === 0 ? (
                            <TableEmpty
                                colSpan={5}
                                icon={BadgeCheck}
                                title="Everything is in date"
                                description="No licence, medical, or clearance is inside its renewal window."
                            />
                        ) : (
                            credentials.map((item) => (
                                <TR key={item.id}>
                                    <TD>
                                        <Link
                                            href={`/hr/employees/${item.employee_id}`}
                                            className="flex items-center gap-2.5"
                                        >
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(item.employee_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground hover:underline">
                                                    {item.employee_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {item.employee_number}
                                                    {item.department
                                                        ? ` · ${item.department}`
                                                        : ''}
                                                </p>
                                            </div>
                                        </Link>
                                    </TD>

                                    <TD>
                                        <p className="text-sm text-foreground">{item.title}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {item.type_label}
                                            {item.blocking && ' · required to work'}
                                        </p>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(item.expires_at)}
                                    </TD>

                                    <TD
                                        className={`whitespace-nowrap text-sm ${
                                            item.days_remaining < 0
                                                ? 'font-medium text-destructive'
                                                : 'text-muted-foreground'
                                        }`}
                                    >
                                        {countdown(item.days_remaining)}
                                    </TD>

                                    <TD>
                                        <Badge
                                            variant={
                                                item.status === 'expired'
                                                    ? 'destructive'
                                                    : 'warning'
                                            }
                                        >
                                            {item.status === 'expired'
                                                ? 'Expired'
                                                : 'Expiring soon'}
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
