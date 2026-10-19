import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ScanFace, Trash2, UserCheck, Users, UserX } from 'lucide-react';

import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    Modal,
    Pagination,
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
import { formatDate, initials } from '@/lib/utils';
import FaceCamera from './Partials/FaceCamera';
import { TIMEKEEPING_CRUMBS } from './Partials/shared';

export default function Faces(props) {
    return props.mode === 'self' ? <OwnFace {...props} /> : <ManageFaces {...props} />;
}

/** HR's view: everybody, with enroll, re-enroll and remove. */
function ManageFaces({ employees, filters, summary, samples }) {
    const { errors } = usePage().props;
    const [enrolling, setEnrolling] = useState(null);
    const [comparing, setComparing] = useState(null);
    const [removing, setRemoving] = useState(null);

    const apply = (key, value) =>
        router.get(
            '/hr/timekeeping/faces',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const remove = () =>
        router.delete(`/hr/timekeeping/faces/${removing.id}`, {
            preserveScroll: true,
            onFinish: () => setRemoving(null),
        });

    const rows = employees.data ?? [];

    return (
        <AppLayout
            title="Face Enrollment"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Face Enrollment' }]}
        >
            <div className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label="Active employees" value={summary.total} icon={Users} />
                    <StatCard
                        label="Enrolled"
                        value={summary.enrolled}
                        icon={UserCheck}
                        tone="success"
                        href="/hr/timekeeping/faces?enrolled=yes"
                    />
                    <StatCard
                        label="Not enrolled"
                        value={summary.pending}
                        icon={UserX}
                        tone={summary.pending > 0 ? 'warning' : 'muted'}
                        href="/hr/timekeeping/faces?enrolled=no"
                    />
                </div>

                {(errors.face || errors.employee || errors.consent) && !enrolling && (
                    <p
                        className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                        role="alert"
                    >
                        {errors.face ?? errors.employee ?? errors.consent}
                    </p>
                )}

                <Card>
                    <div className="flex flex-col gap-3 border-b border-border p-4 sm:flex-row sm:items-center">
                        <div className="sm:w-72">
                            <SearchInput
                                defaultValue={filters.search ?? ''}
                                onChange={(event) => apply('search', event.target.value)}
                                placeholder="Search name or employee number…"
                                aria-label="Search employees"
                            />
                        </div>
                        <Select
                            value={filters.enrolled ?? ''}
                            onChange={(event) => apply('enrolled', event.target.value)}
                            placeholder="Everyone"
                            className="w-full sm:w-48"
                            aria-label="Filter by enrollment"
                            options={[
                                { value: 'yes', label: 'Enrolled' },
                                { value: 'no', label: 'Not enrolled' },
                            ]}
                        />
                    </div>

                    <Table>
                        <THead>
                            <TR>
                                <TH>Employee</TH>
                                <TH>Face</TH>
                                <TH>Enrolled</TH>
                                <TH className="text-right">Actions</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {rows.length === 0 ? (
                                <TableEmpty
                                    colSpan={4}
                                    icon={ScanFace}
                                    title="Nobody matches these filters"
                                    description="Try clearing the search or the enrollment filter."
                                />
                            ) : (
                                rows.map((row) => (
                                    <TR key={row.id}>
                                        <TD>
                                            <div className="flex items-center gap-2.5">
                                                <Avatar url={row.photo_url} name={row.name} />
                                                <div>
                                                    <p className="font-medium text-foreground">
                                                        {row.name}
                                                    </p>
                                                    <p className="font-mono text-xs text-muted-foreground">
                                                        {row.number}
                                                        {row.position
                                                            ? ` · ${row.position}`
                                                            : ''}
                                                    </p>
                                                </div>
                                            </div>
                                        </TD>
                                        <TD>
                                            {row.enrollment ? (
                                                <div className="flex flex-wrap gap-1">
                                                    <Badge variant="success">Enrolled</Badge>
                                                    {row.enrollment.self_enrolled && (
                                                        <Badge variant="muted">
                                                            Self-enrolled
                                                        </Badge>
                                                    )}
                                                </div>
                                            ) : (
                                                <Badge variant="warning">Not enrolled</Badge>
                                            )}
                                        </TD>
                                        <TD className="text-sm text-muted-foreground">
                                            {row.enrollment ? (
                                                <>
                                                    {formatDate(row.enrollment.enrolled_at)}
                                                    {row.enrollment.enrolled_by && (
                                                        <span className="block text-xs">
                                                            by {row.enrollment.enrolled_by}
                                                        </span>
                                                    )}
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </TD>
                                        <TD>
                                            <div className="flex justify-end gap-2">
                                                {row.enrollment?.photo_url && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => setComparing(row)}
                                                    >
                                                        Compare
                                                    </Button>
                                                )}
                                                <Button
                                                    size="sm"
                                                    variant={
                                                        row.enrollment ? 'secondary' : 'primary'
                                                    }
                                                    disabled={row.is_self && !!row.enrollment}
                                                    title={
                                                        row.is_self && row.enrollment
                                                            ? 'Another HR staff member has to re-enroll you'
                                                            : undefined
                                                    }
                                                    onClick={() => setEnrolling(row)}
                                                >
                                                    <ScanFace className="h-4 w-4" />
                                                    {row.enrollment ? 'Re-enroll' : 'Enroll'}
                                                </Button>
                                                {row.enrollment && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="text-destructive"
                                                        aria-label={`Remove ${row.name}'s face`}
                                                        title="Remove face"
                                                        onClick={() => setRemoving(row)}
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>

                    <Pagination links={employees.links ?? []} meta={employees} />
                </Card>
            </div>

            {enrolling && (
                <EnrollModal
                    employee={enrolling}
                    samples={samples}
                    onClose={() => setEnrolling(null)}
                />
            )}

            <Modal
                show={comparing !== null}
                onClose={() => setComparing(null)}
                title={comparing ? `${comparing.name} — 201 photo and enrolled face` : ''}
                maxWidth="lg"
            >
                {comparing && (
                    <div className="grid grid-cols-2 gap-4">
                        <Figure caption="201 file photo" url={comparing.photo_url} />
                        <Figure caption="Enrolled face" url={comparing.enrollment?.photo_url} />
                    </div>
                )}
            </Modal>

            <Modal
                show={removing !== null}
                onClose={() => setRemoving(null)}
                title="Remove this face?"
                description={
                    removing
                        ? `${removing.name} will not be able to use the time clock until enrolled again.`
                        : ''
                }
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setRemoving(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={remove}>
                            Remove
                        </Button>
                    </>
                }
            />
        </AppLayout>
    );
}

/** Every other role: their own face, enrolled once. */
function OwnFace({ self, canEnroll, samples }) {
    const { errors } = usePage().props;
    const [enrolling, setEnrolling] = useState(false);
    const error = errors.face ?? errors.employee ?? errors.consent;

    return (
        <AppLayout
            title="Face Enrollment"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Face Enrollment' }]}
        >
            <div className="mx-auto max-w-2xl space-y-5">
                {!self ? (
                    <Card>
                        <div className="flex items-start gap-3 p-5">
                            <UserX
                                className="mt-0.5 h-5 w-5 shrink-0 text-warning"
                                aria-hidden="true"
                            />
                            <div>
                                <p className="text-sm font-medium text-foreground">
                                    No 201 file for this account
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Only a login linked to an employee record has a face to
                                    enroll.
                                </p>
                            </div>
                        </div>
                    </Card>
                ) : (
                    <Card>
                        <div className="flex flex-col gap-5 p-5 sm:flex-row sm:items-center">
                            <div className="grid grid-cols-2 gap-3 sm:w-64">
                                <Figure caption="201 file photo" url={self.photo_url} />
                                <Figure
                                    caption="Enrolled face"
                                    url={self.enrollment?.photo_url}
                                />
                            </div>
                            <div className="flex-1 space-y-2">
                                <p className="text-base font-semibold text-foreground">
                                    {self.name}
                                </p>
                                <p className="font-mono text-xs text-muted-foreground">
                                    {self.number}
                                </p>
                                {self.enrollment ? (
                                    <>
                                        <Badge variant="success">Enrolled</Badge>
                                        <p className="text-sm text-muted-foreground">
                                            Since {formatDate(self.enrollment.enrolled_at)}
                                            {self.enrollment.enrolled_by &&
                                                ` · by ${self.enrollment.enrolled_by}`}
                                            . To change it, ask HR to re-enroll you.
                                        </p>
                                    </>
                                ) : (
                                    <>
                                        <Badge variant="warning">Not enrolled</Badge>
                                        <p className="text-sm text-muted-foreground">
                                            Enroll your face once to use the Time Clock.
                                        </p>
                                        {canEnroll && (
                                            <Button onClick={() => setEnrolling(true)}>
                                                <ScanFace className="h-4 w-4" />
                                                Enroll my face
                                            </Button>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                        {error && !enrolling && (
                            <p
                                className="border-t border-border px-5 py-3 text-sm text-destructive"
                                role="alert"
                            >
                                {error}
                            </p>
                        )}
                    </Card>
                )}
            </div>

            {enrolling && self && (
                <EnrollModal
                    employee={self}
                    samples={samples}
                    self
                    onClose={() => setEnrolling(false)}
                />
            )}
        </AppLayout>
    );
}

function EnrollModal({ employee, samples, onClose, self = false }) {
    const [capture, setCapture] = useState(null);
    const [consent, setConsent] = useState(false);
    const [saving, setSaving] = useState(false);
    const [preview, setPreview] = useState(null);

    const captured = (result) => {
        setCapture(result);
        setPreview(URL.createObjectURL(result.snapshot));
    };

    const retake = () => {
        if (preview) URL.revokeObjectURL(preview);
        setPreview(null);
        setCapture(null);
    };

    const save = () => {
        setSaving(true);

        router.post(
            `/hr/timekeeping/faces/${employee.id}`,
            {
                descriptors: JSON.stringify(capture.descriptors),
                snapshot: new File([capture.snapshot], 'face.jpg', { type: 'image/jpeg' }),
                consent: consent ? '1' : '',
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: onClose,
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Modal
            show
            onClose={() => !saving && onClose()}
            title={self ? 'Enroll your face' : `Enroll ${employee.name}'s face`}
            description={
                self
                    ? 'Face the camera in good light, without a cap or sunglasses. You can do this once; after that only HR can change it.'
                    : 'The employee should be in front of the camera now, in good light, without a cap or sunglasses.'
            }
            maxWidth="md"
            footer={
                capture && (
                    <>
                        <Button variant="secondary" onClick={retake} disabled={saving}>
                            Retake
                        </Button>
                        <Button onClick={save} disabled={!consent || saving}>
                            Save enrollment
                        </Button>
                    </>
                )
            }
        >
            {!capture ? (
                <FaceCamera mode="enroll" samples={samples} onCapture={captured} />
            ) : (
                <div className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <Figure caption="201 file photo" url={employee.photo_url} />
                        <Figure caption="Just taken" url={preview} />
                    </div>
                    <label className="flex items-start gap-2.5 rounded-lg border border-border p-3 text-sm text-foreground">
                        <input
                            type="checkbox"
                            checked={consent}
                            onChange={(event) => setConsent(event.target.checked)}
                            className="mt-0.5 rounded border-border text-primary focus:ring-primary"
                        />
                        <span>
                            {self
                                ? 'I agree that a template of my face will be stored to verify my time-in and time-out, with a photo kept for each punch.'
                                : `${employee.name} was told that a template of their face will be stored to verify their time-in and time-out, with a photo kept for each punch, and agreed to it.`}
                        </span>
                    </label>
                </div>
            )}
        </Modal>
    );
}

function Avatar({ url, name }) {
    return url ? (
        <img src={url} alt="" className="h-8 w-8 shrink-0 rounded-full object-cover" />
    ) : (
        <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
            {initials(name)}
        </span>
    );
}

function Figure({ caption, url }) {
    return (
        <figure className="space-y-1.5">
            {url ? (
                <img
                    src={url}
                    alt={caption}
                    className="aspect-square w-full rounded-lg object-cover"
                />
            ) : (
                <div className="grid aspect-square w-full place-items-center rounded-lg bg-muted text-xs text-muted-foreground">
                    No photo on file
                </div>
            )}
            <figcaption className="text-center text-xs text-muted-foreground">
                {caption}
            </figcaption>
        </figure>
    );
}
