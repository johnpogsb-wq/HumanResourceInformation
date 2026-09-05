import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Briefcase, FileWarning, Plus, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    DateInput,
    Field,
    Input,
    Modal,
    SearchInput,
    Select,
    MeterCard,
    StatCard,
    Table,
    TableEmpty,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { formatCurrency, formatDate } from '@/lib/utils';

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

export default function Clients({ clients, filters, summary, wageRegions }) {
    const [creating, setCreating] = useState(false);

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

    const search = (value) =>
        router.get(
            '/hr/clients',
            { search: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

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

            <Card>
                <CardHeader
                    title="Clients"
                    description="The companies PrimePower deploys employees to. Payroll and billing group by these."
                    action={
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="w-full sm:w-56">
                                <SearchInput
                                    defaultValue={filters.search ?? ''}
                                    onChange={(event) => search(event.target.value)}
                                    placeholder="Search name or code"
                                    aria-label="Search clients"
                                />
                            </div>
                            <Button onClick={open}>
                                <Plus className="h-4 w-4" />
                                New Client
                            </Button>
                        </div>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Code</TH>
                            <TH>Client</TH>
                            <TH>Wage Region</TH>
                            <TH>Contract</TH>
                            <TH className="text-right">Deployed</TH>
                            <TH>Status</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {clients.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={Briefcase}
                                title={
                                    filters.search
                                        ? 'No client matches that search'
                                        : 'No clients yet'
                                }
                                description="Clients are the companies deployed employees are filed against."
                            />
                        ) : (
                            clients.map((client) => (
                                <TR key={client.id}>
                                    <TD>
                                        <span className="font-mono text-xs font-medium text-muted-foreground">
                                            {client.code}
                                        </span>
                                    </TD>

                                    <TD>
                                        <p className="font-medium text-foreground">
                                            {client.name}
                                        </p>
                                        {client.industry && (
                                            <p className="truncate text-xs text-muted-foreground">
                                                {client.industry}
                                            </p>
                                        )}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm">
                                        {client.wage_region ? (
                                            <>
                                                <span className="text-foreground">
                                                    {client.wage_region}
                                                </span>
                                                {client.daily_minimum && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {formatCurrency(client.daily_minimum)}
                                                        /day floor
                                                    </span>
                                                )}
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm">
                                        {client.contract_end ? (
                                            <span
                                                className={
                                                    client.contract_lapsed
                                                        ? 'font-medium text-warning'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {formatDate(client.contract_end)}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">Open</span>
                                        )}
                                    </TD>

                                    <TD className="text-right tabular-nums">
                                        {client.active_employees_count}
                                    </TD>

                                    <TD>
                                        <Badge variant={client.is_active ? 'success' : 'muted'}>
                                            {client.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

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
