import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Building2, Handshake, Mail, Phone, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge, Card, CardBody, CardHeader, SearchInput, StatCard } from '@/Components/ui';
import { initials } from '@/lib/utils';

/**
 * One colleague, as the rest of the company sees them.
 *
 * Everything on this card is work-facing — a name, a job, where they are
 * posted, and how to reach them. There is no salary, no government number,
 * no address, and no link into the 201 file, and that is what makes the
 * screen safe to open to everybody rather than to HR alone.
 */
function PersonCard({ person }) {
    return (
        <div className="flex min-w-0 items-start gap-3 rounded-lg border border-border bg-card p-3 transition-colors hover:border-primary/30">
            {person.photo_url ? (
                <img
                    src={person.photo_url}
                    alt=""
                    className="h-10 w-10 shrink-0 rounded-full object-cover"
                />
            ) : (
                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                    {initials(person.full_name)}
                </span>
            )}

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-foreground">
                    {person.full_name}
                </p>

                <p className="truncate font-mono text-[11px] text-muted-foreground">
                    {person.employee_number}
                </p>

                {/* Internal staff or deployed, and to whom. For a manpower
                    agency that is half of "who are you". */}
                <div className="mt-1.5">
                    {person.employment_category === 'external' && person.client ? (
                        <span className="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                            <Handshake className="h-3 w-3 shrink-0" aria-hidden="true" />
                            <span className="truncate">{person.client}</span>
                        </span>
                    ) : (
                        <span className="text-[11px] text-muted-foreground">
                            Internal staff
                        </span>
                    )}
                </div>

                {/* A directory that cannot be used to reach anybody is a list. */}
                <div className="mt-2 space-y-0.5">
                    {person.email && (
                        <a
                            href={`mailto:${person.email}`}
                            className="flex items-center gap-1.5 text-[11px] text-muted-foreground transition-colors hover:text-primary"
                        >
                            <Mail className="h-3 w-3 shrink-0" aria-hidden="true" />
                            <span className="truncate">{person.email}</span>
                        </a>
                    )}
                    {person.mobile_number && (
                        <a
                            href={`tel:${person.mobile_number}`}
                            className="flex items-center gap-1.5 text-[11px] text-muted-foreground transition-colors hover:text-primary"
                        >
                            <Phone className="h-3 w-3 shrink-0" aria-hidden="true" />
                            {person.mobile_number}
                        </a>
                    )}
                </div>
            </div>
        </div>
    );
}

/** A department, with its people grouped under the job each one holds. */
function DepartmentBlock({ code, name, headcount, positions, icon: Icon = Building2 }) {
    if (positions.length === 0) return null;

    return (
        <Card className="mb-5">
            <CardHeader>
                <div className="flex min-w-0 items-center gap-2.5">
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
                    </span>
                    <div className="min-w-0">
                        <h3 className="truncate text-sm font-semibold text-foreground">
                            {name}
                        </h3>
                        {code && (
                            <p className="font-mono text-[11px] text-muted-foreground">
                                {code}
                            </p>
                        )}
                    </div>
                </div>

                <Badge variant="muted">
                    {headcount} {headcount === 1 ? 'person' : 'people'}
                </Badge>
            </CardHeader>

            <CardBody className="space-y-5">
                {positions.map((position) => (
                    <div key={position.title}>
                        {/* The job, not a heading for its own sake: somebody
                            looking for "a driver" is walking down the org
                            chart rather than reading 41 names. */}
                        <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                            {position.title}
                            <span className="ml-1.5 font-normal normal-case tracking-normal">
                                ({position.people.length})
                            </span>
                        </p>

                        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {position.people.map((person) => (
                                <PersonCard key={person.id} person={person} />
                            ))}
                        </div>
                    </div>
                ))}
            </CardBody>
        </Card>
    );
}

export default function Directory({ departments, unassigned, filters, total }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const first = useRef(true);

    // Debounced, so typing does not fire a request per keystroke. The first
    // render must not re-request what the server already sent.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return undefined;
        }

        const timer = setTimeout(() => {
            router.get(
                '/hr/directory',
                { search: search || undefined },
                { preserveState: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [search]);

    const shown = departments.reduce((sum, department) => sum + department.headcount, 0);

    return (
        <AppLayout title="Org Directory">
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="People Listed"
                    value={total}
                    icon={Users}
                    tone={total > 0 ? 'primary' : 'muted'}
                    hint="active staff only"
                />
                <StatCard
                    label="Departments"
                    value={departments.length}
                    icon={Building2}
                    tone={departments.length > 0 ? 'info' : 'muted'}
                    hint="with somebody in them"
                />
                {/* Not a fault — a new hire filed before their department was
                    decided is still somebody a colleague may need to reach. */}
                <StatCard
                    label="Not Yet Filed"
                    value={unassigned.headcount}
                    icon={Users}
                    tone={unassigned.headcount > 0 ? 'warning' : 'muted'}
                    hint="no department on record"
                />
            </div>

            <Card className="mb-5">
                <CardBody className="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div className="w-full sm:max-w-sm">
                        <SearchInput
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search a name, number, or email…"
                            aria-label="Search the directory"
                        />
                    </div>

                    <p className="text-xs text-muted-foreground sm:ml-auto">
                        {search
                            ? `${shown + unassigned.headcount} matching`
                            : 'Names, roles, and work contacts only.'}
                    </p>
                </CardBody>
            </Card>

            {departments.map((department) => (
                <DepartmentBlock key={department.id} {...department} />
            ))}

            {unassigned.headcount > 0 && (
                <DepartmentBlock
                    name="No department on file"
                    code={null}
                    headcount={unassigned.headcount}
                    positions={unassigned.positions}
                    icon={Users}
                />
            )}

            {shown === 0 && unassigned.headcount === 0 && (
                <Card>
                    <CardBody className="py-14 text-center">
                        <p className="text-sm font-medium text-foreground">
                            {search ? 'Nobody matches that search' : 'Nobody to list yet'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {search
                                ? 'Try a surname, an employee number, or an email address.'
                                : 'Active employees appear here once they are on the roster.'}
                        </p>
                    </CardBody>
                </Card>
            )}
        </AppLayout>
    );
}
