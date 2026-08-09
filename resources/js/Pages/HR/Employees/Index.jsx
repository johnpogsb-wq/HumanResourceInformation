import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { CalendarClock, Plus, UserCheck, Users, UserX } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    Pagination,
    SearchInput,
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
    String(value)
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const EMPLOYMENT_STATUSES = [
    'regular',
    'probationary',
    'contractual',
    'project-based',
    'resigned',
    'terminated',
];

export default function Index({ employees, statistics, departments, filters, sort, can }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const isFirstRender = useRef(true);

    // Debounce the search box so typing does not fire a request per keystroke.
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                '/hr/employees',
                { ...filters, search: search || undefined },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 350);

        return () => clearTimeout(timer);
        // `filters` is server state and stable between renders for a given page.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const applyFilter = (key, value) => {
        router.get(
            '/hr/employees',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const applySort = (key) => {
        const direction = sort.key === key && sort.direction === 'asc' ? 'desc' : 'asc';

        router.get(
            '/hr/employees',
            { ...filters, sort: key, direction },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const rows = employees.data ?? [];
    const meta = employees.meta ?? {};
    // `employees.links` is the {first,last,prev,next} object — the numbered page
    // links live on meta.links.
    const links = meta.links ?? [];

    const stats = useMemo(
        () => [
            { label: 'Total Employees', value: statistics.total, icon: Users },
            { label: 'Active', value: statistics.active, icon: UserCheck },
            { label: 'On Leave', value: statistics.on_leave, icon: CalendarClock },
            { label: 'Probationary', value: statistics.probationary, icon: UserX },
        ],
        [statistics],
    );

    return (
        <AppLayout
            title="Employee Information"
            breadcrumbs={[{ label: 'Human Resource' }, { label: 'Employee Information' }]}
            actions={
                can.create && (
                    <Button href="/hr/employees/create" size="sm">
                        <Plus className="h-4 w-4" />
                        <span className="hidden sm:inline">Add Employee</span>
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
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center">
                    <div className="lg:w-72">
                        <SearchInput
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search name, number, or email…"
                            aria-label="Search employees"
                        />
                    </div>

                    <div className="flex flex-1 flex-wrap gap-2 lg:justify-end">
                        <Select
                            value={filters.department_id ?? ''}
                            onChange={(event) =>
                                applyFilter('department_id', event.target.value)
                            }
                            placeholder="All departments"
                            className="w-full sm:w-48"
                            aria-label="Filter by department"
                            options={departments.map((department) => ({
                                value: department.id,
                                label: department.name,
                            }))}
                        />

                        <Select
                            value={filters.employment_status ?? ''}
                            onChange={(event) =>
                                applyFilter('employment_status', event.target.value)
                            }
                            placeholder="All employment statuses"
                            className="w-full sm:w-52"
                            aria-label="Filter by employment status"
                            options={EMPLOYMENT_STATUSES.map((status) => ({
                                value: status,
                                label: titleCase(status),
                            }))}
                        />

                        <Select
                            value={filters.status ?? ''}
                            onChange={(event) => applyFilter('status', event.target.value)}
                            placeholder="All record statuses"
                            className="w-full sm:w-44"
                            aria-label="Filter by record status"
                            options={[
                                { value: 'active', label: 'Active' },
                                { value: 'on_leave', label: 'On Leave' },
                                { value: 'inactive', label: 'Inactive' },
                            ]}
                        />
                    </div>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH sortKey="employee_number" sort={sort} onSort={applySort}>
                                Employee
                            </TH>
                            <TH>Department</TH>
                            <TH>Position</TH>
                            <TH sortKey="employment_status" sort={sort} onSort={applySort}>
                                Employment
                            </TH>
                            <TH sortKey="date_hired" sort={sort} onSort={applySort}>
                                Date Hired
                            </TH>
                            <TH sortKey="status" sort={sort} onSort={applySort}>
                                Status
                            </TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                title="No employees found"
                                description="Try adjusting your search or filters, or add a new employee record."
                            />
                        ) : (
                            rows.map((employee) => (
                                <TR key={employee.id}>
                                    <TD>
                                        <Link
                                            href={`/hr/employees/${employee.id}`}
                                            className="group flex items-center gap-3"
                                        >
                                            {employee.photo_url ? (
                                                <img
                                                    src={employee.photo_url}
                                                    alt=""
                                                    className="h-9 w-9 shrink-0 rounded-full object-cover"
                                                />
                                            ) : (
                                                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                                    {initials(employee.full_name)}
                                                </span>
                                            )}

                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-medium text-foreground group-hover:text-primary">
                                                    {employee.full_name}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {employee.employee_number}
                                                </span>
                                            </span>
                                        </Link>
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {employee.department?.name ?? '—'}
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {employee.position?.title ?? '—'}
                                    </TD>

                                    <TD>
                                        <Badge status={employee.employment_status} />
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(employee.date_hired)}
                                    </TD>

                                    <TD>
                                        <Badge status={employee.status} />
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={links} meta={meta} />
            </Card>
        </AppLayout>
    );
}
