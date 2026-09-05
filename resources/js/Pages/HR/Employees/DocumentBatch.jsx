import { Link, useForm } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import axios from 'axios';
import {
    ArrowLeft,
    CheckCircle2,
    CircleAlert,
    FileText,
    Loader2,
    ScanLine,
    TriangleAlert,
    Upload,
} from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    DateInput,
    Input,
    Select,
} from '@/Components/ui';
import { cn } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

/**
 * How each match was arrived at, and how much it should be trusted.
 *
 * The order is the point, and it is the same precedence the single-document
 * upload applies: an ID number belongs to one person and OCR reads digits
 * well, while a name is shared by thousands and arrives truncated.
 */
const MATCHES = {
    number: {
        icon: CheckCircle2,
        tone: 'text-success',
        label: 'ID number',
        note: 'The number on this document is on that employee’s 201 file.',
    },
    name: {
        icon: CheckCircle2,
        tone: 'text-success',
        label: 'Name',
        note: 'Matched on the name only — check it before filing.',
    },
    ambiguous: {
        icon: TriangleAlert,
        tone: 'text-warning',
        label: 'Several people',
        note: 'More than one employee fits this name. Pick the right one.',
    },
    unmatched: {
        icon: CircleAlert,
        tone: 'text-muted-foreground',
        label: 'No match',
        note: 'Nobody on file fits this document. Assign it by hand.',
    },
};

function formatBytes(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export default function DocumentBatch({
    employees = [],
    documentTypes = [],
    expiringTypes = [],
    maxFiles = 20,
    scannerEnabled = false,
}) {
    const picker = useRef(null);
    const [files, setFiles] = useState([]);
    const [rows, setRows] = useState([]);
    const [reading, setReading] = useState(false);
    const [failed, setFailed] = useState(null);

    const form = useForm({});

    const employeeOptions = useMemo(
        () =>
            employees.map((employee) => ({
                value: employee.id,
                label: `${employee.full_name} · ${employee.employee_number}`,
            })),
        [employees],
    );

    /**
     * Reads the whole stack and proposes an owner for each file.
     *
     * Over XHR rather than an Inertia visit, for the same reason the CSV
     * preview is: the browser cannot re-attach files to a form it has
     * re-rendered, and a review table above an empty file input is a dead end.
     */
    const examine = async (picked) => {
        if (picked.length === 0) return;

        setReading(true);
        setFailed(null);
        setRows([]);

        try {
            const body = new FormData();
            picked.forEach((file) => body.append('files[]', file));

            const { data } = await axios.post('/hr/employees/documents/batch/examine', body);

            setRows(data.documents);
        } catch (error) {
            setFailed(
                error.response?.data?.message ??
                    'The batch could not be read. Check the files are images.',
            );
        } finally {
            setReading(false);
        }
    };

    const pick = (list) => {
        const picked = Array.from(list ?? []).slice(0, maxFiles);
        setFiles(picked);
        examine(picked);
    };

    const edit = (index, field, value) =>
        setRows((current) =>
            current.map((row) => (row.index === index ? { ...row, [field]: value } : row)),
        );

    const assigned = rows.filter((row) => row.employee_id).length;

    /**
     * Sends the files again alongside what the person decided.
     *
     * The files are re-uploaded rather than a batch being held server-side
     * between the two requests — nothing sent in between decides where a
     * document lands, and the server re-derives the scope either way.
     */
    const submit = () => {
        const body = new FormData();

        files.forEach((file) => body.append('files[]', file));

        rows.forEach((row, position) => {
            body.append(`assignments[${position}][employee_id]`, row.employee_id ?? '');
            body.append(`assignments[${position}][type]`, row.type ?? 'other');
            body.append(`assignments[${position}][title]`, row.title ?? '');
            body.append(`assignments[${position}][issued_at]`, row.issued_at ?? '');
            body.append(`assignments[${position}][expires_at]`, row.expires_at ?? '');
        });

        form.transform(() => body).post('/hr/employees/documents/batch', {
            forceFormData: true,
        });
    };

    return (
        <AppLayout
            title="File Scanned Documents"
            breadcrumbs={[
                { label: 'Employee Information', href: '/hr/employees' },
                { label: 'File Scanned Documents' },
            ]}
        >
            <div className="mb-4">
                <Link
                    href="/hr/employees"
                    className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                    Back to Employees
                </Link>
            </div>

            <Card>
                <CardHeader
                    title="Drop a stack of scans"
                    description={`Up to ${maxFiles} images at a time. Each one is read and matched to the person it names — nothing is filed until you say so.`}
                />
                <CardBody className="space-y-3">
                    {/* Honest when the feature is dark, rather than offering a
                        scan that would come back empty for every file. */}
                    {!scannerEnabled && (
                        <p className="flex items-start gap-1.5 text-sm text-warning">
                            <TriangleAlert
                                className="mt-0.5 h-4 w-4 shrink-0"
                                aria-hidden="true"
                            />
                            No scanner is configured, so nothing will be matched automatically.
                            Every document will need an employee chosen by hand.
                        </p>
                    )}

                    <input
                        ref={picker}
                        type="file"
                        multiple
                        accept="image/*"
                        className="hidden"
                        onChange={(event) => pick(event.target.files)}
                    />

                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            loading={reading}
                            onClick={() => picker.current?.click()}
                        >
                            {!reading && <Upload className="h-4 w-4" aria-hidden="true" />}
                            {reading ? 'Reading…' : 'Choose files'}
                        </Button>

                        {files.length > 0 && !reading && (
                            <p className="text-sm text-muted-foreground">
                                {files.length} file{files.length === 1 ? '' : 's'} chosen
                            </p>
                        )}
                    </div>

                    {reading && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
                            Reading {files.length} document{files.length === 1 ? '' : 's'}. On
                            the local scanner this takes a few seconds each.
                        </p>
                    )}

                    {failed && <p className="text-sm text-destructive">{failed}</p>}
                </CardBody>
            </Card>

            {rows.length > 0 && (
                <Card className="mt-6">
                    <CardHeader
                        title="Check before filing"
                        description={`${assigned} of ${rows.length} have an employee. The rest are skipped.`}
                        action={
                            <Button
                                onClick={submit}
                                loading={form.processing}
                                disabled={assigned === 0}
                            >
                                <FileText className="mr-1.5 h-4 w-4" aria-hidden="true" />
                                File {assigned} document{assigned === 1 ? '' : 's'}
                            </Button>
                        }
                    />
                    <CardBody className="space-y-3">
                        {rows.map((row) => {
                            const match = MATCHES[row.matched_by] ?? MATCHES.unmatched;
                            const Icon = match.icon;
                            const showExpiry =
                                expiringTypes.includes(row.type) || Boolean(row.expires_at);

                            return (
                                <div
                                    key={row.index}
                                    className={cn(
                                        'rounded-lg border p-3',
                                        row.employee_id
                                            ? 'border-border bg-card'
                                            : 'border-warning/30 bg-warning/5',
                                    )}
                                >
                                    <div className="mb-2 flex flex-wrap items-center gap-2">
                                        <Icon
                                            className={cn('h-4 w-4 shrink-0', match.tone)}
                                            aria-hidden="true"
                                        />
                                        <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground">
                                            {row.file_name}
                                        </span>
                                        <Badge variant="muted">{match.label}</Badge>
                                        <span className="text-xs text-muted-foreground">
                                            {formatBytes(row.size)}
                                        </span>
                                    </div>

                                    {/* What the scan actually read, so the match
                                        can be judged rather than taken on faith. */}
                                    {/* An expired document in a batch is the
                                        easiest one to let through — nobody
                                        reads forty expiry dates. Said once,
                                        per row, in the row itself. */}
                                    {row.expiry?.state === 'expired' && (
                                        <p
                                            className={cn(
                                                'mb-2 flex items-start gap-1.5 text-xs',
                                                row.expiry.blocking
                                                    ? 'text-destructive'
                                                    : 'text-warning',
                                            )}
                                        >
                                            <TriangleAlert
                                                className="mt-0.5 h-3.5 w-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            Already expired, {Math.abs(row.expiry.days)} days
                                            ago.
                                            {row.expiry.blocking &&
                                                ' This type is required to work.'}
                                        </p>
                                    )}

                                    <p className="mb-2 text-xs text-muted-foreground">
                                        {row.name_on_document ? (
                                            <>
                                                Names{' '}
                                                <span className="text-foreground">
                                                    {row.name_on_document}
                                                </span>
                                                {row.document_number && (
                                                    <>
                                                        , number{' '}
                                                        <span className="font-mono text-foreground">
                                                            {row.document_number}
                                                        </span>
                                                    </>
                                                )}
                                                . {match.note}
                                            </>
                                        ) : (
                                            'No name could be read from this image.'
                                        )}
                                    </p>

                                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                        <Select
                                            value={row.employee_id ?? ''}
                                            onChange={(event) =>
                                                edit(
                                                    row.index,
                                                    'employee_id',
                                                    event.target.value || null,
                                                )
                                            }
                                            placeholder="Choose an employee"
                                            aria-label="Employee"
                                            options={employeeOptions}
                                        />

                                        <Select
                                            value={row.type ?? 'other'}
                                            onChange={(event) =>
                                                edit(row.index, 'type', event.target.value)
                                            }
                                            aria-label="Document type"
                                            options={documentTypes.map((type) => ({
                                                value: type,
                                                label: titleCase(type),
                                            }))}
                                        />

                                        <DateInput
                                            value={row.issued_at ?? ''}
                                            onChange={(value) =>
                                                edit(row.index, 'issued_at', value)
                                            }
                                            aria-label="Issued date"
                                            placeholder="Issued"
                                        />

                                        {showExpiry ? (
                                            <DateInput
                                                value={row.expires_at ?? ''}
                                                onChange={(value) =>
                                                    edit(row.index, 'expires_at', value)
                                                }
                                                aria-label="Expiry date"
                                                placeholder="Expires"
                                            />
                                        ) : (
                                            <Input
                                                value={row.title ?? ''}
                                                onChange={(event) =>
                                                    edit(row.index, 'title', event.target.value)
                                                }
                                                aria-label="Title"
                                                placeholder="Title"
                                            />
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </CardBody>
                </Card>
            )}
        </AppLayout>
    );
}
