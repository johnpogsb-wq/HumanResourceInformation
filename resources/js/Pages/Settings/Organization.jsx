import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Briefcase, Building2, Pencil, Plus, Trash2 } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    Field,
    Input,
    Modal,
    Select,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
    Textarea,
} from '@/Components/ui';
import { formatCurrency } from '@/lib/utils';

const BLANK_DEPARTMENT = { code: '', name: '', description: '', is_active: true };
const BLANK_POSITION = {
    department_id: '',
    code: '',
    title: '',
    salary_grade: '',
    min_salary: '',
    max_salary: '',
    is_active: true,
};

export default function Organization({ departments, positions }) {
    const [departmentModal, setDepartmentModal] = useState(null);
    const [positionModal, setPositionModal] = useState(null);
    const [pendingDelete, setPendingDelete] = useState(null);

    const departmentForm = useForm(BLANK_DEPARTMENT);
    const positionForm = useForm(BLANK_POSITION);

    const departmentOptions = useMemo(
        () =>
            departments.map((department) => ({ value: department.id, label: department.name })),
        [departments],
    );

    const openDepartment = (department) => {
        departmentForm.clearErrors();
        departmentForm.setData(
            department === 'new' ? BLANK_DEPARTMENT : { ...BLANK_DEPARTMENT, ...department },
        );
        setDepartmentModal(department);
    };

    const openPosition = (position) => {
        positionForm.clearErrors();
        positionForm.setData(
            position === 'new'
                ? BLANK_POSITION
                : {
                      ...BLANK_POSITION,
                      ...position,
                      salary_grade: position.salary_grade ?? '',
                      min_salary: position.min_salary ?? '',
                      max_salary: position.max_salary ?? '',
                  },
        );
        setPositionModal(position);
    };

    const submitDepartment = (event) => {
        event.preventDefault();

        const done = {
            preserveScroll: true,
            onSuccess: () => {
                departmentForm.reset();
                setDepartmentModal(null);
            },
        };

        if (departmentModal === 'new') {
            departmentForm.post('/settings/organization/departments', done);
        } else {
            departmentForm.put(
                `/settings/organization/departments/${departmentModal.id}`,
                done,
            );
        }
    };

    const submitPosition = (event) => {
        event.preventDefault();

        const done = {
            preserveScroll: true,
            onSuccess: () => {
                positionForm.reset();
                setPositionModal(null);
            },
        };

        if (positionModal === 'new') {
            positionForm.post('/settings/organization/positions', done);
        } else {
            positionForm.put(`/settings/organization/positions/${positionModal.id}`, done);
        }
    };

    return (
        <SettingsLayout
            title="Organization"
            description="Departments and positions. Employee records, KPIs, and payroll all reference these."
        >
            {/* Departments */}
            <Card>
                <CardHeader
                    title="Departments"
                    description="The top level of the org structure."
                    action={
                        <Button size="sm" onClick={() => openDepartment('new')}>
                            <Plus className="h-4 w-4" />
                            New Department
                        </Button>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Code</TH>
                            <TH>Department</TH>
                            <TH className="text-right">Positions</TH>
                            <TH className="text-right">Employees</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {departments.length === 0 ? (
                            <TableEmpty
                                colSpan={6}
                                icon={Building2}
                                title="No departments"
                                description="Add a department before creating positions or employees."
                            />
                        ) : (
                            departments.map((department) => (
                                <TR key={department.id}>
                                    <TD>
                                        <Badge variant="muted">{department.code}</Badge>
                                    </TD>

                                    <TD>
                                        <p className="text-sm font-medium text-foreground">
                                            {department.name}
                                        </p>
                                        {department.description && (
                                            <p className="max-w-md truncate text-xs text-muted-foreground">
                                                {department.description}
                                            </p>
                                        )}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {department.positions_count}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {department.employees_count}
                                    </TD>

                                    <TD>
                                        <Badge
                                            status={
                                                department.is_active ? 'active' : 'inactive'
                                            }
                                        />
                                    </TD>

                                    <TD>
                                        <div className="flex items-center justify-end gap-1">
                                            <button
                                                type="button"
                                                onClick={() => openDepartment(department)}
                                                aria-label={`Edit ${department.name}`}
                                                className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                                            >
                                                <Pencil className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setPendingDelete({
                                                        type: 'department',
                                                        item: department,
                                                    })
                                                }
                                                aria-label={`Delete ${department.name}`}
                                                className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
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

            {/* Positions */}
            <Card>
                <CardHeader
                    title="Positions"
                    description="Job titles inside a department, with an optional salary band."
                    action={
                        <Button size="sm" onClick={() => openPosition('new')}>
                            <Plus className="h-4 w-4" />
                            New Position
                        </Button>
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Code</TH>
                            <TH>Position</TH>
                            <TH>Department</TH>
                            <TH>Grade</TH>
                            <TH className="text-right">Salary Band</TH>
                            <TH className="text-right">Employees</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {positions.length === 0 ? (
                            <TableEmpty
                                colSpan={8}
                                icon={Briefcase}
                                title="No positions"
                                description="Positions give employees a job title and a salary band."
                            />
                        ) : (
                            positions.map((position) => (
                                <TR key={position.id}>
                                    <TD>
                                        <Badge variant="muted">{position.code}</Badge>
                                    </TD>

                                    <TD className="text-sm font-medium text-foreground">
                                        {position.title}
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {position.department ?? '—'}
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {position.salary_grade ?? '—'}
                                    </TD>

                                    <TD className="whitespace-nowrap text-right text-sm tabular-nums text-muted-foreground">
                                        {position.min_salary || position.max_salary
                                            ? `${formatCurrency(position.min_salary)} – ${formatCurrency(position.max_salary)}`
                                            : '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {position.employees_count}
                                    </TD>

                                    <TD>
                                        <Badge
                                            status={position.is_active ? 'active' : 'inactive'}
                                        />
                                    </TD>

                                    <TD>
                                        <div className="flex items-center justify-end gap-1">
                                            <button
                                                type="button"
                                                onClick={() => openPosition(position)}
                                                aria-label={`Edit ${position.title}`}
                                                className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                                            >
                                                <Pencil className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setPendingDelete({
                                                        type: 'position',
                                                        item: position,
                                                    })
                                                }
                                                aria-label={`Delete ${position.title}`}
                                                className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
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

            {/* Department form */}
            <Modal
                show={Boolean(departmentModal)}
                onClose={() => setDepartmentModal(null)}
                title={departmentModal === 'new' ? 'New Department' : 'Edit Department'}
                maxWidth="lg"
            >
                <form onSubmit={submitDepartment} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Code" required error={departmentForm.errors.code}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={departmentForm.data.code}
                                    onChange={(event) =>
                                        departmentForm.setData('code', event.target.value)
                                    }
                                    error={departmentForm.errors.code}
                                    placeholder="OPS"
                                />
                            )}
                        </Field>

                        <Field
                            label="Name"
                            required
                            className="sm:col-span-2"
                            error={departmentForm.errors.name}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={departmentForm.data.name}
                                    onChange={(event) =>
                                        departmentForm.setData('name', event.target.value)
                                    }
                                    error={departmentForm.errors.name}
                                    placeholder="Fleet Operations"
                                />
                            )}
                        </Field>
                    </div>

                    <Field label="Description" error={departmentForm.errors.description}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={2}
                                value={departmentForm.data.description ?? ''}
                                onChange={(event) =>
                                    departmentForm.setData('description', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <label className="flex items-center gap-2.5">
                        <input
                            type="checkbox"
                            checked={departmentForm.data.is_active}
                            onChange={(event) =>
                                departmentForm.setData('is_active', event.target.checked)
                            }
                            className="h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                        />
                        <span className="text-sm text-foreground">Active</span>
                    </label>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setDepartmentModal(null)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={departmentForm.processing}>
                            Save Department
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Position form */}
            <Modal
                show={Boolean(positionModal)}
                onClose={() => setPositionModal(null)}
                title={positionModal === 'new' ? 'New Position' : 'Edit Position'}
                maxWidth="2xl"
            >
                <form onSubmit={submitPosition} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Code" required error={positionForm.errors.code}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={positionForm.data.code}
                                    onChange={(event) =>
                                        positionForm.setData('code', event.target.value)
                                    }
                                    error={positionForm.errors.code}
                                    placeholder="OPS-DRV"
                                />
                            )}
                        </Field>

                        <Field
                            label="Title"
                            required
                            className="sm:col-span-2"
                            error={positionForm.errors.title}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={positionForm.data.title}
                                    onChange={(event) =>
                                        positionForm.setData('title', event.target.value)
                                    }
                                    error={positionForm.errors.title}
                                    placeholder="Professional Driver"
                                />
                            )}
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Department"
                            required
                            error={positionForm.errors.department_id}
                        >
                            {({ id }) => (
                                <Select
                                    id={id}
                                    value={positionForm.data.department_id}
                                    onChange={(event) =>
                                        positionForm.setData(
                                            'department_id',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Select department"
                                    error={positionForm.errors.department_id}
                                    options={departmentOptions}
                                />
                            )}
                        </Field>

                        <Field label="Salary Grade" error={positionForm.errors.salary_grade}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    value={positionForm.data.salary_grade ?? ''}
                                    onChange={(event) =>
                                        positionForm.setData('salary_grade', event.target.value)
                                    }
                                    placeholder="SG-10"
                                />
                            )}
                        </Field>

                        <Field label="Minimum Salary" error={positionForm.errors.min_salary}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={positionForm.data.min_salary ?? ''}
                                    onChange={(event) =>
                                        positionForm.setData('min_salary', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field label="Maximum Salary" error={positionForm.errors.max_salary}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={positionForm.data.max_salary ?? ''}
                                    onChange={(event) =>
                                        positionForm.setData('max_salary', event.target.value)
                                    }
                                    error={positionForm.errors.max_salary}
                                />
                            )}
                        </Field>
                    </div>

                    <label className="flex items-center gap-2.5">
                        <input
                            type="checkbox"
                            checked={positionForm.data.is_active}
                            onChange={(event) =>
                                positionForm.setData('is_active', event.target.checked)
                            }
                            className="h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                        />
                        <span className="text-sm text-foreground">Active</span>
                    </label>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setPositionModal(null)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={positionForm.processing}>
                            Save Position
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Delete confirmation */}
            <Modal
                show={Boolean(pendingDelete)}
                onClose={() => setPendingDelete(null)}
                title={
                    pendingDelete?.type === 'department'
                        ? 'Delete this department?'
                        : 'Delete this position?'
                }
                maxWidth="md"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setPendingDelete(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                router.delete(
                                    `/settings/organization/${pendingDelete.type}s/${pendingDelete.item.id}`,
                                    {
                                        preserveScroll: true,
                                        onFinish: () => setPendingDelete(null),
                                    },
                                )
                            }
                        >
                            Delete
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {pendingDelete?.item.name ?? pendingDelete?.item.title}
                    </span>{' '}
                    will be deleted. If any employee
                    {pendingDelete?.type === 'department' ? ' or position' : ''} still
                    references it, it is deactivated instead so existing records stay readable.
                </p>
            </Modal>
        </SettingsLayout>
    );
}
