import { router } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { LogoMark } from '@/Components/layout/PrimePowerLogo';
import { Button } from '@/Components/ui';
import { formatCurrency, formatDate } from '@/lib/utils';

const titleCase = (value) =>
    value
        ? String(value)
              .replace(/[_-]/g, ' ')
              .replace(/\b\w/g, (character) => character.toUpperCase())
        : null;

const longDateTime = (iso) =>
    new Date(iso).toLocaleString('en-PH', {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });

/**
 * The 201 file on paper: the company's letterhead, every section of the
 * record in one place, and two signature lines. Printed from the browser like
 * the payslip, so there is no PDF dependency to keep — "Save as PDF" in the
 * print dialog makes the file.
 */
export default function Print({ employee, company, full, printedBy, printedAt, can }) {
    const record = employee.data ?? employee;
    const back = `/hr/employees/${record.id}`;

    const toggleFull = (event) =>
        router.get(
            `/hr/employees/${record.id}/print`,
            event.target.checked ? { full: 1 } : {},
            { preserveScroll: true, replace: true },
        );

    const contact = [company.address, company.phone, company.email].filter(Boolean);

    return (
        <AppLayout
            title={`Print — ${record.full_name}`}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: record.full_name, href: back },
                { label: 'Print' },
            ]}
        >
            <div className="mx-auto mb-4 flex max-w-4xl flex-wrap items-center justify-between gap-3 print:hidden">
                <Button href={back} variant="ghost" size="sm">
                    <ArrowLeft className="h-4 w-4" />
                    Back to record
                </Button>

                <div className="flex flex-wrap items-center gap-3">
                    {can.showFull && (
                        <label className="flex items-center gap-2 text-sm text-foreground">
                            <input
                                type="checkbox"
                                checked={full}
                                onChange={toggleFull}
                                className="h-4 w-4 rounded border-input text-primary focus:ring-ring/30"
                            />
                            Print government numbers in full
                        </label>
                    )}
                    <Button onClick={() => window.print()}>
                        <Printer className="h-4 w-4" />
                        Print
                    </Button>
                </div>
            </div>

            <article className="mx-auto max-w-4xl rounded-xl border border-border bg-card p-8 text-sm text-foreground shadow-sm print:max-w-none print:rounded-none print:border-0 print:bg-transparent print:p-0 print:shadow-none">
                {/* Letterhead. A <div>, not a <header>: the app hides every header
                    element on paper to drop the topbar. */}
                <div className="flex items-center gap-4 border-b-2 border-foreground pb-4">
                    <LogoMark className="h-16 w-16" />
                    <div className="min-w-0 flex-1">
                        <p className="text-xl font-bold uppercase tracking-tight text-logo-primary">
                            {company.name}
                        </p>
                        {company.tagline && (
                            <p className="text-xs text-muted-foreground">{company.tagline}</p>
                        )}
                        {contact.length > 0 && (
                            <p className="text-xs text-muted-foreground">
                                {contact.join(' · ')}
                            </p>
                        )}
                    </div>
                    <div className="text-right">
                        <p className="text-base font-semibold uppercase">
                            Employee Information Sheet
                        </p>
                        <p className="text-xs text-muted-foreground">201 File</p>
                    </div>
                </div>

                {/* Who */}
                <section className="flex items-start gap-5 border-b border-border py-5">
                    {record.photo_url ? (
                        <img
                            src={record.photo_url}
                            alt=""
                            className="h-28 w-28 shrink-0 rounded border border-border object-cover"
                        />
                    ) : (
                        <div className="grid h-28 w-28 shrink-0 place-items-center rounded border border-dashed border-border text-xs text-muted-foreground">
                            2×2 photo
                        </div>
                    )}
                    <div className="grid flex-1 grid-cols-2 gap-x-6 gap-y-3">
                        <Item label="Full name" value={record.full_name} wide strong />
                        <Item label="Employee number" value={record.employee_number} />
                        <Item label="Status" value={titleCase(record.status)} />
                        <Item label="Position" value={record.position?.title} />
                        <Item label="Department" value={record.department?.name} />
                    </div>
                </section>

                <Block title="Personal information">
                    <Item label="Date of birth" value={formatDate(record.birth_date)} />
                    <Item label="Place of birth" value={record.birth_place} />
                    <Item label="Gender" value={titleCase(record.gender)} />
                    <Item label="Civil status" value={titleCase(record.civil_status)} />
                    <Item label="Nationality" value={record.nationality} />
                    <Item label="Religion" value={record.religion} />
                    <Item label="Blood type" value={record.blood_type} />
                </Block>

                <Block title="Contact information">
                    <Item label="Email" value={record.email} />
                    <Item label="Mobile" value={record.mobile_number} />
                    <Item label="Phone" value={record.phone_number} />
                    <Item label="Present address" value={record.present_address} wide />
                    <Item label="Permanent address" value={record.permanent_address} wide />
                </Block>

                <Block title="Emergency contact">
                    <Item label="Name" value={record.emergency_contact_name} />
                    <Item label="Relationship" value={record.emergency_contact_relationship} />
                    <Item label="Contact number" value={record.emergency_contact_number} />
                </Block>

                {'sss_number' in record && (
                    <Block title="Government IDs">
                        <Item label="SSS" value={record.sss_number} mono />
                        <Item label="PhilHealth" value={record.philhealth_number} mono />
                        <Item label="Pag-IBIG" value={record.pagibig_number} mono />
                        <Item label="TIN" value={record.tin} mono />
                    </Block>
                )}

                <Block title="Employment">
                    <Item label="Category" value={titleCase(record.employment_category)} />
                    <Item label="Client" value={record.client?.name} />
                    <Item label="Supervisor" value={record.supervisor?.full_name} />
                    <Item
                        label="Employment status"
                        value={titleCase(record.employment_status)}
                    />
                    <Item label="Employment type" value={titleCase(record.employment_type)} />
                    <Item label="Date hired" value={formatDate(record.date_hired)} />
                    <Item
                        label="Date regularized"
                        value={formatDate(record.date_regularized)}
                    />
                    {record.date_separated && (
                        <Item
                            label="Date separated"
                            value={formatDate(record.date_separated)}
                        />
                    )}
                </Block>

                {'basic_salary' in record && (
                    <Block title="Compensation and banking">
                        <Item
                            label="Basic salary"
                            value={formatCurrency(record.basic_salary)}
                        />
                        <Item label="Pay frequency" value={titleCase(record.pay_frequency)} />
                        <Item label="Bank" value={record.bank_name} />
                        <Item label="Account number" value={record.bank_account_number} mono />
                    </Block>
                )}

                {record.drivers_license_number && (
                    <Block title="Driver's licence">
                        <Item
                            label="Licence number"
                            value={record.drivers_license_number}
                            mono
                        />
                        <Item label="DL codes" value={record.license_dl_codes} />
                        <Item label="Conditions" value={record.license_conditions} />
                        <Item label="Expiry" value={formatDate(record.license_expiry)} />
                    </Block>
                )}

                <List
                    title="Documents on file"
                    rows={record.documents?.data ?? record.documents}
                    empty="No documents on file."
                    columns={['Type', 'Title', 'Expires']}
                    cells={(row) => [
                        titleCase(row.type),
                        row.title,
                        row.expires_at ? formatDate(row.expires_at) : '—',
                    ]}
                />

                {record.notes && (
                    <Block title="Notes">
                        <Item value={record.notes} wide />
                    </Block>
                )}

                {/* Signatures */}
                <section className="mt-12 grid break-inside-avoid grid-cols-2 gap-12">
                    <Signature label="Employee's signature over printed name" />
                    <Signature label="Verified by (HR)" />
                </section>

                <footer className="mt-8 border-t border-border pt-3 text-[11px] text-muted-foreground">
                    <p>
                        Printed by {printedBy} on {longDateTime(printedAt)}
                        {!full && ' · government numbers and the account number are masked'}.
                    </p>
                    <p className="mt-0.5 font-medium">
                        CONFIDENTIAL — contains personal information protected under the Data
                        Privacy Act of 2012 (RA 10173). Do not copy or leave unattended.
                    </p>
                </footer>
            </article>
        </AppLayout>
    );
}

function Block({ title, children }) {
    return (
        <section className="break-inside-avoid border-b border-border py-4">
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {title}
            </h2>
            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3 print:grid-cols-3">
                {children}
            </dl>
        </section>
    );
}

function Item({ label, value, wide = false, strong = false, mono = false }) {
    return (
        <div className={wide ? 'col-span-2 sm:col-span-3 print:col-span-3' : undefined}>
            {label && <dt className="text-[11px] text-muted-foreground">{label}</dt>}
            <dd
                className={[
                    'text-foreground',
                    strong ? 'text-base font-semibold' : '',
                    mono ? 'font-mono' : '',
                ].join(' ')}
            >
                {value || value === 0 ? value : '—'}
            </dd>
        </div>
    );
}

function List({ title, rows = [], empty, columns, cells }) {
    return (
        <section className="break-inside-avoid border-b border-border py-4">
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {title}
            </h2>
            {rows?.length ? (
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b border-border text-[11px] text-muted-foreground">
                            {columns.map((column) => (
                                <th key={column} className="py-1.5 pr-3 font-medium">
                                    {column}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, index) => (
                            <tr
                                key={row.id ?? index}
                                className="border-b border-border/60 last:border-0"
                            >
                                {cells(row).map((cell, cellIndex) => (
                                    <td
                                        key={cellIndex}
                                        className="py-1.5 pr-3 align-top text-foreground"
                                    >
                                        {cell || '—'}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                <p className="text-muted-foreground">{empty}</p>
            )}
        </section>
    );
}

function Signature({ label }) {
    return (
        <div>
            <div className="h-10 border-b border-foreground" />
            <p className="mt-1 text-center text-[11px] text-muted-foreground">{label}</p>
            <p className="mt-3 text-[11px] text-muted-foreground">Date: ____________________</p>
        </div>
    );
}
