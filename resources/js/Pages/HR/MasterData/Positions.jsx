import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Briefcase, Pencil, Plus, Trash2, TriangleAlert, Users } from 'lucide-react';
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
    // null | 'new' | the position being edited
    const [modal, setModal] = useState(null);
    const [pendingDelete, setPendingDelete] = useState(null);

    const form = useForm(BLANK);

    const open = (position = 'new') => {
        form.clearErrors();
        form.setData(
            position === 'new'
                ? BLANK
                : {
                      ...BLANK,
                      ...position,
                      salary_grade: position.salary_grade ?? '',
                      min_salary: position.min_salary ?? '',
                      max_salary: position.max_salary ?? '',
                  },
        );
        setModal(position);
    };

    const submit = (event) => {
        event.preventDefault();

        const done = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setModal(null);
            },
        };

        if (modal === 'new') {
            form.post('/hr/positions', done);
        } else {
            form.put(`/hr/positions/${modal.id}`, done);
        }
    };

    const confirmDelete = () =>
        router.delete(`/hr/positions/${pendingDelete.id}`, {
            preserveScroll: true,
            onSuccess: () => setPendingDelete(null),
        });

    const filter = (key, value) =>
        router.get(
            '/hr/positions',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // A position with people in it is deactivated, not deleted, so their
    // history keeps its job title.
    const inUse = pendingDelete ? pendingDelete.employees_count > 0 : false;

    return (
        <AppLayout
            title="Positions"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Positions' },
            ]}
            actions={
                <Button size="sm" onClick={() => open('new')}>
                    <Plus className="h-4 w-4" />
                    <span className="hidden sm:inline">New Position</span>
                </Button>
            }
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Positions"
                    value={summary.total}
                    icon={Briefcase}
                    tone="primary"
                    hint={`${summary.active} active`}
                />
                <StatCard label="Active" value={summary.active} icon={Users} tone="success" />
                <StatCard
                    label="Without a Salary Band"
                    value={summary.without_band}
                    icon={TriangleAlert}
                    tone="warning"
                    hint="A rate keyed here has nothing to check against"
                />
            </div>

            <Card>
                <CardHeader
                    title="Positions"
                    description="Job titles inside a department. Salaries & Adjustments flags a rate that falls outside the band — it never blocks it."
                    action={
                        <div className="flex gap-2">
                            <div className="w-52">
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
                                className="w-44"
                                options={[
                                    { value: '', label: 'All departments' },
                                    ...departments,
                                ]}
                            />
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
                            <TH />
                        </TR>
                    </THead>

                    <TBody>
                        {positions.length === 0 ? (
                            <TableEmpty
                                colSpan={8}
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
                                    <TD className="text-right">
                                        <div className="flex justify-end gap-1">
                                            <button
                                                type="button"
                                                onClick={() => open(position)}
                                                className="rounded p-1 text-muted-foreground transition-colors hover:text-foreground"
                                                aria-label={`Edit ${position.title}`}
                                            >
                                                <Pencil className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setPendingDelete(position)}
                                                className="rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
                                                aria-label={`Delete ${position.title}`}
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            <Modal
                show={modal !== null}
                onClose={() => setModal(null)}
                title={modal === 'new' ? 'New position' : 'Edit position'}
                description="The salary band is advisory — HR pays outside it deliberately often enough that enforcing it would be wrong."
                maxWidth="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)}>
                            Cancel
                        </Button>
                        <Button onClick={submit} disabled={form.processing}>
                            {modal === 'new' ? 'Create' : 'Save'}
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

            <Modal
                show={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                title={inUse ? 'Deactivate this position?' : 'Delete this position?'}
                description={
                    inUse
                        ? `${pendingDelete?.employees_count} employee(s) hold ${pendingDelete?.title}, so it is deactivated rather than deleted — their history keeps the title.`
                        : `${pendingDelete?.title} has nobody in it and will be removed.`
                }
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setPendingDelete(null)}>
                            Cancel
                        </Button>
                        <Button onClick={confirmDelete}>
                            {inUse ? 'Deactivate' : 'Delete'}
                        </Button>
                    </>
                }
            />
        </AppLayout>
    );
}
