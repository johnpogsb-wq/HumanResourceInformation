import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowLeft,
    BadgeCheck,
    Banknote,
    Play,
    Receipt,
    Send,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    Field,
    Modal,
    Pagination,
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
import { formatCurrency, formatDate, initials } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

export default function Run({ run, payslips, can }) {
    const [action, setAction] = useState(null); // 'approve' | 'cancel'

    const form = useForm({ remarks: '' });

    const submit = (event) => {
        event.preventDefault();

        form.post(`/hr/payroll/runs/${run.id}/${action}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setAction(null);
            },
        });
    };

    const rows = payslips.data ?? [];

    const stats = [
        {
            label: 'Employees',
            value: run.employee_count,
            icon: Receipt,
            hint: run.period.name,
        },
        { label: 'Gross Pay', value: formatCurrency(run.total_gross), icon: Wallet },
        {
            label: 'Deductions',
            value: formatCurrency(run.total_deductions),
            icon: TriangleAlert,
        },
        { label: 'Net Pay', value: formatCurrency(run.total_net), icon: Banknote },
    ];

    return (
        <AppLayout
            title={`Payroll Run ${run.run_number}`}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Payroll', href: '/hr/payroll' },
                { label: run.run_number },
            ]}
            actions={
                <div className="flex items-center gap-1.5">
                    {can.recompute && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/hr/payroll/periods/${run.period_id ?? ''}/generate`,
                                )
                            }
                            disabled={!run.period_id}
                        >
                            <Play className="h-4 w-4" />
                            <span className="hidden sm:inline">Recompute</span>
                        </Button>
                    )}

                    {can.submit && (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/hr/payroll/runs/${run.id}/submit`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Send className="h-4 w-4" />
                            <span className="hidden sm:inline">Submit for Approval</span>
                        </Button>
                    )}

                    {can.approve && (
                        <Button size="sm" onClick={() => setAction('approve')}>
                            <BadgeCheck className="h-4 w-4" />
                            <span className="hidden sm:inline">Approve</span>
                        </Button>
                    )}

                    {can.markPaid && (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(
                                    `/hr/payroll/runs/${run.id}/paid`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Banknote className="h-4 w-4" />
                            <span className="hidden sm:inline">Mark Paid</span>
                        </Button>
                    )}

                    {can.cancel && (
                        <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => setAction('cancel')}
                        >
                            Cancel
                        </Button>
                    )}
                </div>
            }
        >
            <div className="mb-4">
                <Button href="/hr/payroll" variant="ghost" size="sm">
                    <ArrowLeft className="h-4 w-4" />
                    Back to payroll periods
                </Button>
            </div>

            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {stats.map((stat) => (
                    <StatCard key={stat.label} {...stat} />
                ))}
            </div>

            <Card className="mb-5">
                <div className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p className="text-xs text-muted-foreground">Status</p>
                        <div className="mt-1">
                            <Badge status={run.status}>{titleCase(run.status)}</Badge>
                        </div>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">Cut-off</p>
                        <p className="mt-1 text-sm text-foreground">
                            {formatDate(run.period.start_date)} –{' '}
                            {formatDate(run.period.end_date)}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">Processed by</p>
                        <p className="mt-1 text-sm text-foreground">
                            {run.processed_by ?? '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">Approved by</p>
                        <p className="mt-1 text-sm text-foreground">{run.approved_by ?? '—'}</p>
                    </div>

                    {run.remarks && (
                        <div className="sm:col-span-2 lg:col-span-4">
                            <p className="text-xs text-muted-foreground">Remarks</p>
                            <p className="mt-1 text-sm text-foreground">{run.remarks}</p>
                        </div>
                    )}
                </div>
            </Card>

            <Card>
                <Table>
                    <THead>
                        <TR>
                            <TH>Employee</TH>
                            <TH className="text-right">Days</TH>
                            <TH className="text-right">OT Hrs</TH>
                            <TH className="text-right">Gross</TH>
                            <TH className="text-right">Deductions</TH>
                            <TH className="text-right">Net Pay</TH>
                            <TH className="text-right">Payslip</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {rows.length === 0 ? (
                            <TableEmpty
                                colSpan={7}
                                icon={Receipt}
                                title="No payslips in this run"
                                description="Recompute the run to generate payslips."
                            />
                        ) : (
                            rows.map((payslip) => (
                                <TR key={payslip.id}>
                                    <TD>
                                        <div className="flex items-center gap-2.5">
                                            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                {initials(payslip.employee.full_name)}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {payslip.employee.full_name}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {payslip.employee.employee_number}
                                                </p>
                                            </div>
                                        </div>
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {payslip.days_worked}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {payslip.overtime_hours || '—'}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-foreground">
                                        {formatCurrency(payslip.gross_pay)}
                                    </TD>

                                    <TD className="text-right text-sm tabular-nums text-muted-foreground">
                                        {formatCurrency(payslip.deductions_total)}
                                    </TD>

                                    <TD className="text-right text-sm font-medium tabular-nums text-foreground">
                                        {formatCurrency(payslip.net_pay)}
                                    </TD>

                                    <TD>
                                        <div className="flex justify-end">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                href={`/hr/payroll/payslips/${payslip.id}`}
                                            >
                                                View
                                            </Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>

                <Pagination links={payslips.meta.links ?? []} meta={payslips.meta} />
            </Card>

            <Modal
                show={Boolean(action)}
                onClose={() => setAction(null)}
                title={action === 'approve' ? 'Approve this payroll run?' : 'Cancel this run?'}
                maxWidth="md"
            >
                <form onSubmit={submit} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        {action === 'approve' ? (
                            <>
                                {run.run_number} totals{' '}
                                <span className="font-medium text-foreground">
                                    {formatCurrency(run.total_net)}
                                </span>{' '}
                                across {run.employee_count} employee(s). Approving locks the
                                figures and applies loan amortisations — it cannot be recomputed
                                afterwards.
                            </>
                        ) : (
                            <>
                                {run.run_number} will be cancelled. Its payslips stay on record
                                for audit, but nothing is paid out.
                            </>
                        )}
                    </p>

                    <Field label="Remarks" error={form.errors.remarks}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={2}
                                value={form.data.remarks}
                                onChange={(event) =>
                                    form.setData('remarks', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setAction(null)}>
                            Back
                        </Button>
                        <Button
                            type="submit"
                            variant={action === 'cancel' ? 'destructive' : 'primary'}
                            loading={form.processing}
                        >
                            {action === 'approve' ? 'Approve Run' : 'Cancel Run'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
