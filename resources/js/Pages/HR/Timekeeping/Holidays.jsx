import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CalendarDays, Pencil, Plus, TriangleAlert, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardHeader,
    Field,
    Input,
    Modal,
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
import { formatDate } from '@/lib/utils';

const BLANK = {
    name: '',
    date: '',
    type: 'regular',
    is_nationwide: true,
};

export default function Holidays({ holidays, filters, years, summary, nextYear, types, can }) {
    const [modal, setModal] = useState(null); // null | 'new' | holiday
    const form = useForm(BLANK);

    const open = (holiday = 'new') => {
        form.clearErrors();
        form.setData(
            holiday === 'new'
                ? { ...BLANK, date: `${filters.year}-01-01` }
                : {
                      name: holiday.name,
                      date: holiday.date,
                      type: holiday.type,
                      is_nationwide: holiday.is_nationwide,
                  },
        );
        setModal(holiday);
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
            form.post('/hr/timekeeping/holidays', done);
        } else {
            form.put(`/hr/timekeeping/holidays/${modal.id}`, done);
        }
    };

    const remove = (holiday) => {
        if (!window.confirm(`Remove ${holiday.name}?`)) return;

        router.delete(`/hr/timekeeping/holidays/${holiday.id}`, { preserveScroll: true });
    };

    const changeYear = (year) =>
        router.get(
            '/hr/timekeeping/holidays',
            { year },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <AppLayout
            title="Holidays"
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Timekeeping', href: '/hr/timekeeping' },
                { label: 'Holidays' },
            ]}
            actions={
                can.manage && (
                    <Button size="sm" onClick={() => open('new')}>
                        <Plus className="h-4 w-4" />
                        <span className="hidden sm:inline">Add Holiday</span>
                    </Button>
                )
            }
        >
            <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label={`Holidays in ${filters.year}`}
                    value={summary.total}
                    icon={CalendarDays}
                />
                <StatCard
                    label="Regular"
                    value={summary.regular}
                    icon={CalendarDays}
                    hint="200% premium when worked"
                />
                <StatCard
                    label="Special Non-Working"
                    value={summary.special}
                    icon={CalendarDays}
                    hint="130% premium when worked"
                />
            </div>

            {/* Proclamations land late in the year, and the cost of forgetting
                is silent: leave gets charged for days it shouldn't be. */}
            {nextYear.count === 0 && (
                <Card className="mb-5">
                    <div className="flex items-start gap-3 p-4">
                        <TriangleAlert className="mt-0.5 h-5 w-5 shrink-0 text-warning" />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-foreground">
                                No holidays recorded for {nextYear.year} yet
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Leave requests spanning {nextYear.year} will be charged for
                                holidays as if they were ordinary working days, and attendance
                                on those dates will not be marked as a holiday.
                            </p>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => changeYear(nextYear.year)}
                        >
                            Set up {nextYear.year}
                        </Button>
                    </div>
                </Card>
            )}

            <Card>
                <CardHeader
                    title={`${filters.year} Holiday Calendar`}
                    description="Read by leave costing, attendance status, and holiday pay."
                    action={
                        <Select
                            value={String(filters.year)}
                            onChange={(event) => changeYear(event.target.value)}
                            aria-label="Filter by year"
                            className="w-32"
                            options={years.map((year) => ({
                                value: String(year),
                                label: String(year),
                            }))}
                        />
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Date</TH>
                            <TH>Holiday</TH>
                            <TH>Type</TH>
                            <TH>Coverage</TH>
                            {can.manage && <TH className="text-right">Actions</TH>}
                        </TR>
                    </THead>

                    <TBody>
                        {holidays.length === 0 ? (
                            <TableEmpty
                                colSpan={can.manage ? 5 : 4}
                                icon={CalendarDays}
                                title={`No holidays recorded for ${filters.year}`}
                                description="Leave, attendance, and payroll will treat every day this year as an ordinary working day until holidays are added."
                            />
                        ) : (
                            holidays.map((holiday) => (
                                <TR key={holiday.id}>
                                    <TD className="whitespace-nowrap">
                                        <p className="text-sm font-medium text-foreground">
                                            {formatDate(holiday.date)}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {holiday.day_of_week}
                                        </p>
                                    </TD>

                                    <TD className="text-sm text-foreground">{holiday.name}</TD>

                                    <TD>
                                        <Badge
                                            variant={
                                                holiday.type === 'regular' ? 'primary' : 'muted'
                                            }
                                        >
                                            {holiday.type === 'regular'
                                                ? 'Regular'
                                                : 'Special Non-Working'}
                                        </Badge>
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            ×{holiday.pay_multiplier}
                                        </span>
                                    </TD>

                                    <TD className="text-sm text-muted-foreground">
                                        {holiday.is_nationwide ? 'Nationwide' : 'Local'}
                                    </TD>

                                    {can.manage && (
                                        <TD className="text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => open(holiday)}
                                                    aria-label={`Edit ${holiday.name}`}
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => remove(holiday)}
                                                    disabled={holiday.has_attendance}
                                                    title={
                                                        holiday.has_attendance
                                                            ? 'Attendance already recorded on this date'
                                                            : undefined
                                                    }
                                                    aria-label={`Remove ${holiday.name}`}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </TD>
                                    )}
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            <Modal
                show={Boolean(modal)}
                onClose={() => setModal(null)}
                title={modal === 'new' ? 'Add Holiday' : 'Edit Holiday'}
            >
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Name" error={form.errors.name}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                placeholder="e.g. Araw ng Kagitingan"
                                required
                            />
                        )}
                    </Field>

                    <Field label="Date" error={form.errors.date}>
                        {({ id }) => (
                            <Input
                                id={id}
                                type="date"
                                value={form.data.date}
                                onChange={(event) => form.setData('date', event.target.value)}
                                required
                            />
                        )}
                    </Field>

                    <Field label="Type" error={form.errors.type}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={form.data.type}
                                onChange={(event) => form.setData('type', event.target.value)}
                                options={types}
                            />
                        )}
                    </Field>

                    <label className="flex items-center gap-2 text-sm text-foreground">
                        <input
                            type="checkbox"
                            checked={form.data.is_nationwide}
                            onChange={(event) =>
                                form.setData('is_nationwide', event.target.checked)
                            }
                            className="h-4 w-4 rounded border-border text-primary focus:ring-ring"
                        />
                        Nationwide
                    </label>

                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="outline" onClick={() => setModal(null)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {modal === 'new' ? 'Add Holiday' : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
