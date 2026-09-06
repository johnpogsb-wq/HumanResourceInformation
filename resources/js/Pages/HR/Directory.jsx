import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    X,
    Building2,
    ChevronDown,
    ChevronRight,
    Handshake,
    Mail,
    Phone,
    ShieldAlert,
    Users,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { Badge, Card, CardBody, CardHeader, StatCard } from '@/Components/ui';
import { cn, initials } from '@/lib/utils';

/**
 * One colleague, as the rest of the company sees them.
 *
 * Everything on this card is work-facing — a name, a job, where they are
 * posted, and how to reach them. There is no salary, no government number,
 * no address, and no link into the 201 file, and that is what makes the
 * screen safe to open to everybody rather than to HR alone.
 */
function PersonRow({ person }) {
    const body = (
        <>
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
                <p
                    className={cn(
                        'truncate text-sm font-medium',
                        person.can_view
                            ? 'text-foreground group-hover:text-primary'
                            : 'text-foreground',
                    )}
                >
                    {person.full_name}
                </p>
                <p className="truncate font-mono text-[11px] text-muted-foreground">
                    {person.employee_number}
                </p>
            </div>

            {/* Where they are posted. For a manpower agency that is half of
                "who are you" — an internal clerk and a driver on a client site
                are different people to reach.

                Both readings carry an icon, "Internal staff" included. One
                line indented behind a symbol and the next starting flush left
                is what made this column read as debris rather than as a
                posting. */}
            <div className="hidden min-w-0 shrink-0 sm:block sm:w-40">
                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    {person.employment_category === 'external' && person.client ? (
                        <>
                            <Handshake
                                className="h-3.5 w-3.5 shrink-0 opacity-60"
                                aria-hidden="true"
                            />
                            <span className="truncate">{person.client}</span>
                        </>
                    ) : (
                        <>
                            <Building2
                                className="h-3.5 w-3.5 shrink-0 opacity-60"
                                aria-hidden="true"
                            />
                            <span className="truncate">Internal staff</span>
                        </>
                    )}
                </span>
            </div>

            {/* A directory that cannot be used to reach anybody is a list.

                Right-aligned with the icon trailing, so the two lines finish
                against one edge. Left-aligned they stopped wherever the address
                happened to end — a ragged margin two lines deep on every row,
                which is what made the column look untidy rather than the sizes
                did. The number is tabular so it lines up down the column as
                well as beside the address.

                Stopped from bubbling, so a mail link inside a row that is
                itself a link opens the mail client rather than the record. */}
            <div
                className="hidden shrink-0 text-right lg:block lg:w-56"
                onClick={(event) => event.stopPropagation()}
            >
                {person.email && (
                    <a
                        href={`mailto:${person.email}`}
                        className="flex items-center justify-end gap-1.5 text-xs text-muted-foreground transition-colors hover:text-primary"
                    >
                        <span className="truncate">{person.email}</span>
                        <Mail className="h-3.5 w-3.5 shrink-0 opacity-60" aria-hidden="true" />
                    </a>
                )}
                {person.mobile_number && (
                    <a
                        href={`tel:${person.mobile_number}`}
                        className="mt-0.5 flex items-center justify-end gap-1.5 font-mono text-xs tabular-nums text-muted-foreground transition-colors hover:text-primary"
                    >
                        {person.mobile_number}
                        <Phone className="h-3.5 w-3.5 shrink-0 opacity-60" aria-hidden="true" />
                    </a>
                )}
            </div>

            {/* The slot is drawn whether or not there is a badge in it, and
                that is the whole reason the rows line up.

                A badge only some people carry, sized to its own words, is a
                column of variable width — so a row with one pushed everything
                to its left along by 90px, and the posting and the contact
                landed at a different x on every line. Reserving the width
                costs a fixed strip of whitespace and buys a screen that reads
                as a table.

                Only ever filled for somebody who may already open this
                person's 201 file — see DirectoryController::card(). */}
            <div className="hidden w-32 shrink-0 justify-end sm:flex">
                {person.credentials && (
                    <Badge variant={person.credentials.blocking ? 'destructive' : 'warning'}>
                        <ShieldAlert className="h-3 w-3" aria-hidden="true" />
                        {person.credentials.blocking
                            ? 'Cannot work'
                            : `${person.credentials.total} due`}
                    </Badge>
                )}
            </div>

            {/* Reserved for the same reason, so a row nobody may open ends
                where every other row ends. */}
            <div className="hidden w-4 shrink-0 sm:block">
                {person.can_view && (
                    <ChevronRight
                        className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                )}
            </div>
        </>
    );

    const shell =
        'group flex items-center gap-3 border-b border-border px-4 py-3 last:border-0';

    /*
     * A row is a link only when the viewer may follow it. Drawing one that
     * 403s is worse than drawing none: it says there is something behind it
     * *and* that they are not trusted with it, which is the least useful pair
     * of facts a screen can offer.
     */
    return person.can_view ? (
        <Link
            href={`/hr/employees/${person.id}`}
            className={cn(shell, 'transition-colors hover:bg-secondary/50')}
        >
            {body}
        </Link>
    ) : (
        <div className={shell}>{body}</div>
    );
}

/**
 * A department, closed until somebody asks for it.
 *
 * The screen opens on the org chart — eight departments a reader can take in
 * at once — rather than on forty-one people they have to scroll past to find
 * the shape. Expanding is the question being asked: "who is in Operations?"
 *
 * The people are already on the page; this only draws them. A department is a
 * dozen rows, and fetching them per click would put a network round trip in
 * front of an answer the browser is already holding.
 */
function DepartmentBlock({
    code,
    name,
    headcount,
    positions,
    icon: Icon = Building2,
    open,
    onToggle,
}) {
    if (positions.length === 0) return null;

    return (
        <Card className="mb-3">
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="flex w-full items-center gap-3 px-5 py-4 text-left transition-colors hover:bg-secondary/40"
            >
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
                </span>

                <div className="min-w-0 flex-1">
                    <h3 className="truncate text-sm font-semibold text-foreground">{name}</h3>
                    {code && (
                        <p className="font-mono text-[11px] text-muted-foreground">{code}</p>
                    )}
                </div>

                <Badge variant="muted">
                    {headcount} {headcount === 1 ? 'person' : 'people'}
                </Badge>

                {/* Which way the block will move, stated before the click
                    rather than discovered by it. */}
                <ChevronDown
                    className={cn(
                        'h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200',
                        open && 'rotate-180',
                    )}
                    aria-hidden="true"
                />
            </button>

            {open &&
                positions.map((position) => (
                    <div key={position.title} className="border-t border-border">
                        {/* The job, not a heading for its own sake: somebody
                            looking for "a driver" is walking down the org chart
                            rather than reading 41 names. */}
                        <p className="bg-secondary/40 px-4 py-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                            {position.title}
                            <span className="ml-1.5 font-normal normal-case tracking-normal">
                                ({position.people.length})
                            </span>
                        </p>

                        {position.people.map((person) => (
                            <PersonRow key={person.id} person={person} />
                        ))}
                    </div>
                ))}
        </Card>
    );
}

export default function Directory({ departments, unassigned, filters, total }) {
    /*
     * Which departments are open. Closed to begin with — the screen opens on
     * the org chart, and expanding is the question being asked.
     *
     * More than one may be open at once. The sidebar is an accordion because
     * only one module can be current; a directory is being *read*, and having
     * one department close itself because somebody opened another would take
     * away what they were halfway through.
     */
    const [expanded, setExpanded] = useState(() => new Set());

    const toggle = (id) =>
        setExpanded((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });

    /*
     * A search opens everything it matched.
     *
     * Without this, searching a closed directory returns the right answer and
     * shows an empty screen — the reader would conclude the search found
     * nothing, which is the opposite of what happened. Clearing the box closes
     * them again, so the screen returns to the shape it started in.
     */
    const searching = Boolean(filters.search);

    const isOpen = (id) => searching || expanded.has(id);

    const shown = departments.reduce((sum, department) => sum + department.headcount, 0);

    return (
        <AppLayout title="Departments">
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
                    {/* A search arriving by URL still narrows this list and
                        still opens the departments it matched, so it has to be
                        visible and removable — a list narrowed by something
                        with no control on screen is a list nobody can
                        explain. */}
                    {searching ? (
                        <button
                            type="button"
                            onClick={() => router.get('/hr/directory')}
                            className="flex h-9 shrink-0 items-center gap-1.5 self-start rounded-full border border-primary/30 bg-primary/10 px-3 text-xs font-medium text-primary transition-colors hover:bg-primary/20"
                        >
                            Matching “{filters.search}”
                            <X className="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                    ) : (
                        <p className="text-xs text-muted-foreground">
                            Names, roles, and work contacts only.
                        </p>
                    )}

                    <p className="text-xs text-muted-foreground sm:ml-auto">
                        {searching
                            ? `${shown + unassigned.headcount} matching`
                            : `${total} listed`}
                    </p>
                </CardBody>
            </Card>

            {departments.map((department) => (
                <DepartmentBlock
                    key={department.id}
                    {...department}
                    open={isOpen(department.id)}
                    onToggle={() => toggle(department.id)}
                />
            ))}

            {unassigned.headcount > 0 && (
                <DepartmentBlock
                    name="No department on file"
                    code={null}
                    headcount={unassigned.headcount}
                    positions={unassigned.positions}
                    icon={Users}
                    // Not a real department, so it cannot key on an id.
                    open={isOpen('unassigned')}
                    onToggle={() => toggle('unassigned')}
                />
            )}

            {shown === 0 && unassigned.headcount === 0 && (
                <Card>
                    <CardBody className="py-14 text-center">
                        <p className="text-sm font-medium text-foreground">
                            {searching ? 'Nobody matches that search' : 'Nobody to list yet'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {searching
                                ? 'Try a surname, an employee number, or an email address.'
                                : 'Active employees appear here once they are on the roster.'}
                        </p>
                    </CardBody>
                </Card>
            )}
        </AppLayout>
    );
}
