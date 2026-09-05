import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Briefcase, Plus, TriangleAlert, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    Field,
    Input,
    Modal,
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
import { formatCurrency } from '@/lib/utils';

const BLANK = {
    department_id: '',
    code: '',
    title: '',
    salary_grade: '',
    min_salary: '',
    max_salary: '',
    is_active: true,
};

/** The band, or an honest gap where one was never set. */
function Band({ min, max }) {
    if (min === null || max === null) {
        return <span className="text-xs text-muted-foreground">Not set</span>;
    }

    return (
        <span className="tabular-nums">
            {formatCurrency(min)} – {formatCurrency(max)}
        </span>
    );
}

export default function Positions({ positions, filters, departments, summary }) {
    const [creating, setCreating] = useState(false);

    const form = useForm(BLANK);

    const open = () => {
        form.clearErrors();
        form.setData(BLANK);
        setCreating(true);
    };

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/positions', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setCreating(false);
            },
        });
    };

    const filter = (key, value) =>
        router.get(
            '/hr/positions',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const activeRate = summary.total > 0 ? (summary.active / summary.total) * 100 : 0;

    return (
        <AppLayout
            title="Positions"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Positions' },
            ]}
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Positions"
                    value={summary.total}
                    icon={Briefcase}
                    tone={summary.total > 0 ? 'primary' : 'muted'}
                    hint="job titles on the org chart"
                />

                <MeterCard
                    label="Active"
                    value={summary.active}
                    percent={activeRate}
                    badge={summary.total > 0 ? `${Math.round(activeRate)}%` : undefined}
                    icon={Users}
                    tone="success"
                    iconTone="success"
                    hint={`of ${summary.total} — the rest are deactivated, not deleted`}
                />

                {/* Counted, not flagged as an error: a band is optional and
                    advisory. But a rate keyed against a bandless position has
                    nothing to be compared to, which is worth seeing. */}
                <StatCard
                    label="Without a Salary Band"
                    value={summary.without_band}
                    icon={TriangleAlert}
                    tone={summary.without_band > 0 ? 'info' : 'muted'}
                    hint="a rate keyed here has nothing to check against"
                />
            </div>

            <Card>
                <CardHeader
                    title="Positions"
                    description="Job titles inside a department. Salaries & Adjustments flags a rate that falls outside the band — it never blocks it."
                    action={
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="w-full sm:w-52">
                                <SearchInput
                                    defaultValue={filters.search ?? ''}
                                    onChange={(event) => filter('search', event.target.value)}
                                    placeholder="Search title or code"
                                    aria-label="Search positions"
                                />
                            </div>
                            <Select
                                value={filters.department_id ?? ''}
                                onChange={(event) =>
                                    filter('department_id', event.target.value)
                                }
                                aria-label="Filter by department"
                                className="w-full sm:w-44"
                                options={[
                                    { value: '', label: 'All departments' },
                                    ...departments,
                                ]}
                            />
                            <Button onClick={open}>
                                <Plus className="h-4 w-4" />
                                New Position
                            </Button>
                        </div>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Code</TH>
                            <TH>Position</TH>
                            <TH>Department</TH>
                            <TH>Grade</TH>
                            <TH>Salary Band</TH>
                            <TH className="text-right">Employees</TH>
                            <TH>Status</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {positions.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={Briefcase}
                                title={
                                    filters.search || filters.department_id
                                        ? 'No position matches those filters'
                                        : 'No positions yet'
                                }
                                description="A position gives an employee a job title and an optional salary band."
                            />
                        ) : (
                            positions.map((position) => (
                                <TR key={position.id}>
                                    <TD>
                                        <span className="font-mono text-xs font-medium text-muted-foreground">
                                            {position.code}
                                        </span>
                                    </TD>
                                    <TD>
                                        <span className="font-medium text-foreground">
                                            {position.title}
                                        </span>
                                    </TD>
                                    <TD>{position.department ?? '—'}</TD>
                                    <TD>{position.salary_grade ?? '—'}</TD>
                                    <TD>
                                        <Band
                                            min={position.min_salary}
                                            max={position.max_salary}
                                        />
                                    </TD>
                                    <TD className="text-right tabular-nums">
                                        {position.employees_count}
                                    </TD>
                                    <TD>
                                        <Badge
                                            variant={position.is_active ? 'success' : 'muted'}
                                        >
                                            {position.is_active ? 'Active' : 'Inactive'}
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
                title="New position"
                description="The salary band is advisory — HR pays outside it deliberately often enough that enforcing it would be wrong."
                maxWidth="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setCreating(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={form.processing}>
                            Create
                        </Button>
                    </>
                }
            >
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Department" required error={form.errors.department_id}>
                        <Select
                            value={form.data.department_id}
                            onChange={(event) =>
                                form.setData('department_id', event.target.value)
                            }
                            options={[
                                { value: '', label: 'Select a department' },
                                ...departments,
                            ]}
                        />
                    </Field>

                    <Field label="Code" required error={form.errors.code}>
                        <Input
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                            placeholder="OPS-DRV"
                        />
                    </Field>

                    <Field label="Title" required error={form.errors.title}>
                        <Input
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.target.value)}
                            placeholder="Professional Driver"
                        />
                    </Field>

                    <Field label="Salary grade" error={form.errors.salary_grade}>
                        <Input
                            value={form.data.salary_grade}
                            onChange={(event) =>
                                form.setData('salary_grade', event.target.value)
                            }
                            placeholder="SG-8"
                        />
                    </Field>

                    <Field label="Minimum salary" error={form.errors.min_salary}>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            value={form.data.min_salary}
                            onChange={(event) => form.setData('min_salary', event.target.value)}
                        />
                    </Field>

                    <Field label="Maximum salary" error={form.errors.max_salary}>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            value={form.data.max_salary}
                            onChange={(event) => form.setData('max_salary', event.target.value)}
                        />
                    </Field>

                    <label className="flex w-fit cursor-pointer items-center gap-2 sm:col-span-2">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(event) =>
                                form.setData('is_active', event.target.checked)
                            }
                            className="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-ring focus:ring-offset-0"
                        />
                        <span className="text-sm text-muted-foreground">
                            Active — available when filing an employee
                        </span>
                    </label>
                </form>
            </Modal>
        </AppLayout>
    );
}
