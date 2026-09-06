import { Link, router, WhenVisible } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    Building2,
    CalendarClock,
    Inbox,
    Loader2,
    ScanLine,
    UserCheck,
    Users,
    UserX,
    Upload,
    X,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Card,
    Button,
    SearchInput,
    Select,
    MeterCard,
    StatCard,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatDate, initials, withFilters } from '@/lib/utils';

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

/**
 * How a filter the dropdowns cannot show describes itself.
 *
 * Each of these is set by a dashboard tile and has no control on this screen:
 * `hired_within` is a rolling window, `without_documents` is the absence of a
 * relationship, and a comma-separated `employment_status` is the donut's
 * grouping — "Contractual" there is contractual *and* project-based, which no
 * single dropdown option can say.
 *
 * A list narrowed by a filter with no visible control is a list nobody can
 * explain, so each draws a removable chip instead.
 */
function tileFilterLabel(filters) {
    if (filters.hired_within) {
        return {
            key: 'hired_within',
            label: `Hired in the last ${filters.hired_within} days`,
        };
    }

    if (filters.without_documents) {
        return { key: 'without_documents', label: 'No documents on file' };
    }

    if (String(filters.employment_status ?? '').includes(',')) {
        return {
            key: 'employment_status',
            label: filters.employment_status.split(',').map(titleCase).join(' or '),
        };
    }

    return null;
}

export default function Index({
    employees,
    statistics,
    departments,
    clients,
    filters,
    sort,
    can,
    pendingEndorsements = 0,
}) {
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
    const hasMore = (meta.current_page ?? 1) < (meta.last_page ?? 1);
    const tileFilter = tileFilterLabel(filters);

    /*
     * Clicking a figure opens the rows it counted, keeping whatever the
     * screen is already narrowed to. `status` and `employment_status` are
     * cleared together: they are two dropdowns over the same list, and a tile
     * that set one while leaving the other behind would return the people who
     * are both — usually nobody.
     *
     * The two dashboard-set keys are cleared with them, for the same reason:
     * arriving on "no documents on file" and then clicking "Total Employees"
     * has to give the whole list back, not the whole list still narrowed to
     * the people with an empty 201 file.
     */
    const drillTo = (changes) =>
        withFilters('/hr/employees', filters, changes, [
            'status',
            'employment_status',
            'hired_within',
            'without_documents',
        ]);

    // 38 of 41 is the reading; 38 on its own is a number whose scale the
    // reader has to go and find.
    const activeRate = statistics.total > 0 ? (statistics.active / statistics.total) * 100 : 0;

    return (
        <AppLayout
            title="Employee Information"
            breadcrumbs={[{ label: 'Human Resource' }, { label: 'Employee Information' }]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {/* The whole list. Clicking it clears the narrowing filters
                    rather than adding one — it is the way back out. */}
                <StatCard
                    label="Total Employees"
                    value={statistics.total}
                    icon={Users}
                    tone="primary"
                    hint={filters.search ? 'matching this search' : 'on the books'}
                    href={drillTo({})}
                />

                {/* A share, because "38 active" says nothing until you know
                    whether the roster is 41 or 400. */}
                <MeterCard
                    label="Active"
                    value={statistics.active}
                    percent={activeRate}
                    badge={`${Math.round(activeRate)}%`}
                    icon={UserCheck}
                    tone={activeRate >= 90 ? 'success' : 'warning'}
                    iconTone="success"
                    hint={`of ${statistics.total} on the books`}
                    href={drillTo({ status: 'active' })}
                />

                {/* Info, not warning — an approved absence is not a fault.
                    Grey at zero, like every other tile in the system. */}
                <StatCard
                    label="On Leave"
                    value={statistics.on_leave}
                    icon={CalendarClock}
                    tone={statistics.on_leave > 0 ? 'info' : 'muted'}
                    hint="away today, still on the roster"
                    href={drillTo({ status: 'on_leave' })}
                />

                {/* Not a problem, a clock: every one of these is a
                    regularisation date somebody has to act on. */}
                <StatCard
                    label="Probationary"
                    value={statistics.probationary}
                    icon={UserX}
                    tone={statistics.probationary > 0 ? 'warning' : 'muted'}
                    hint="awaiting regularisation"
                    href={drillTo({ employment_status: 'probationary' })}
                />
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
                        {/* The agency split comes first: it is the widest cut
                            of the workforce, and the client filter beside it
                            only means anything for external staff. */}
                        <Select
                            value={filters.employment_category ?? ''}
                            onChange={(event) =>
                                applyFilter('employment_category', event.target.value)
                            }
                            placeholder="All staff"
                            className="w-full sm:w-40"
                            aria-label="Filter by internal or external"
                            options={[
                                { value: 'internal', label: 'Internal Staff' },
                                { value: 'external', label: 'External (Deployed)' },
                            ]}
                        />

                        <Select
                            value={filters.client_id ?? ''}
                            onChange={(event) => applyFilter('client_id', event.target.value)}
                            placeholder="All clients"
                            className="w-full sm:w-52"
                            aria-label="Filter by client"
                            options={clients.map((client) => ({
                                value: client.id,
                                // The headcount is the figure the agency is
                                // asked for; showing it here saves opening the
                                // filter five times to compare.
                                label: `${client.name} (${client.employees_count})`,
                            }))}
                        />

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

                        {/* What a dashboard tile asked for, said out loud. */}
                        {tileFilter && (
                            <button
                                type="button"
                                onClick={() => applyFilter(tileFilter.key, '')}
                                className="flex h-9 shrink-0 items-center gap-1.5 self-end rounded-full border border-info/30 bg-info/10 px-3 text-xs font-medium text-info transition-colors hover:bg-info/20"
                            >
                                {tileFilter.label}
                                <X className="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                        )}

                        {/* Two ways in, beside each other: a spreadsheet of a
                            workforce that already exists, and a stack of scans
                            for the files they arrive with. Splitting them
                            across the screen would make the bulk paths look
                            like separate features rather than the same act at
                            scale.

                            There is no "Add Employee" beside them any more.
                            PrimePower does not hire into this system directly —
                            Core 1 recruits and sends the hire over, and it is
                            approved on the endorsements screen. Leaving a
                            direct button here would have been a second way in
                            that keeps no record of who accepted the person or
                            why, which is the whole thing the handover exists to
                            record. Import stays because digitising a workforce
                            that already works here is not hiring: there is no
                            endorsement for somebody on their sixth year. */}
                        {can.create && (
                            <Button variant="outline" href="/hr/employees/import">
                                <Upload className="h-4 w-4" />
                                Import
                            </Button>
                        )}

                        {can.fileDocuments && (
                            <Button variant="outline" href="/hr/employees/documents/batch">
                                <ScanLine className="h-4 w-4" />
                                File Scans
                            </Button>
                        )}

                        {can.create && (
                            <Button href="/hr/endorsements">
                                <Inbox className="h-4 w-4" />
                                New Hires
                                {pendingEndorsements > 0 && (
                                    <span className="ml-0.5 grid min-w-5 place-items-center rounded-full bg-primary-foreground/20 px-1.5 text-[11px] font-semibold leading-5">
                                        {pendingEndorsements}
                                    </span>
                                )}
                            </Button>
                        )}
                    </div>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH sortKey="employee_number" sort={sort} onSort={applySort}>
                                Employee
                            </TH>
                            <TH>Assignment</TH>
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
                            <>
                                {rows.map((employee) => (
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

                                        {/* One column, two meanings: an
                                            internal employee belongs to a
                                            department, a deployed one belongs
                                            to a client. Showing both as
                                            separate columns would leave one
                                            of them blank on every row. */}
                                        <TD className="text-sm">
                                            {employee.client ? (
                                                <span className="flex items-center gap-1.5">
                                                    <Building2
                                                        className="h-3.5 w-3.5 shrink-0 text-primary"
                                                        aria-hidden="true"
                                                    />
                                                    <span className="truncate text-foreground">
                                                        {employee.client.name}
                                                    </span>
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    {employee.department?.name ?? '—'}
                                                </span>
                                            )}
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
                                ))}

                                {/* Scrolling this row into view fetches the next
                                    page and appends it above — infinite scroll
                                    instead of numbered pages. It disappears once
                                    the last page has loaded. */}
                                {hasMore && (
                                    <WhenVisible
                                        as="tr"
                                        always
                                        data="employees"
                                        params={{
                                            data: { page: (meta.current_page ?? 1) + 1 },
                                            // Otherwise each scroll-triggered
                                            // fetch pushes ?page=2, ?page=3…
                                            // onto the URL and browser history —
                                            // one Back press per page loaded,
                                            // and a refresh mid-scroll would
                                            // render only that lone page instead
                                            // of everything loaded so far.
                                            preserveUrl: true,
                                        }}
                                    >
                                        {({ fetching }) => (
                                            <TD colSpan={6} className="py-4 text-center">
                                                {fetching && (
                                                    <span className="inline-flex items-center gap-2 text-xs text-muted-foreground">
                                                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                        Loading more…
                                                    </span>
                                                )}
                                            </TD>
                                        )}
                                    </WhenVisible>
                                )}
                            </>
                        )}
                    </TBody>
                </Table>

                {!hasMore && rows.length > 0 && (
                    <p className="border-t border-border px-4 py-3 text-center text-xs text-muted-foreground">
                        {rows.length} of {meta.total} employee(s)
                    </p>
                )}
            </Card>
        </AppLayout>
    );
}
