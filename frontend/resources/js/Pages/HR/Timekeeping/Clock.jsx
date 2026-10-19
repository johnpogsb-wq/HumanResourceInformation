import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import {
    Camera,
    ChevronLeft,
    ChevronRight,
    Coffee,
    LogIn,
    LogOut,
    ScanFace,
    TimerReset,
    UserCheck,
    UserX,
} from 'lucide-react';

import AppLayout from '@/Layouts/AppLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    DateInput,
    Modal,
    StatCard,
    Table,
    TableEmpty,
    TBody,
    TD,
    TH,
    THead,
    TR,
} from '@/Components/ui';
import { cn, formatDate } from '@/lib/utils';
import FaceCamera from './Partials/FaceCamera';
import { DayStatus, minutes, TIMEKEEPING_CRUMBS } from './Partials/shared';

const ACTIONS = {
    time_in: { label: 'Time in', icon: LogIn },
    break_start: { label: 'Start break', icon: Coffee },
    break_end: { label: 'End break', icon: TimerReset },
    time_out: { label: 'Time out', icon: LogOut },
};

const ACTION_VARIANTS = {
    time_in: 'success',
    break_start: 'warning',
    break_end: 'primary',
    time_out: 'muted',
};

const STATES = {
    out: { label: 'Not timed in', variant: 'muted' },
    working: { label: 'Working', variant: 'success' },
    on_break: { label: 'On break', variant: 'warning' },
    done: { label: 'Done for today', variant: 'primary' },
    stale: { label: 'Missing time-out', variant: 'destructive' },
};

const time = (iso) =>
    iso
        ? new Date(iso).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })
        : '—';

/** "08:00" → "8:00 AM", for the shift line. */
const clockTime = (hhmm) => {
    const [hours, mins] = hhmm.split(':').map(Number);

    return new Date(2000, 0, 1, hours, mins).toLocaleTimeString('en-PH', {
        hour: 'numeric',
        minute: '2-digit',
    });
};

const shiftDate = (date, days) => {
    const next = new Date(`${date}T00:00:00`);
    next.setDate(next.getDate() + days);

    return next.toISOString().slice(0, 10);
};

/**
 * The server's clock, ticking. The offset is taken once from the page's own
 * `serverTime`, so a device whose clock is wrong still shows the time a punch
 * will actually be stamped with.
 */
function useServerClock(serverTime) {
    const [offset] = useState(() => new Date(serverTime).getTime() - Date.now());
    const [now, setNow] = useState(() => new Date(Date.now() + offset));

    useEffect(() => {
        const timer = setInterval(() => setNow(new Date(Date.now() + offset)), 1000);

        return () => clearInterval(timer);
    }, [offset]);

    return now;
}

export default function Clock(props) {
    const { mode, serverTime, requireBlink } = props;
    const { errors } = usePage().props;
    const now = useServerClock(serverTime);
    const [action, setAction] = useState(null);
    const [sending, setSending] = useState(false);

    const punchError = errors.face ?? errors.action ?? errors.work_date ?? errors.snapshot;
    const kiosk = mode === 'kiosk';

    const submit = ({ descriptor, snapshot }) => {
        setSending(true);

        router.post(
            '/hr/timekeeping/clock/punch',
            {
                action,
                descriptor: JSON.stringify(descriptor),
                snapshot: new File([snapshot], 'punch.jpg', { type: 'image/jpeg' }),
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    setSending(false);
                    setAction(null);
                },
            },
        );
    };

    return (
        <AppLayout
            title="Time Clock"
            breadcrumbs={[...TIMEKEEPING_CRUMBS, { label: 'Time Clock' }]}
        >
            <div className={cn('mx-auto space-y-5', kiosk ? 'max-w-5xl' : 'max-w-3xl')}>
                {kiosk ? (
                    <KioskPanel
                        {...props}
                        now={now}
                        error={punchError}
                        action={action}
                        onPress={setAction}
                    />
                ) : (
                    <PersonalPanel
                        {...props}
                        now={now}
                        error={punchError}
                        action={action}
                        onPress={setAction}
                    />
                )}
            </div>

            <Modal
                show={action !== null}
                onClose={() => !sending && setAction(null)}
                title={
                    action
                        ? `${ACTIONS[action].label} — ${kiosk ? 'look at the camera' : 'verify your face'}`
                        : ''
                }
                maxWidth="md"
            >
                {action &&
                    (sending ? (
                        <p className="py-10 text-center text-sm text-muted-foreground">
                            Recording the punch…
                        </p>
                    ) : (
                        <FaceCamera
                            mode="verify"
                            requireBlink={requireBlink}
                            onCapture={submit}
                        />
                    ))}
            </Modal>
        </AppLayout>
    );
}

function ClockFace({ now, children }) {
    return (
        <div className="text-center">
            <p className="text-sm text-muted-foreground">
                {now.toLocaleDateString('en-PH', {
                    weekday: 'long',
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric',
                })}
            </p>
            <p className="mt-1 font-mono text-4xl font-semibold tabular-nums text-foreground sm:text-5xl">
                {now.toLocaleTimeString('en-PH', {
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                })}
            </p>
            {children}
        </div>
    );
}

function ActionButtons({ isAvailable, onPress }) {
    return (
        <div className="grid grid-cols-2 gap-3">
            {Object.entries(ACTIONS).map(([key, { label, icon: Icon }]) => {
                const available = isAvailable(key);

                return (
                    <Button
                        key={key}
                        size="lg"
                        variant={available ? 'primary' : 'secondary'}
                        disabled={!available}
                        onClick={() => onPress(key)}
                        className="h-14 justify-center"
                    >
                        <Icon className="h-5 w-5" />
                        {label}
                    </Button>
                );
            })}
        </div>
    );
}

function ErrorLine({ error, action }) {
    if (!error || action) return null;

    return (
        <p
            className="rounded-lg bg-destructive/10 px-3 py-2 text-center text-sm text-destructive"
            role="alert"
        >
            {error}
        </p>
    );
}

/**
 * HR's shared kiosk. Anybody enrolled steps up, presses a button and looks at
 * the camera — the face says who they are. Below it, every punch of the day.
 */
function KioskPanel({
    now,
    error,
    action,
    onPress,
    date,
    today,
    punches,
    summary,
    operator,
    can,
}) {
    const [photo, setPhoto] = useState(null);

    const go = (day) =>
        router.get('/hr/timekeeping/clock', day === today ? {} : { date: day }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });

    return (
        <>
            {/* Why the person at the kiosk cannot clock themselves in, and the
                next thing to do about it — an administrator's login is often
                nobody's 201 file, which left the buttons looking broken. */}
            {operator && !operator.has_file && (
                <Notice
                    icon={UserX}
                    title="Your login is not linked to a 201 file"
                    body="The kiosk clocks in employees, so an account with no employee record has nobody to time in as. Link this login to your 201 file, then enroll your face."
                    action={
                        can.linkAccounts && (
                            <Link
                                href="/settings/users"
                                className="text-sm font-medium text-primary hover:underline"
                            >
                                Link it on Users & Access
                            </Link>
                        )
                    }
                />
            )}

            {operator?.has_file && !operator.enrolled && (
                <Notice
                    icon={ScanFace}
                    title="Your own face is not enrolled yet"
                    body="Enroll it once and the kiosk will recognise you."
                    action={
                        <Link
                            href="/hr/timekeeping/faces"
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            Enroll my face
                        </Link>
                    }
                />
            )}

            {summary.enrolled === 0 && (
                <Notice
                    icon={ScanFace}
                    title="Nobody is enrolled yet"
                    body="The kiosk recognises only enrolled faces. Enroll each employee once, with them in front of the camera."
                    action={
                        can.enroll && (
                            <Link
                                href="/hr/timekeeping/faces"
                                className="text-sm font-medium text-primary hover:underline"
                            >
                                Open Face Enrollment
                            </Link>
                        )
                    }
                />
            )}

            <Card floating>
                <CardBody className="space-y-5">
                    <ClockFace now={now}>
                        <p className="mt-3 text-sm text-muted-foreground">
                            Press what you are doing, then look at the camera.
                        </p>
                    </ClockFace>
                    <ErrorLine error={error} action={action} />
                    <ActionButtons isAvailable={() => summary.enrolled > 0} onPress={onPress} />
                </CardBody>
            </Card>

            <div className="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Timed in"
                    value={summary.timed_in}
                    icon={UserCheck}
                    tone="success"
                />
                <StatCard
                    label="On break"
                    value={summary.on_break}
                    icon={Coffee}
                    tone="warning"
                />
                <StatCard label="Timed out" value={summary.timed_out} icon={LogOut} />
            </div>

            <Card>
                <CardHeader
                    title="Time in and time out records"
                    action={
                        <div className="flex items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                aria-label="Previous day"
                                onClick={() => go(shiftDate(date, -1))}
                            >
                                <ChevronLeft className="h-4 w-4" />
                            </Button>
                            <DateInput
                                value={date}
                                max={today}
                                onChange={(event) =>
                                    event.target.value && go(event.target.value)
                                }
                                className="w-40"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                aria-label="Next day"
                                disabled={date >= today}
                                onClick={() => go(shiftDate(date, 1))}
                            >
                                <ChevronRight className="h-4 w-4" />
                            </Button>
                        </div>
                    }
                />
                <Table>
                    <THead>
                        <TR>
                            <TH>Time</TH>
                            <TH>Employee</TH>
                            <TH>Punch</TH>
                            <TH className="text-right">Photo</TH>
                        </TR>
                    </THead>
                    <TBody>
                        {punches.length === 0 ? (
                            <TableEmpty
                                colSpan={4}
                                icon={ScanFace}
                                title={`No punches on ${formatDate(date)}`}
                                description="Punches appear here the moment somebody times in or out."
                            />
                        ) : (
                            punches.map((punch) => (
                                <TR key={punch.id}>
                                    <TD className="whitespace-nowrap font-mono text-sm tabular-nums">
                                        {time(punch.at)}
                                    </TD>
                                    <TD>
                                        <Link
                                            href={`/hr/timekeeping?employee=${punch.employee.id}&from=${date}&to=${date}`}
                                            className="text-sm font-medium text-foreground hover:text-primary hover:underline"
                                        >
                                            {punch.employee.name}
                                        </Link>
                                        <p className="text-xs text-muted-foreground">
                                            {punch.employee.number}
                                        </p>
                                    </TD>
                                    <TD>
                                        <Badge
                                            variant={ACTION_VARIANTS[punch.action] ?? 'default'}
                                        >
                                            {punch.label}
                                        </Badge>
                                    </TD>
                                    <TD className="text-right">
                                        {punch.photo_url && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => setPhoto(punch)}
                                                aria-label={`Photo taken at ${punch.employee.name}'s punch`}
                                            >
                                                <Camera className="h-4 w-4" />
                                                View
                                            </Button>
                                        )}
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            <Modal
                show={photo !== null}
                onClose={() => setPhoto(null)}
                title={
                    photo ? `${photo.employee.name} — ${photo.label} at ${time(photo.at)}` : ''
                }
                maxWidth="md"
            >
                {photo && (
                    <img
                        src={photo.photo_url}
                        alt={`${photo.employee.name} at the time clock`}
                        className="w-full rounded-lg"
                    />
                )}
            </Modal>
        </>
    );
}

/** An employee's own login: their day, and their last two weeks. */
function PersonalPanel({
    now,
    error,
    action,
    onPress,
    employee,
    enrolled,
    state,
    shift,
    punches,
    history = [],
    can,
}) {
    const status = STATES[state?.state] ?? STATES.out;

    return (
        <>
            {!employee || !can.punch ? (
                <Notice
                    icon={UserX}
                    title="No time record for this account"
                    body="This login is not linked to an active 201 file, so there is no day to punch."
                />
            ) : !enrolled ? (
                <Notice
                    icon={ScanFace}
                    title="Your face is not enrolled yet"
                    body="Enroll your face once before you can time in."
                    action={
                        <Link
                            href="/hr/timekeeping/faces"
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            Enroll my face
                        </Link>
                    }
                />
            ) : null}

            <Card floating>
                <CardBody className="space-y-5">
                    <ClockFace now={now}>
                        <div className="mt-3 flex flex-wrap items-center justify-center gap-2 text-sm text-muted-foreground">
                            <Badge variant={status.variant}>{status.label}</Badge>
                            {shift ? (
                                <span>
                                    {shift.name} · {clockTime(shift.start)} –{' '}
                                    {clockTime(shift.end)}
                                </span>
                            ) : (
                                <span>No shift assigned</span>
                            )}
                        </div>
                    </ClockFace>

                    {state?.state === 'stale' && (
                        <p className="rounded-lg bg-destructive/10 px-3 py-2 text-center text-sm text-destructive">
                            Your time-in at {time(state.time_in)} has no time-out. File a time
                            correction for today.
                        </p>
                    )}

                    <ErrorLine error={error} action={action} />
                    <ActionButtons
                        isAvailable={(key) =>
                            enrolled && can.punch && (state?.next ?? []).includes(key)
                        }
                        onPress={onPress}
                    />
                </CardBody>
            </Card>

            {state && (
                <Card floating>
                    <CardHeader title="Today" />
                    <CardBody className="space-y-4">
                        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <Stamp label="Time in" value={time(state.time_in)} />
                            <Stamp label="Break start" value={time(state.break_start)} />
                            <Stamp label="Break end" value={time(state.break_end)} />
                            <Stamp label="Time out" value={time(state.time_out)} />
                        </dl>

                        {state.time_out && (
                            <dl className="grid grid-cols-3 gap-3 border-t border-border pt-4">
                                <Stamp label="Worked" value={minutes(state.minutes_worked)} />
                                <Stamp
                                    label="Late"
                                    value={minutes(state.late_minutes)}
                                    tone={state.late_minutes > 0 ? 'warning' : null}
                                />
                                <Stamp
                                    label="Overtime"
                                    value={minutes(state.overtime_minutes)}
                                    hint={
                                        state.overtime_minutes > 0 ? 'Paid once approved' : null
                                    }
                                />
                            </dl>
                        )}

                        {punches.length > 0 && (
                            <ul className="divide-y divide-border border-t border-border">
                                {punches.map((punch) => (
                                    <li
                                        key={punch.id}
                                        className="flex items-center gap-3 py-2.5"
                                    >
                                        {punch.photo_url ? (
                                            <img
                                                src={punch.photo_url}
                                                alt=""
                                                className="h-10 w-10 shrink-0 rounded-full object-cover"
                                            />
                                        ) : (
                                            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-muted">
                                                <ScanFace className="h-4 w-4 text-muted-foreground" />
                                            </span>
                                        )}
                                        <span className="flex-1 text-sm text-foreground">
                                            {punch.label}
                                        </span>
                                        <span className="font-mono text-sm tabular-nums text-muted-foreground">
                                            {time(punch.at)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardBody>
                </Card>
            )}

            {employee && can.punch && (
                <Card>
                    <CardHeader
                        title="My time in and time out — last 14 days"
                        action={
                            <Link
                                href={`/hr/timekeeping?employee=${employee.id}`}
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                Full DTR
                            </Link>
                        }
                    />
                    <Table>
                        <THead>
                            <TR>
                                <TH>Date</TH>
                                <TH>Time in</TH>
                                <TH>Break</TH>
                                <TH>Time out</TH>
                                <TH>Status</TH>
                                <TH className="text-right">Worked</TH>
                            </TR>
                        </THead>
                        <TBody>
                            {history.length === 0 ? (
                                <TableEmpty
                                    colSpan={6}
                                    icon={ScanFace}
                                    title="No time records yet"
                                    description="Your days appear here once you time in."
                                />
                            ) : (
                                history.map((day) => (
                                    <TR key={day.date}>
                                        <TD className="whitespace-nowrap text-sm font-medium text-foreground">
                                            {formatDate(day.date)}
                                        </TD>
                                        <TD className="font-mono text-sm tabular-nums">
                                            {time(day.time_in)}
                                        </TD>
                                        <TD className="whitespace-nowrap font-mono text-sm tabular-nums text-muted-foreground">
                                            {day.break_start
                                                ? `${time(day.break_start)} – ${day.break_end ? time(day.break_end) : '…'}`
                                                : '—'}
                                        </TD>
                                        <TD className="font-mono text-sm tabular-nums">
                                            {time(day.time_out)}
                                        </TD>
                                        <TD>
                                            <DayStatus status={day.status} />
                                        </TD>
                                        <TD className="text-right text-sm tabular-nums">
                                            {minutes(day.minutes_worked)}
                                        </TD>
                                    </TR>
                                ))
                            )}
                        </TBody>
                    </Table>
                </Card>
            )}
        </>
    );
}

function Stamp({ label, value, tone, hint }) {
    return (
        <div className="rounded-lg bg-muted/50 px-3 py-2 text-center">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'mt-0.5 font-mono text-sm font-semibold tabular-nums',
                    tone === 'warning' ? 'text-warning' : 'text-foreground',
                )}
            >
                {value}
            </dd>
            {hint && <p className="text-[11px] text-muted-foreground">{hint}</p>}
        </div>
    );
}

function Notice({ icon: Icon, title, body, action }) {
    return (
        <div className="flex items-start gap-3 rounded-xl border border-warning/30 bg-warning/10 p-4">
            <Icon className="mt-0.5 h-5 w-5 shrink-0 text-warning" aria-hidden="true" />
            <div className="space-y-1">
                <p className="text-sm font-medium text-foreground">{title}</p>
                <p className="text-sm text-muted-foreground">{body}</p>
                {action}
            </div>
        </div>
    );
}
