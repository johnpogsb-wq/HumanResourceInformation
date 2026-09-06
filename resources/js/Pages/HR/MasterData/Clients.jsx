import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Briefcase, ChevronDown, FileWarning, Handshake, Plus, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    DateInput,
    Field,
    Input,
    Modal,
    Select,
    MeterCard,
    StatCard,
} from '@/Components/ui';
import { cn, formatCurrency, formatDate, initials } from '@/lib/utils';

const BLANK = {
    code: '',
    name: '',
    industry: '',
    wage_region: '',
    contact_person: '',
    contact_email: '',
    contact_number: '',
    address: '',
    contract_start: '',
    contract_end: '',
    is_active: true,
};

/** One labelled fact inside an opened client. Absent rather than blank. */
function Fact({ label, children }) {
    if (children === null || children === undefined || children === '') return null;

    return (
        <div className="min-w-0">
            <dt className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                {label}
            </dt>
            <dd className="mt-0.5 truncate text-sm text-foreground">{children}</dd>
        </div>
    );
}

/**
 * A client, closed until somebody asks about it.
 *
 * The same shape the departments screen uses, and for the same reason: a table
 * of clients could be counted and never opened, so "what did we sign with
 * Metro Fleet, and who is on their site" had to be answered somewhere else.
 * Both halves are in here now — the terms, then the people.
 */
function ClientBlock({ client, open, onToggle }) {
    const staff = client.employees ?? [];

    return (
        <Card className="mb-3">
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="flex w-full items-center gap-3 px-5 py-4 text-left transition-colors hover:bg-secondary/40"
            >
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <Handshake className="h-[18px] w-[18px]" aria-hidden="true" />
                </span>

                <div className="min-w-0 flex-1">
                    <h3 className="truncate text-sm font-semibold text-foreground">
                        {client.name}
                    </h3>
                    <p className="truncate text-[11px] text-muted-foreground">
                        <span className="font-mono">{client.code}</span>
                        {client.industry && ` · ${client.industry}`}
                    </p>
                </div>

                {/* A contract past its end date with people still on it is the
                    one finding worth carrying on the closed row: the
                    deployment is running past what was signed for. Reported,
                    never enforced — blocking payroll over a paperwork gap
                    would strand those employees unpaid. */}
                {client.contract_lapsed && (
                    <Badge variant="warning">
                        <FileWarning className="h-3 w-3" aria-hidden="true" />
                        Contract lapsed
                    </Badge>
                )}

                {!client.is_active && <Badge variant="muted">Inactive</Badge>}

                <Badge variant={client.active_employees_count > 0 ? 'info' : 'muted'}>
                    {client.active_employees_count}{' '}
                    {client.active_employees_count === 1 ? 'person' : 'people'}
                </Badge>

                <ChevronDown
                    className={cn(
                        'h-4 w-4 shrink-0 text-muted-foreground transition-transform duration-200',
                        open && 'rotate-180',
                    )}
                    aria-hidden="true"
                />
            </button>

            {open && (
                <>
                    <dl className="grid gap-4 border-t border-border px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Fact label="Contact">{client.contact_person}</Fact>
                        <Fact label="Email">
                            {client.contact_email && (
                                <a
                                    href={`mailto:${client.contact_email}`}
                                    className="hover:text-primary"
                                >
                                    {client.contact_email}
                                </a>
                            )}
                        </Fact>
                        <Fact label="Phone">
                            {client.contact_number && (
                                <a
                                    href={`tel:${client.contact_number}`}
                                    className="font-mono tabular-nums hover:text-primary"
                                >
                                    {client.contact_number}
                                </a>
                            )}
                        </Fact>
                        <Fact label="Site">{client.address}</Fact>

                        {/* Not decoration: each region's RTWPB sets its own
                            wage floor, so this is the figure a deployed
                            employee's rate is measured against. */}
                        <Fact label="Wage region">
                            {client.wage_region && (
                                <>
                                    {client.wage_region_label ?? client.wage_region}
                                    {client.daily_minimum && (
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            · {formatCurrency(client.daily_minimum)}/day floor
                                        </span>
                                    )}
                                </>
                            )}
                        </Fact>

                        <Fact label="Contract from">
                            {client.contract_start && formatDate(client.contract_start)}
                        </Fact>
                        <Fact label="Contract to">
                            {client.contract_end ? (
                                <span className={client.contract_lapsed ? 'text-warning' : ''}>
                                    {formatDate(client.contract_end)}
                                </span>
                            ) : (
                                <span className="text-muted-foreground">Open-ended</span>
                            )}
                        </Fact>

                        {/* Everyone ever filed here, against who is there now.
                            The gap is the client's history, and it is why a
                            client with staff is deactivated rather than
                            deleted — payslips keep the client they were
                            grouped under. */}
                        <Fact label="Filed here, all time">{client.employees_count}</Fact>
                    </dl>

                    <div className="border-t border-border">
                        {staff.length === 0 ? (
                            <p className="px-5 py-4 text-xs text-muted-foreground">
                                Nobody is deployed here at the moment.
                            </p>
                        ) : (
                            staff.map((employee) => (
                                <div
                                    key={employee.id}
                                    className="flex items-center gap-3 border-b border-border px-4 py-2.5 last:border-0"
                                >
                                    {employee.photo_url ? (
                                        <img
                                            src={employee.photo_url}
                                            alt=""
                                            className="h-9 w-9 shrink-0 rounded-full object-cover"
                                        />
                                    ) : (
                                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                                            {initials(employee.full_name)}
                                        </span>
                                    )}

                                    <div className="min-w-0 flex-1">
                                        <Link
                                            href={`/hr/employees/${employee.id}`}
                                            className="block truncate text-sm font-medium text-foreground hover:text-primary"
                                        >
                                            {employee.full_name}
                                        </Link>
                                        <p className="truncate font-mono text-[11px] text-muted-foreground">
                                            {employee.employee_number}
                                        </p>
                                    </div>

                                    <span className="hidden shrink-0 text-xs text-muted-foreground sm:block">
                                        {employee.position ?? 'No position on file'}
                                    </span>
                                </div>
                            ))
                        )}
                    </div>
                </>
            )}
        </Card>
    );
}

export default function Clients({ clients, filters, summary, wageRegions }) {
    const [creating, setCreating] = useState(false);

    /*
     * Which clients are open. Closed to begin with, and more than one may be
     * open at once — this is a screen being read, and having one client close
     * itself because another was opened takes away what somebody was halfway
     * through. The same choice the departments and positions screens make.
     */
    const [expanded, setExpanded] = useState(() => new Set());

    const toggle = (id) =>
        setExpanded((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);

            return next;
        });

    // A search opens what it matched, or the answer arrives as a screen of
    // shut cards and reads as nothing found.
    const isOpen = (id) => Boolean(filters.search) || expanded.has(id);

    const form = useForm(BLANK);

    const open = () => {
        form.clearErrors();
        form.setData(BLANK);
        setCreating(true);
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/clients', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    const deployedRate = summary.total > 0 ? (summary.deployed / summary.total) * 100 : 0;
    const set = (field) => (event) => form.setData(field, event.target.value);

    return (
        <AppLayout
            title="Clients"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Clients' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Clients"
                    value={summary.total}
                    icon={Briefcase}
                    tone={summary.total > 0 ? 'primary' : 'muted'}
                    hint={`${summary.active} still taking deployments`}
                />

                {/* A client with nobody on site is not a fault — it is one
                    that has not been staffed yet, or has been wound down. The
                    share says which kind of list this is. */}
                <MeterCard
                    label="With Deployments"
                    value={summary.deployed}
                    percent={deployedRate}
                    badge={summary.total > 0 ? `${Math.round(deployedRate)}%` : undefined}
                    icon={Users}
                    tone="success"
                    iconTone="success"
                    hint={`of ${summary.total} on the books`}
                />
                <StatCard
                    label="Deployed Staff"
                    value={clients.reduce(
                        (sum, client) => sum + client.active_employees_count,
                        0,
                    )}
                    icon={Users}
                    tone="info"
                    hint="active, across all clients"
                />
                {/* A contract past its end date with people still on it is the
                    finding worth surfacing — the deployment is running past
                    what was signed for. */}
                <StatCard
                    label="Lapsed Contracts"
                    value={summary.lapsed}
                    icon={FileWarning}
                    tone={summary.lapsed > 0 ? 'warning' : 'muted'}
                    hint="past end date, still staffed"
                />
            </div>

            <Card className="mb-5">
                <CardHeader
                    title="Clients"
                    description="The companies PrimePower deploys employees to. Open one to see its terms and who is on site."
                    action={
                        <Button onClick={open}>
                            <Plus className="h-4 w-4" />
                            New Client
                        </Button>
                    }
                />
            </Card>

            {clients.length === 0 ? (
                <Card>
                    <CardBody className="py-14 text-center">
                        <p className="text-sm font-medium text-foreground">
                            {filters.search
                                ? 'No client matches that search'
                                : 'No clients yet'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Clients are the companies deployed employees are filed against.
                        </p>
                    </CardBody>
                </Card>
            ) : (
                clients.map((client) => (
                    <ClientBlock
                        key={client.id}
                        client={client}
                        open={isOpen(client.id)}
                        onToggle={() => toggle(client.id)}
                    />
                ))
            )}

            <Modal
                show={creating}
                onClose={() => setCreating(false)}
                title="New Client"
                description="Deployed employees are filed against a client; payroll and billing group by it."
                maxWidth="2xl"
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Code" required error={form.errors.code}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.code}
                                    onChange={set('code')}
                                    error={form.errors.code}
                                    placeholder="MTL"
                                />
                            )}
                        </Field>

                        <Field label="Name" required error={form.errors.name}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.name}
                                    onChange={set('name')}
                                    error={form.errors.name}
                                />
                            )}
                        </Field>

                        <Field label="Industry" error={form.errors.industry}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.industry}
                                    onChange={set('industry')}
                                />
                            )}
                        </Field>

                        <Field
                            label="Wage Region"
                            error={form.errors.wage_region}
                            hint="The regional wage floor deployed staff are measured against."
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={form.data.wage_region}
                                    onChange={set('wage_region')}
                                    placeholder="Select region"
                                    options={wageRegions.map((region) => ({
                                        value: region.value,
                                        label: region.label,
                                    }))}
                                />
                            )}
                        </Field>

                        <Field label="Contact Person" error={form.errors.contact_person}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.contact_person}
                                    onChange={set('contact_person')}
                                />
                            )}
                        </Field>

                        <Field label="Contact Number" error={form.errors.contact_number}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.contact_number}
                                    onChange={set('contact_number')}
                                />
                            )}
                        </Field>

                        <Field label="Contact Email" error={form.errors.contact_email}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="email"
                                    value={form.data.contact_email}
                                    onChange={set('contact_email')}
                                    error={form.errors.contact_email}
                                />
                            )}
                        </Field>

                        <Field label="Address" error={form.errors.address}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={form.data.address}
                                    onChange={set('address')}
                                />
                            )}
                        </Field>

                        <Field label="Contract Start" error={form.errors.contract_start}>
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.contract_start}
                                    onChange={set('contract_start')}
                                />
                            )}
                        </Field>

                        <Field
                            label="Contract End"
                            error={form.errors.contract_end}
                            hint="Leave blank for an open-ended agreement."
                        >
                            {({ id }) => (
                                <DateInput
                                    id={id}
                                    value={form.data.contract_end}
                                    onChange={set('contract_end')}
                                    error={form.errors.contract_end}
                                />
                            )}
                        </Field>
                    </div>

                    <label className="flex items-center gap-2.5 text-sm text-foreground">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) =>
                                form.setData('is_active', event.target.checked)
                            }
                            className="h-4 w-4 rounded border-border text-primary focus:ring-ring"
                        />
                        Active — available when assigning deployments
                    </label>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setCreating(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Create Client
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
