import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarRange, Play, Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import SectionTabs from '@/Pages/HR/Payroll/Partials/SectionTabs';
import {
    Badge,
    Button,
    Card,
    Field,
    Input,
    Modal,
    Pagination,
    Select,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { formatCurrency, formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

export default function Index({ periods, suggestion, can }) {
    const [createOpen, setCreateOpen] = useState(false);

    // Pre-filled with the next cut-off so HR is not typing dates by hand.
    const form = useForm({ ...suggestion });

    const submit = (event) => {
        event.preventDefault();

        form.post('/hr/payroll/periods', {
            preserveScroll: true,
            onSuccess: () => setCreateOpen(false),
        });
    };

    const rows = periods.data ?? [];

    return (
        <AppLayout
            title="Payroll & Compensation"
            breadcrumbs={[{ label: 'Human Resource' }, { label: 'Payroll & Compensation' }]}
            actions={
                can.create && (
                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                        <Plus className="h-4 w-4" />
                        <span className="hidden sm:inline">New Period</span>
                    </Button>
                )
            }
        >
            <SectionTabs />

            <Card>
                <Table>
                    <THead>
                        <TR>
                            <TH>Period</TH>
                            <TH>Cut-off</TH>
                            <TH>Pay Date</TH>
                            <TH className="text-right">Employees</TH>
                            <TH className="text-right">Net Pay</TH>
                            <TH>Status</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={CalendarRange}
                                title="No payroll periods yet"
                                description="Create a cut-off period, then compute its run."
                            />
                        ) : (
                            rows.map((period) => (
                                <TR key={period.id}>
                                    <TD>
                                        <p className="text-sm font-medium text-foreground">
                                            {period.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {titleCase(period.frequency)}
                                            {period.run && ` · ${period.run.run_number}`}
                                        </p>
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(period.start_date)} –{' '}
                                        {formatDate(period.end_date)}
                                    </TD>

                                    <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                        {formatDate(period.pay_date)}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {period.run?.employee_count ?? '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {period.run
                                            ? formatCurrency(period.run.total_net)
                                            : '—'}
                                    </TD>

                                    <TD>
                                        <Badge status={period.run?.status ?? period.status}>
                                            {titleCase(period.run?.status ?? period.status)}
                                        </Badge>
                                    </TD>

                                    <TD>
                                        <div className="flex items-center justify-end gap-1">
                                            {period.run && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    href={`/hr/payroll/runs/${period.run.id}`}
                                                >
                                                    Open
                                                </Button>
                                            )}

                                            {can.create &&
                                                !['approved', 'paid'].includes(
                                                    period.run?.status,
                                                ) && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            router.post(
                                                                `/hr/payroll/periods/${period.id}/generate`,
                                                            )
                                                        }
                                                    >
                                                        <Play className="h-4 w-4" />
                                                        {period.run ? 'Recompute' : 'Compute'}
                                                    </Button>
                                                )}
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={periods.meta.links ?? []} meta={periods.meta} />
            </Card>

            <Modal
                show={createOpen}
                onClose={() => setCreateOpen(false)}
                title="New Payroll Period"
                description="Attendance and leave inside these dates drive the computation."
                maxWidth="lg"
            >
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Period Name" required error={form.errors.name}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                error={form.errors.name}
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Start" required error={form.errors.start_date}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="date"
                                    value={form.data.start_date}
                                    onChange={(event) =>
                                        form.setData('start_date', event.target.value)
                                    }
                                    error={form.errors.start_date}
                                />
                            )}
                        </Field>

                        <Field label="End" required error={form.errors.end_date}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="date"
                                    value={form.data.end_date}
                                    onChange={(event) =>
                                        form.setData('end_date', event.target.value)
                                    }
                                    error={form.errors.end_date}
                                />
                            )}
                        </Field>

                        <Field label="Pay Date" required error={form.errors.pay_date}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="date"
                                    value={form.data.pay_date}
                                    onChange={(event) =>
                                        form.setData('pay_date', event.target.value)
                                    }
                                    error={form.errors.pay_date}
                                />
                            )}
                        </Field>
                    </div>

                    <Field label="Frequency" required error={form.errors.frequency}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={form.data.frequency}
                                onChange={(event) =>
                                    form.setData('frequency', event.target.value)
                                }
                                options={[
                                    { value: 'semi_monthly', label: 'Semi-monthly' },
                                    { value: 'monthly', label: 'Monthly' },
                                    { value: 'weekly', label: 'Weekly' },
                                ]}
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={() => setCreateOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            Create Period
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
