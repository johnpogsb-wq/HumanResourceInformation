import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import axios from 'axios';
import {
    ArrowLeft,
    CheckCircle2,
    Download,
    FileText,
    History,
    Loader2,
    Pencil,
    Plus,
    ScanLine,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
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
import { cn, formatCurrency, formatDate, initials } from '@/lib/utils';

const DOCUMENT_TYPES = [
    'contract',
    'resume',
    'government_id',
    'clearance',
    'certificate',
    'medical',
    'drivers_license',
    'other',
];

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const TABS = [
    { id: 'overview', label: 'Overview' },
    { id: 'employment', label: 'Employment' },
    { id: 'documents', label: 'Documents' },
    { id: 'audit', label: 'Audit Trail' },
];

function DetailRow({ label, value, className }) {
    return (
        <div className={cn('min-w-0', className)}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 break-words text-sm text-foreground">{value || '—'}</dd>
        </div>
    );
}

function formatBytes(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export default function Show({ employee, subordinates, audits, can }) {
    const record = employee.data ?? employee;

    const [tab, setTab] = useState('overview');
    const [uploadOpen, setUploadOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [pendingDocument, setPendingDocument] = useState(null);

    const upload = useForm({
        type: 'contract',
        title: '',
        description: '',
        issued_at: '',
        expires_at: '',
        file: null,
    });

    // What the scanner proposed, kept beside the form rather than merged into
    // it — HR needs to see that a value was read rather than typed, and see
    // the name check, before saving.
    const [scan, setScan] = useState(null);
    const [scanning, setScanning] = useState(false);

    /**
     * Reads the picked file and fills the form. Nothing is saved here; the
     * Upload button below still does that, so every field stays editable.
     * A failed scan is silent by design — the form simply stays empty and
     * HR types it, exactly as before this existed.
     */
    const scanDocument = async (file) => {
        if (!can.scanDocuments || !file?.type?.startsWith('image/')) return;

        setScanning(true);
        setScan(null);

        try {
            const body = new FormData();
            body.append('file', file);

            const { data } = await axios.post(
                `/hr/employees/${record.id}/documents/scan`,
                body,
            );

            if (!data.scanned) return;

            setScan(data.fields);

            // Only fill what came back. A null from the scanner means "not
            // legible" — overwriting a field with it would erase a correction
            // HR had already typed.
            const filled = {};
            if (data.fields.type) filled.type = data.fields.type;
            if (data.fields.title) filled.title = data.fields.title;
            if (data.fields.issued_at) filled.issued_at = data.fields.issued_at;
            if (data.fields.expires_at) filled.expires_at = data.fields.expires_at;

            upload.setData((current) => ({ ...current, ...filled }));
        } catch {
            // Network or server trouble: leave the form alone.
        } finally {
            setScanning(false);
        }
    };

    // The scan panel describes one file, so it has to go whenever the form
    // does — otherwise reopening shows the previous document's reading.
    const closeUpload = () => {
        setUploadOpen(false);
        setScan(null);
    };

    const submitDocument = (event) => {
        event.preventDefault();

        upload.post(`/hr/employees/${record.id}/documents`, {
            forceFormData: true,
            onSuccess: () => {
                upload.reset();
                closeUpload();
            },
        });
    };

    const confirmDeleteEmployee = () => {
        router.delete(`/hr/employees/${record.id}`, { onFinish: () => setDeleteOpen(false) });
    };

    const deleteDocument = () => {
        router.delete(`/hr/employees/${record.id}/documents/${pendingDocument.id}`, {
            preserveScroll: true,
            onFinish: () => setPendingDocument(null),
        });
    };

    const documents = record.documents ?? [];

    return (
        <AppLayout
            title={record.full_name}
            breadcrumbs={[
                { label: 'Human Resource' },
                { label: 'Employee Information', href: '/hr/employees' },
                { label: record.full_name },
            ]}
            actions={
                <div className="flex items-center gap-1.5">
                    {can.update && (
                        <Button
                            href={`/hr/employees/${record.id}/edit`}
                            size="sm"
                            variant="outline"
                        >
                            <Pencil className="h-4 w-4" />
                            <span className="hidden sm:inline">Edit</span>
                        </Button>
                    )}
                    {can.delete && (
                        <Button
                            size="sm"
                            variant="destructive"
                            onClick={() => setDeleteOpen(true)}
                        >
                            <Trash2 className="h-4 w-4" />
                            <span className="hidden sm:inline">Archive</span>
                        </Button>
                    )}
                </div>
            }
        >
            <div className="mb-4">
                <Button href="/hr/employees" variant="ghost" size="sm">
                    <ArrowLeft className="h-4 w-4" />
                    Back to directory
                </Button>
            </div>

            {/* Profile header */}
            <Card className="mb-5">
                <CardBody className="flex flex-col gap-5 sm:flex-row sm:items-center">
                    {record.photo_url ? (
                        <img
                            src={record.photo_url}
                            alt=""
                            className="h-20 w-20 shrink-0 rounded-full object-cover ring-2 ring-border"
                        />
                    ) : (
                        <span className="grid h-20 w-20 shrink-0 place-items-center rounded-full bg-primary/10 text-xl font-semibold text-primary">
                            {initials(record.full_name)}
                        </span>
                    )}

                    <div className="min-w-0 flex-1">
                        <h2 className="truncate text-lg font-semibold text-foreground">
                            {record.full_name}
                        </h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {record.position?.title ?? 'No position assigned'}
                            {record.department?.name ? ` · ${record.department.name}` : ''}
                        </p>

                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            <Badge variant="primary">{record.employee_number}</Badge>
                            <Badge status={record.employment_status} />
                            <Badge status={record.status} />
                            {record.has_account && <Badge variant="muted">Has login</Badge>}
                        </div>
                    </div>

                    <dl className="grid shrink-0 grid-cols-2 gap-4 sm:grid-cols-1 sm:text-right">
                        <DetailRow label="Date Hired" value={formatDate(record.date_hired)} />
                        <DetailRow label="Supervisor" value={record.supervisor?.full_name} />
                    </dl>
                </CardBody>
            </Card>

            {/* Tabs */}
            <div
                className="scrollbar-thin mb-5 flex gap-1 overflow-x-auto border-b border-border"
                role="tablist"
            >
                {TABS.map((item) => (
                    <button
                        key={item.id}
                        type="button"
                        role="tab"
                        aria-selected={tab === item.id}
                        onClick={() => setTab(item.id)}
                        className={cn(
                            '-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition-colors',
                            tab === item.id
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                        )}
                    >
                        {item.label}
                        {item.id === 'documents' && documents.length > 0 && (
                            <span className="ml-1.5 rounded-full bg-secondary px-1.5 py-0.5 text-[10px] text-muted-foreground">
                                {documents.length}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {tab === 'overview' && (
                <div className="grid gap-5 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="Personal Information" />
                        <CardBody>
                            <dl className="grid grid-cols-2 gap-4">
                                <DetailRow
                                    label="Date of Birth"
                                    value={formatDate(record.birth_date)}
                                />
                                <DetailRow label="Place of Birth" value={record.birth_place} />
                                <DetailRow label="Gender" value={titleCase(record.gender)} />
                                <DetailRow
                                    label="Civil Status"
                                    value={titleCase(record.civil_status)}
                                />
                                <DetailRow label="Nationality" value={record.nationality} />
                                <DetailRow label="Religion" value={record.religion} />
                                <DetailRow label="Blood Type" value={record.blood_type} />
                            </dl>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Contact Information" />
                        <CardBody>
                            <dl className="grid grid-cols-2 gap-4">
                                <DetailRow label="Email" value={record.email} />
                                <DetailRow label="Mobile" value={record.mobile_number} />
                                <DetailRow label="Phone" value={record.phone_number} />
                                <div className="hidden sm:block" />
                                <DetailRow
                                    label="Present Address"
                                    value={record.present_address}
                                    className="col-span-2"
                                />
                                <DetailRow
                                    label="Permanent Address"
                                    value={record.permanent_address}
                                    className="col-span-2"
                                />
                            </dl>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader title="Emergency Contact" />
                        <CardBody>
                            <dl className="grid grid-cols-2 gap-4">
                                <DetailRow label="Name" value={record.emergency_contact_name} />
                                <DetailRow
                                    label="Relationship"
                                    value={record.emergency_contact_relationship}
                                />
                                <DetailRow
                                    label="Contact Number"
                                    value={record.emergency_contact_number}
                                />
                            </dl>
                        </CardBody>
                    </Card>

                    {can.viewSensitive && (
                        <Card>
                            <CardHeader
                                title="Government IDs"
                                description="Visible to HR and the employee only."
                            />
                            <CardBody>
                                <dl className="grid grid-cols-2 gap-4">
                                    <DetailRow label="SSS" value={record.sss_number} />
                                    <DetailRow
                                        label="PhilHealth"
                                        value={record.philhealth_number}
                                    />
                                    <DetailRow label="Pag-IBIG" value={record.pagibig_number} />
                                    <DetailRow label="TIN" value={record.tin} />
                                </dl>
                            </CardBody>
                        </Card>
                    )}
                </div>
            )}

            {tab === 'employment' && (
                <div className="grid gap-5 lg:grid-cols-2">
                    <Card>
                        <CardHeader title="Employment Details" />
                        <CardBody>
                            <dl className="grid grid-cols-2 gap-4">
                                <DetailRow label="Department" value={record.department?.name} />
                                <DetailRow label="Position" value={record.position?.title} />
                                <DetailRow
                                    label="Supervisor"
                                    value={record.supervisor?.full_name}
                                />
                                <DetailRow
                                    label="Employment Status"
                                    value={titleCase(record.employment_status)}
                                />
                                <DetailRow
                                    label="Employment Type"
                                    value={titleCase(record.employment_type)}
                                />
                                <DetailRow
                                    label="Date Hired"
                                    value={formatDate(record.date_hired)}
                                />
                                <DetailRow
                                    label="Date Regularized"
                                    value={formatDate(record.date_regularized)}
                                />
                                <DetailRow
                                    label="Date Separated"
                                    value={formatDate(record.date_separated)}
                                />
                                {record.separation_reason && (
                                    <DetailRow
                                        label="Separation Reason"
                                        value={record.separation_reason}
                                        className="col-span-2"
                                    />
                                )}
                            </dl>
                        </CardBody>
                    </Card>

                    {can.viewSensitive && (
                        <Card>
                            <CardHeader title="Compensation & Banking" />
                            <CardBody>
                                <dl className="grid grid-cols-2 gap-4">
                                    <DetailRow
                                        label="Basic Salary"
                                        value={formatCurrency(record.basic_salary)}
                                    />
                                    <DetailRow
                                        label="Pay Frequency"
                                        value={titleCase(record.pay_frequency)}
                                    />
                                    <DetailRow label="Bank" value={record.bank_name} />
                                    <DetailRow
                                        label="Account Number"
                                        value={record.bank_account_number}
                                    />
                                </dl>
                            </CardBody>
                        </Card>
                    )}

                    <Card>
                        <CardHeader title="Driver's License" />
                        <CardBody>
                            <dl className="grid grid-cols-2 gap-4">
                                <DetailRow
                                    label="License Number"
                                    value={record.drivers_license_number}
                                />
                                <DetailRow
                                    label="Restriction Codes"
                                    value={record.license_restriction_codes}
                                />
                                <DetailRow
                                    label="Expiry"
                                    value={formatDate(record.license_expiry)}
                                />
                            </dl>
                        </CardBody>
                    </Card>

                    <Card>
                        <CardHeader
                            title="Direct Reports"
                            description={`${subordinates.length} employee(s) reporting to this person.`}
                        />
                        <CardBody>
                            {subordinates.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No direct reports.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {subordinates.map((subordinate) => (
                                        <li key={subordinate.id}>
                                            <Link
                                                href={`/hr/employees/${subordinate.id}`}
                                                className="flex items-center gap-2.5 rounded-md p-2 transition-colors hover:bg-secondary/60"
                                            >
                                                <span className="grid h-7 w-7 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                                                    {initials(subordinate.full_name)}
                                                </span>
                                                <span className="text-sm text-foreground">
                                                    {subordinate.full_name}
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardBody>
                    </Card>
                </div>
            )}

            {tab === 'documents' && (
                <Card>
                    <CardHeader
                        title="Documents"
                        description="Contracts, IDs, clearances, and certificates."
                        action={
                            can.manageDocuments && (
                                <Button size="sm" onClick={() => setUploadOpen(true)}>
                                    <Plus className="h-4 w-4" />
                                    Upload
                                </Button>
                            )
                        }
                    />

                    <Table>
                        <THead>
                            <TR>
                                <TH>Document</TH>
                                <TH>Type</TH>
                                <TH>Issued</TH>
                                <TH>Expires</TH>
                                <TH>Size</TH>
                                <TH className="text-right">Actions</TH>
                            </TR>
                        </THead>

                        <TBody>
                            {documents.length === 0 ? (
                                <TableEmpty
                                    colSpan={6}
                                    icon={FileText}
                                    title="No documents uploaded"
                                    description="Upload the employment contract, government IDs, and clearances here."
                                />
                            ) : (
                                documents.map((document) => (
                                    <TR key={document.id}>
                                        <TD>
                                            <div className="flex items-center gap-2.5">
                                                <FileText
                                                    className="h-4 w-4 shrink-0 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-foreground">
                                                        {document.title}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {document.file_name}
                                                    </p>
                                                </div>
                                            </div>
                                        </TD>

                                        <TD>
                                            <Badge variant="muted">
                                                {titleCase(document.type)}
                                            </Badge>
                                        </TD>

                                        <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                            {formatDate(document.issued_at)}
                                        </TD>

                                        <TD className="whitespace-nowrap text-sm">
                                            {document.expires_at ? (
                                                <span
                                                    className={cn(
                                                        document.is_expired
                                                            ? 'font-medium text-destructive'
                                                            : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {formatDate(document.expires_at)}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </TD>

                                        <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                            {formatBytes(document.file_size)}
                                        </TD>

                                        <TD>
                                            <div className="flex items-center justify-end gap-1">
                                                <a
                                                    href={document.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    aria-label={`Download ${document.title}`}
                                                    className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                                                >
                                                    <Download
                                                        className="h-4 w-4"
                                                        aria-hidden="true"
                                                    />
                                                </a>

                                                {can.manageDocuments && (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setPendingDocument(document)
                                                        }
                                                        aria-label={`Delete ${document.title}`}
                                                        className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                                    >
                                                        <Trash2
                                                            className="h-4 w-4"
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                )}
                                            </div>
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </Card>
            )}

            {tab === 'audit' && (
                <Card>
                    <CardHeader
                        title="Audit Trail"
                        description="Most recent changes to this record."
                    />

                    <Table>
                        <THead>
                            <TR>
                                <TH>Event</TH>
                                <TH>Changed Fields</TH>
                                <TH>User</TH>
                                <TH>When</TH>
                            </TR>
                        </THead>

                        <TBody>
                            {audits.length === 0 ? (
                                <TableEmpty
                                    colSpan={4}
                                    icon={History}
                                    title="No audit entries"
                                    description="Changes to this record will appear here."
                                />
                            ) : (
                                audits.map((audit) => (
                                    <TR key={audit.id}>
                                        <TD>
                                            <Badge
                                                variant={
                                                    audit.event === 'deleted'
                                                        ? 'destructive'
                                                        : audit.event === 'created'
                                                          ? 'success'
                                                          : 'primary'
                                                }
                                            >
                                                {audit.event}
                                            </Badge>
                                        </TD>

                                        <TD className="text-sm text-muted-foreground">
                                            {audit.changes.length > 0
                                                ? audit.changes.map(titleCase).join(', ')
                                                : '—'}
                                        </TD>

                                        <TD className="text-sm text-foreground">
                                            {audit.user}
                                        </TD>

                                        <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                            {formatDate(audit.created_at, {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </Card>
            )}

            {/* Upload document */}
            <Modal
                show={uploadOpen}
                onClose={closeUpload}
                title="Upload Document"
                description={`Attach a file to ${record.full_name}'s 201 file.`}
                maxWidth="lg"
            >
                <form onSubmit={submitDocument} className="space-y-4">
                    <Field label="Document Type" required error={upload.errors.type}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={upload.data.type}
                                onChange={(event) => upload.setData('type', event.target.value)}
                                options={DOCUMENT_TYPES.map((type) => ({
                                    value: type,
                                    label: titleCase(type),
                                }))}
                            />
                        )}
                    </Field>

                    <Field label="Title" required error={upload.errors.title}>
                        {({ id }) => (
                            <Input
                                id={id}
                                value={upload.data.title}
                                onChange={(event) =>
                                    upload.setData('title', event.target.value)
                                }
                                error={upload.errors.title}
                                placeholder="e.g. Employment Contract 2026"
                            />
                        )}
                    </Field>

                    <Field label="Description" error={upload.errors.description}>
                        {({ id }) => (
                            <Textarea
                                id={id}
                                rows={2}
                                value={upload.data.description}
                                onChange={(event) =>
                                    upload.setData('description', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Issued Date" error={upload.errors.issued_at}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="date"
                                    value={upload.data.issued_at}
                                    onChange={(event) =>
                                        upload.setData('issued_at', event.target.value)
                                    }
                                />
                            )}
                        </Field>

                        <Field label="Expiry Date" error={upload.errors.expires_at}>
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="date"
                                    value={upload.data.expires_at}
                                    onChange={(event) =>
                                        upload.setData('expires_at', event.target.value)
                                    }
                                    error={upload.errors.expires_at}
                                />
                            )}
                        </Field>
                    </div>

                    <Field
                        label="File"
                        required
                        hint="PDF, JPG, PNG, DOC, or DOCX — max 10 MB"
                        error={upload.errors.file}
                    >
                        {({ id }) => (
                            <input
                                id={id}
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                onChange={(event) => {
                                    const file = event.target.files[0] ?? null;
                                    upload.setData('file', file);
                                    scanDocument(file);
                                }}
                                className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-xs file:font-medium file:text-secondary-foreground hover:file:bg-secondary/70"
                            />
                        )}
                    </Field>

                    {scanning && (
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" />
                            Reading the document…
                        </p>
                    )}

                    {/* What was read, shown separately from the fields it
                        filled — HR has to be able to tell a scanned value from
                        a typed one before saving. */}
                    {scan && !scanning && (
                        <div className="rounded-lg border border-border bg-secondary/40 p-3">
                            <p className="mb-2 flex items-center gap-1.5 text-xs font-medium text-foreground">
                                <ScanLine className="h-3.5 w-3.5" aria-hidden="true" />
                                Read from the document — check before saving
                            </p>

                            <dl className="space-y-1 text-xs">
                                {scan.name_on_document && (
                                    <div className="flex gap-2">
                                        <dt className="w-28 shrink-0 text-muted-foreground">
                                            Name
                                        </dt>
                                        <dd className="text-foreground">
                                            {scan.name_on_document}
                                        </dd>
                                    </div>
                                )}
                                {scan.document_number && (
                                    <div className="flex gap-2">
                                        <dt className="w-28 shrink-0 text-muted-foreground">
                                            Number
                                        </dt>
                                        <dd className="font-mono text-foreground">
                                            {scan.document_number}
                                        </dd>
                                    </div>
                                )}
                                {scan.expires_at && (
                                    <div className="flex gap-2">
                                        <dt className="w-28 shrink-0 text-muted-foreground">
                                            Expires
                                        </dt>
                                        <dd className="text-foreground">
                                            {formatDate(scan.expires_at)}
                                        </dd>
                                    </div>
                                )}
                            </dl>

                            {/* The check that catches filing a document under
                                the wrong person. */}
                            {scan.name_matches === false && (
                                <p className="mt-2 flex items-start gap-1.5 text-xs text-destructive">
                                    <TriangleAlert
                                        className="mt-0.5 h-3.5 w-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    This document names {scan.name_on_document}, not{' '}
                                    {record.full_name}. Check you are filing it under the right
                                    employee.
                                </p>
                            )}

                            {scan.name_matches === true && (
                                <p className="mt-2 flex items-center gap-1.5 text-xs text-success">
                                    <CheckCircle2
                                        className="h-3.5 w-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Name matches this employee.
                                </p>
                            )}

                            {scan.confidence !== 'high' && (
                                <p className="mt-2 text-xs text-warning">
                                    {scan.note ??
                                        'The scan was not fully legible — check every field.'}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="outline" onClick={closeUpload}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={upload.processing}>
                            Upload
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Archive employee */}
            <Modal
                show={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                title="Archive this employee?"
                maxWidth="md"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setDeleteOpen(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={confirmDeleteEmployee}>
                            Archive Employee
                        </Button>
                    </>
                }
            >
                <div className="flex gap-3">
                    <TriangleAlert
                        className="mt-0.5 h-5 w-5 shrink-0 text-destructive"
                        aria-hidden="true"
                    />
                    <p className="text-sm text-muted-foreground">
                        <span className="font-medium text-foreground">{record.full_name}</span>{' '}
                        will be archived and their login deactivated. The 201 file is retained
                        for audit and payroll history, and an administrator can restore it
                        later.
                    </p>
                </div>
            </Modal>

            {/* Delete document */}
            <Modal
                show={Boolean(pendingDocument)}
                onClose={() => setPendingDocument(null)}
                title="Delete this document?"
                maxWidth="md"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setPendingDocument(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={deleteDocument}>
                            Delete
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">
                        {pendingDocument?.title}
                    </span>{' '}
                    will be permanently removed. This cannot be undone.
                </p>
            </Modal>
        </AppLayout>
    );
}
