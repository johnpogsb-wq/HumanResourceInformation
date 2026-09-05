import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { CheckCircle2, Inbox, Radio, XCircle } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
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
import { formatDate } from '@/lib/utils';

const STATUS_FILTERS = [
    { value: 'pending', label: 'Awaiting decision' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Declined' },
    { value: 'all', label: 'All' },
];

/**
 * Hires proposed by Core 1, waiting on a decision here.
 *
 * Core 1 recruits; this system employs. Nobody reaches the payroll without
 * somebody on this screen saying yes, which is why there is no "add" button
 * anywhere on it — an endorsement arrives over the API or it does not arrive.
 */
export default function Index({ endorsements, filters, statistics }) {
    const [search, setSearch] = useState(filters.search ?? '');

    // Debounced so typing does not fire a request per keystroke. The first
    // render must not re-request what the server already sent.
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;

            return undefined;
        }

        const timer = setTimeout(() => {
            router.get(
                '/hr/endorsements',
                { search: search || undefined, status: filters.status },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [search]);

    const applyStatus = (status) => {
        router.get(
            '/hr/endorsements',
            { search: search || undefined, status },
            { preserveState: true, replace: true },
        );
    };

    const rows = endorsements.data ?? [];

    return (
        <AppLayout title="Endorsements from Core 1">
            <div className="mb-5 grid gap-5 sm:grid-cols-3">
                <StatCard
                    label="Awaiting decision"
                    value={statistics.pending}
                    icon={Inbox}
                    /* Zero drops to grey by itself: an empty queue is the good
                       outcome, and an amber "0 waiting" reads as a problem. */
                    tone={statistics.pending > 0 ? 'warning' : 'muted'}
                    href="/hr/endorsements?status=pending"
                    floating
                />
                <StatCard
                    label="Approved"
                    value={statistics.approved}
                    icon={CheckCircle2}
                    tone={statistics.approved > 0 ? 'success' : 'muted'}
                    href="/hr/endorsements?status=approved"
                    floating
                />
                <StatCard
                    label="Declined"
                    value={statistics.rejected}
                    icon={XCircle}
                    tone={statistics.rejected > 0 ? 'muted' : 'muted'}
                    href="/hr/endorsements?status=rejected"
                    floating
                />
            </div>

            <Card floating>
                <div className="flex flex-col gap-3 border-b border-border p-4 lg:flex-row lg:items-center">
                    <div className="w-full lg:max-w-xs">
                        <SearchInput
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search name, reference, or email…"
                        />
                    </div>

                    <div className="w-full lg:max-w-[13rem]">
                        <Select
                            value={filters.status}
                            onChange={(event) => applyStatus(event.target.value)}
                            options={STATUS_FILTERS}
                        />
                    </div>

                    {/* No create action, and that is the screen's whole point:
                        an endorsement is written by Core 1 over the API, never
                        typed here. A button would make this a second way to
                        hire and quietly undo the handover. */}
                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground lg:ml-auto">
                        <Radio className="h-3.5 w-3.5" aria-hidden="true" />
                        Received from Core 1 over the API
                    </p>
                </div>

                <Table>
                    <THead>
                        <TR>
                            <TH>Candidate</TH>
                            <TH>Reference</TH>
                            <TH>Hired for</TH>
                            <TH>Start date</TH>
                            <TH>Received</TH>
                            <TH>Status</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 && (
                            <TableEmpty
                                colSpan={6}
                                icon={Inbox}
                                title={
                                    filters.status === 'pending'
                                        ? 'Nothing waiting'
                                        : 'No endorsements match this filter'
                                }
                                description={
                                    filters.status === 'pending'
                                        ? 'New hires appear here when Core 1 sends them over.'
                                        : undefined
                                }
                            />
                        )}

                        {rows.map((row) => (
                            <TR key={row.id}>
                                <TD>
                                    <Link
                                        href={`/hr/endorsements/${row.id}`}
                                        className="font-medium text-foreground hover:text-primary"
                                    >
                                        {row.full_name}
                                    </Link>
                                    {row.email && (
                                        <p className="text-xs text-muted-foreground">
                                            {row.email}
                                        </p>
                                    )}
                                </TD>

                                <TD>
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {row.reference}
                                    </span>
                                </TD>

                                <TD>
                                    <p className="text-sm text-foreground">
                                        {row.position_title ?? '—'}
                                    </p>
                                    {row.client_name && (
                                        <p className="text-xs text-muted-foreground">
                                            {row.client_name}
                                        </p>
                                    )}
                                </TD>

                                <TD>{row.date_hired ? formatDate(row.date_hired) : '—'}</TD>

                                <TD>{row.submitted_at ? formatDate(row.submitted_at) : '—'}</TD>

                                <TD>
                                    <Badge status={row.status}>{row.status}</Badge>

                                    {row.employee_number && (
                                        <Link
                                            href={`/hr/employees/${row.employee_id}`}
                                            className="mt-1 block font-mono text-xs text-muted-foreground hover:text-primary"
                                        >
                                            {row.employee_number}
                                        </Link>
                                    )}

                                    {row.status === 'rejected' && row.decided_by && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            by {row.decided_by}
                                        </p>
                                    )}
                                </TD>
                            </TR>
                        ))}
                    </TBody>
                </Table>

                <Pagination links={endorsements.meta?.links ?? []} meta={endorsements.meta} />
            </Card>
        </AppLayout>
    );
}
