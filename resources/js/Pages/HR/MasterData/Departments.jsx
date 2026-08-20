import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Building2, Pencil, Plus, Trash2, UserX, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    Field,
    FloatingActionButton,
    Input,
    Modal,
    SearchInput,
    StatCard,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
    Textarea,
} from '@/Components/ui';

const BLANK = { code: '', name: '', description: '', is_active: true };

export default function Departments({ departments, filters, summary }) {
    // null | 'new' | the department being edited
    const [modal, setModal] = useState(null);
    const [pendingDelete, setPendingDelete] = useState(null);

    const form = useForm(BLANK);

    const open = (department = 'new') => {
        form.clearErrors();
        form.setData(
            department === 'new'
                ? BLANK
                : { ...BLANK, ...department, description: department.description ?? '' },
        );
        setModal(department);
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
            form.post('/hr/departments', done);
        } else {
            form.put(`/hr/departments/${modal.id}`, done);
        }
    };

    const confirmDelete = () =>
        router.delete(`/hr/departments/${pendingDelete.id}`, {
            preserveScroll: true,
            onSuccess: () => setPendingDelete(null),
        });

    const search = (value) =>
        router.get(
            '/hr/departments',
            { search: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // A department with people or positions filed under it cannot be removed
    // outright — say so on the button rather than after the click.
    const inUse = pendingDelete
        ? pendingDelete.employees_count > 0 || pendingDelete.positions_count > 0
        : false;

    return (
        <AppLayout
            title="Departments"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'Departments' },
            ]}
        >
            <FloatingActionButton icon={Plus} onClick={() => open('new')}>
                New Department
            </FloatingActionButton>

            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Departments"
                    value={summary.total}
                    icon={Building2}
                    tone="primary"
                    hint={`${summary.active} active`}
                />
                <StatCard label="Active" value={summary.active} icon={Users} tone="success" />
                <StatCard
                    label="Without Employees"
                    value={summary.empty}
                    icon={UserX}
                    tone="warning"
                    hint="Newly added, or left behind"
                />
            </div>

            <Card>
                <CardHeader
                    title="Departments"
                    description="Employee records, KPI scoping, and payroll reporting all group by these."
                    action={
                        <div className="w-56">
                            <SearchInput
                                defaultValue={filters.search ?? ''}
                                onChange={(event) => search(event.target.value)}
                                placeholder="Search name or code"
                                aria-label="Search departments"
                            />
                        </div>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Code</TH>
                            <TH>Department</TH>
                            <TH className="text-right">Employees</TH>
                            <TH className="text-right">Positions</TH>
                            <TH>Status</TH>
                            <TH />
                        </TR>
                    </THead>

                    <TBody>
                        {departments.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={Building2}
                                title={
                                    filters.search
                                        ? 'No department matches that search'
                                        : 'No departments yet'
                                }
                                description="Departments are the org units every employee record is filed against."
                            />
                        ) : (
                            departments.map((department) => (
                                <TR key={department.id}>
                                    <TD>
                                        <span className="font-mono text-xs font-medium text-muted-foreground">
                                            {department.code}
                                        </span>
                                    </TD>
                                    <TD>
                                        <p className="font-medium text-foreground">
                                            {department.name}
                                        </p>
                                        {department.description && (
                                            <p className="max-w-md truncate text-xs text-muted-foreground">
                                                {department.description}
                                            </p>
                                        )}
                                    </TD>
                                    <TD className="text-right tabular-nums">
                                        {department.employees_count}
                                    </TD>
                                    <TD className="text-right tabular-nums">
                                        {department.positions_count}
                                    </TD>
                                    <TD>
                                        <Badge
                                            variant={department.is_active ? 'success' : 'muted'}
                                        >
                                            {department.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    </TD>
                                    <TD className="text-right">
                                        <div className="flex justify-end gap-1">
                                            <button
                                                type="button"
                                                onClick={() => open(department)}
                                                className="rounded p-1 text-muted-foreground transition-colors hover:text-foreground"
                                                aria-label={`Edit ${department.name}`}
                                            >
                                                <Pencil className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setPendingDelete(department)}
                                                className="rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
                                                aria-label={`Delete ${department.name}`}
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
                title={modal === 'new' ? 'New department' : 'Edit department'}
                description="The code is normalised to upper case and must be unique."
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
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                    <Field label="Code" required error={form.errors.code}>
                        <Input
                            value={form.data.code}
                            onChange={(event) => form.setData('code', event.target.value)}
                            placeholder="OPS"
                        />
                    </Field>

                    <Field
                        label="Name"
                        required
                        error={form.errors.name}
                        className="sm:col-span-2"
                    >
                        <Input
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder="Fleet Operations"
                        />
                    </Field>

                    <Field
                        label="Description"
                        error={form.errors.description}
                        className="sm:col-span-3"
                    >
                        <Textarea
                            rows={2}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </Field>

                    <label className="flex w-fit cursor-pointer items-center gap-2 sm:col-span-3">
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
                title={inUse ? 'Deactivate this department?' : 'Delete this department?'}
                description={
                    inUse
                        ? `${pendingDelete?.name} has records filed against it, so it is deactivated rather than deleted — the history keeps its department.`
                        : `${pendingDelete?.name} has nothing filed against it and will be removed.`
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
