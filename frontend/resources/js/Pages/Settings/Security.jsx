import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import axios from 'axios';
import { History, KeyRound, Mail, TriangleAlert } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import {
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Field,
    Input,
    Modal,
    TBody,
    TD,
    TH,
    THead,
    TR,
    Table,
    TableEmpty,
} from '@/Components/ui';
import { cn, formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const AUDIT_FILTERS = [
    { id: 'changes', label: 'Record changes' },
    { id: 'auth', label: 'Sign-ins' },
    { id: 'all', label: 'All' },
];

/** A failed sign-in is a finding; a successful one is a note. */
const eventVariant = (entry) => {
    if (entry.event === 'login_failed' || entry.event === 'lockout') return 'destructive';
    if (entry.event === 'deleted') return 'destructive';
    if (entry.event === 'created') return 'success';
    if (entry.is_auth) return 'muted';
    return 'primary';
};

export default function Security({
    account,
    tokens,
    auditLog,
    auditFilter,
    canViewAudit,
    canRename = false,
    mustChangePassword = false,
    mailIsLogged = false,
}) {
    const [deleteOpen, setDeleteOpen] = useState(false);

    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const deleteForm = useForm({ password: '' });

    const submitPassword = (event) => {
        event.preventDefault();

        passwordForm.put('/settings/security/password', {
            preserveScroll: true,
            onSuccess: () => passwordForm.reset(),
        });
    };

    return (
        <SettingsLayout
            title="Security"
            description="Your password, two-factor authentication, and API tokens."
        >
            {/* The user did not ask for this screen — RequirePasswordChange
                sent them here. Without saying so, the redirect reads as the
                system losing their click. */}
            {mustChangePassword && (
                <div className="rounded-lg border border-warning/30 bg-warning/5 p-4">
                    <p className="flex items-start gap-2 text-sm font-medium text-warning">
                        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        Choose your own password before continuing.
                    </p>
                    <p className="mt-1.5 pl-6 text-sm text-muted-foreground">
                        This account is still on the password it was set up with, which someone
                        else has seen. The rest of the system opens once you have replaced it
                        below.
                    </p>
                </div>
            )}

            <Card>
                <CardHeader
                    title="Password"
                    description="Changing your password does not sign you out of this browser."
                />
                <CardBody>
                    <form onSubmit={submitPassword} className="space-y-4">
                        <Field
                            label="Current Password"
                            required
                            className="max-w-md"
                            error={passwordForm.errors.current_password}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="password"
                                    autoComplete="current-password"
                                    value={passwordForm.data.current_password}
                                    onChange={(event) =>
                                        passwordForm.setData(
                                            'current_password',
                                            event.target.value,
                                        )
                                    }
                                    error={passwordForm.errors.current_password}
                                />
                            )}
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="New Password"
                                required
                                error={passwordForm.errors.password}
                            >
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        type="password"
                                        autoComplete="new-password"
                                        value={passwordForm.data.password}
                                        onChange={(event) =>
                                            passwordForm.setData('password', event.target.value)
                                        }
                                        error={passwordForm.errors.password}
                                    />
                                )}
                            </Field>

                            <Field label="Confirm New Password" required>
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        type="password"
                                        autoComplete="new-password"
                                        value={passwordForm.data.password_confirmation}
                                        onChange={(event) =>
                                            passwordForm.setData(
                                                'password_confirmation',
                                                event.target.value,
                                            )
                                        }
                                    />
                                )}
                            </Field>
                        </div>

                        <div className="flex justify-end">
                            <Button type="submit" loading={passwordForm.processing}>
                                Update Password
                            </Button>
                        </div>
                    </form>
                </CardBody>
            </Card>

            {/* The emailed factor above the authenticator, because it is the
                one most people will actually finish turning on. */}
            <EmailOtpCard account={account} mailIsLogged={mailIsLogged} />

            <TwoFactorCard account={account} />

            <Card>
                <CardHeader
                    title="API Tokens"
                    description="Tokens issued to your account for the REST API."
                    action={
                        tokens.length > 0 && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        '/settings/security/tokens/revoke-all',
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Revoke All
                            </Button>
                        )
                    }
                />

                <Table>
                    <THead>
                        <TR>
                            <TH>Token</TH>
                            <TH>Last Used</TH>
                            <TH>Created</TH>
                            <TH className="text-right">Actions</TH>
                        </TR>
                    </THead>

                    <TBody>
                        {tokens.length === 0 ? (
                            <TableEmpty
                                colSpan={4}
                                icon={KeyRound}
                                title="No API tokens"
                                description="Create one under Integrations to use the REST API."
                            />
                        ) : (
                            tokens.map((token) => (
                                <TR key={token.id}>
                                    <TD className="text-sm font-medium text-foreground">
                                        {token.name}
                                    </TD>
                                    <TD className="text-sm text-muted-foreground">
                                        {token.last_used_at
                                            ? formatDate(token.last_used_at)
                                            : 'Never'}
                                    </TD>
                                    <TD className="text-sm text-muted-foreground">
                                        {formatDate(token.created_at)}
                                    </TD>
                                    <TD>
                                        <div className="flex justify-end">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.delete(
                                                        `/settings/security/tokens/${token.id}`,
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Revoke
                                            </Button>
                                        </div>
                                    </TD>
                                </TR>
                            ))
                        )}
                    </TBody>
                </Table>
            </Card>

            {canViewAudit && (
                <Card>
                    <CardHeader
                        title="Audit Log"
                        description="The 50 most recent entries across every module."
                        action={
                            <div className="flex flex-wrap gap-1">
                                {AUDIT_FILTERS.map((option) => (
                                    <Button
                                        key={option.id}
                                        size="sm"
                                        variant={
                                            auditFilter === option.id ? 'primary' : 'outline'
                                        }
                                        onClick={() =>
                                            router.get(
                                                '/settings/security',
                                                { audit: option.id },
                                                { preserveScroll: true, preserveState: true },
                                            )
                                        }
                                    >
                                        {option.label}
                                    </Button>
                                ))}
                            </div>
                        }
                    />

                    <Table>
                        <THead>
                            <TR>
                                <TH>Event</TH>
                                <TH>Record</TH>
                                <TH>Detail</TH>
                                <TH>User</TH>
                                <TH>When</TH>
                            </TR>
                        </THead>

                        <TBody>
                            {auditLog.length === 0 ? (
                                <TableEmpty
                                    colSpan={5}
                                    icon={History}
                                    title="No audit entries"
                                />
                            ) : (
                                auditLog.map((entry) => (
                                    <TR key={entry.id}>
                                        <TD>
                                            <Badge variant={eventVariant(entry)}>
                                                {titleCase(entry.event)}
                                            </Badge>
                                        </TD>
                                        <TD className="text-sm text-foreground">
                                            {/* An attempt on an address that is
                                                not ours has no record to name. */}
                                            {entry.subject_id
                                                ? `${entry.subject} #${entry.subject_id}`
                                                : '—'}
                                        </TD>
                                        <TD className="max-w-xs">
                                            <p className="truncate text-xs text-muted-foreground">
                                                {entry.is_auth
                                                    ? (entry.attempted_email ?? '—')
                                                    : entry.changed.length > 0
                                                      ? entry.changed.map(titleCase).join(', ')
                                                      : '—'}
                                            </p>
                                        </TD>
                                        <TD className="text-sm text-muted-foreground">
                                            {/* Nobody is signed in during a
                                                failed attempt — show where it
                                                came from instead of "System". */}
                                            {entry.is_auth && entry.user === 'System'
                                                ? (entry.ip_address ?? '—')
                                                : entry.user}
                                        </TD>
                                        <TD className="whitespace-nowrap text-sm text-muted-foreground">
                                            {formatDate(entry.created_at, {
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

            <Card className="border-destructive/30">
                <CardHeader
                    title="Delete Account"
                    description="Permanently removes your login. This cannot be undone."
                />
                <CardBody>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="flex items-start gap-2 text-sm text-muted-foreground">
                            <TriangleAlert
                                className="mt-0.5 h-4 w-4 shrink-0 text-destructive"
                                aria-hidden="true"
                            />
                            Employee records stay for audit and payroll history — only the login
                            is removed.
                        </p>
                        <Button variant="destructive" onClick={() => setDeleteOpen(true)}>
                            Delete Account
                        </Button>
                    </div>
                </CardBody>
            </Card>

            <Modal
                show={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                title="Delete your account?"
                maxWidth="md"
            >
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        deleteForm.delete('/settings/security/account');
                    }}
                    className="space-y-4"
                >
                    <p className="text-sm text-muted-foreground">
                        Enter your password to confirm. You will be signed out immediately.
                    </p>

                    <Field label="Password" required error={deleteForm.errors.password}>
                        {({ id }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="current-password"
                                value={deleteForm.data.password}
                                onChange={(event) =>
                                    deleteForm.setData('password', event.target.value)
                                }
                                error={deleteForm.errors.password}
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setDeleteOpen(false)}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            loading={deleteForm.processing}
                        >
                            Delete Account
                        </Button>
                    </div>
                </form>
            </Modal>
        </SettingsLayout>
    );
}

/**
 * Two-factor authentication, in the three states it actually has.
 *
 * Off, half-set-up, and on — and the middle one is why this is not a single
 * toggle. Fortify writes the secret the moment somebody asks to enable 2FA and
 * only confirms it once they have typed a code from their app, so a person who
 * closed the tab has a secret on their record and no protection. Telling them
 * "enabled" there is the reading that ends with somebody believing they have a
 * second factor they never finished proving they hold.
 *
 * **Every call here goes through axios rather than Inertia's router, and that
 * is the whole reason this works.** All seven of Fortify's 2FA routes carry
 * `RequirePassword`. On an ordinary visit that middleware *redirects* to the
 * password screen, storing the URL it interrupted as `url.intended` — so
 * confirming the password sends the browser back to it with **GET**, and six
 * of those seven routes are POST or DELETE. The first click on "Set up
 * two-factor" ended in 405 Method Not Allowed. On a JSON request the same
 * middleware answers **423 Locked** instead, which is a value this component
 * can catch: it opens its own password box and re-runs the action that was
 * refused, with no navigation to lose the QR code over.
 */
function TwoFactorCard({ account }) {
    const [qr, setQr] = useState(null);
    const [codes, setCodes] = useState([]);
    const [busy, setBusy] = useState(false);
    const [code, setCode] = useState('');
    const [codeError, setCodeError] = useState('');

    // The password gate: the action that was refused, held until it is paid for.
    const [pending, setPending] = useState(null);
    const [password, setPassword] = useState('');
    const [gateError, setGateError] = useState('');
    const [gateBusy, setGateBusy] = useState(false);

    /*
     * `account.two_factor_enabled` is server state, so the card cannot decide
     * for itself that it has changed. Only that one prop is asked for again —
     * re-rendering the audit log to turn a badge from Off to On would be a
     * page's worth of work for a word.
     */
    const refresh = () => router.reload({ only: ['account'], preserveScroll: true });

    /**
     * Runs an action; if the server asks for the password first, holds the
     * action and opens the box rather than throwing the work away.
     */
    const guarded = async (action) => {
        try {
            await action();
        } catch (error) {
            if (error?.response?.status === 423) {
                setPassword('');
                setGateError('');
                // Held as a value, not called: setState treats a bare function
                // as an updater and would invoke it immediately.
                setPending(() => action);

                return;
            }

            throw error;
        }
    };

    const run = async (action) => {
        setBusy(true);

        try {
            await guarded(action);
        } finally {
            setBusy(false);
        }
    };

    const unlock = async (event) => {
        event.preventDefault();

        setGateBusy(true);
        setGateError('');

        try {
            await axios.post('/user/confirm-password', { password });

            const action = pending;

            setPending(null);
            setPassword('');

            // Back through `guarded`, so a session that expired between the
            // two requests re-opens the box instead of failing silently.
            await guarded(action);
        } catch (error) {
            setGateError(
                error?.response?.data?.errors?.password?.[0] ?? 'That password was not right.',
            );
        } finally {
            setGateBusy(false);
        }
    };

    /*
     * The QR code and the recovery codes are fetched rather than sent with the
     * page, and that is deliberate: they are the secret itself. Shipping them
     * in every Inertia payload would put them in the browser history and in any
     * page cache, on every visit to Settings, whether or not anybody was
     * setting 2FA up.
     */
    const fetchSecret = async () => {
        const [svg, recovery] = await Promise.all([
            axios.get('/user/two-factor-qr-code'),
            axios.get('/user/two-factor-recovery-codes'),
        ]);

        setQr(svg.data.svg);
        setCodes(recovery.data);
    };

    /*
     * Enabling and fetching are one guarded action rather than three, so the
     * password is asked for once and the whole setup completes on the far side
     * of it — including for somebody who lands here with a secret already on
     * their record from an abandoned attempt.
     */
    const enable = () =>
        run(async () => {
            if (!account.two_factor_started) {
                await axios.post('/user/two-factor-authentication');
            }

            await fetchSecret();
            refresh();
        });

    const showCodes = () =>
        run(async () => {
            const { data } = await axios.get('/user/two-factor-recovery-codes');

            setCodes(data);
        });

    const confirm = (event) => {
        event.preventDefault();
        setCodeError('');

        return run(async () => {
            try {
                await axios.post('/user/confirmed-two-factor-authentication', { code });
            } catch (error) {
                // A wrong code is an answer, not a failure: it is the one error
                // here the person can fix without leaving the screen.
                if (error?.response?.status === 422) {
                    setCodeError(
                        error.response.data?.errors?.code?.[0] ?? 'That code was not accepted.',
                    );

                    return;
                }

                throw error;
            }

            setCode('');
            setQr(null);
            // The codes stay on screen — this is the moment they matter, and
            // clearing them here is how somebody ends up enrolled with no way
            // back in.
            refresh();
        });
    };

    const disable = () =>
        run(async () => {
            await axios.delete('/user/two-factor-authentication');

            setQr(null);
            setCodes([]);
            setCode('');
            refresh();
        });

    return (
        <Card>
            <CardHeader
                title="Two-Factor Authentication"
                description="A code from your phone, on top of your password. This system holds salary, government numbers and bank details — a password on its own is the whole front door."
                action={
                    account.two_factor_enabled ? (
                        <Badge variant="success">On</Badge>
                    ) : (
                        <Badge variant="muted">Off</Badge>
                    )
                }
            />

            <CardBody className="space-y-4">
                {account.two_factor_enabled ? (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Signing in asks for a code from your authenticator app. Keep your
                            recovery codes somewhere you can reach without your phone.
                        </p>

                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" onClick={showCodes} loading={busy}>
                                Show recovery codes
                            </Button>
                            <Button variant="destructive" onClick={disable}>
                                Turn off
                            </Button>
                        </div>

                        {codes.length > 0 && <RecoveryCodes codes={codes} />}
                    </>
                ) : qr ? (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Scan this with an authenticator app — Google Authenticator, Authy,
                            or your password manager — then type the six-digit code it shows.
                        </p>

                        <div
                            className="inline-block rounded-lg border border-border bg-white p-3 [&>svg]:h-44 [&>svg]:w-44"
                            dangerouslySetInnerHTML={{ __html: qr }}
                        />

                        <form onSubmit={confirm} className="flex flex-wrap items-end gap-2">
                            <Field label="Code from the app" error={codeError}>
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        value={code}
                                        onChange={(event) => setCode(event.target.value)}
                                        className="w-40 tracking-[0.3em]"
                                        inputMode="numeric"
                                        maxLength={6}
                                        autoComplete="one-time-code"
                                        error={codeError}
                                    />
                                )}
                            </Field>

                            <Button type="submit" loading={busy}>
                                Confirm
                            </Button>
                            <Button type="button" variant="ghost" onClick={disable}>
                                Cancel setup
                            </Button>
                        </form>

                        {codes.length > 0 && <RecoveryCodes codes={codes} />}

                        {/* Said out loud, because the record already carries a
                            secret and the account is still unprotected. */}
                        <p className="text-xs text-warning">
                            Not on yet — it protects nothing until a code is confirmed.
                        </p>
                    </>
                ) : (
                    <>
                        {/* An abandoned attempt is named rather than hidden: the
                            record carries a secret, the account is not
                            protected, and the button below picks that same setup
                            back up rather than starting a second one. */}
                        {account.two_factor_started && (
                            <p className="text-sm text-warning">
                                A setup was started and never confirmed, so it is protecting
                                nothing yet. Carry on below.
                            </p>
                        )}

                        <Button onClick={enable} loading={busy}>
                            <KeyRound className="h-4 w-4" aria-hidden="true" />
                            {account.two_factor_started
                                ? 'Finish setting up two-factor'
                                : 'Set up two-factor'}
                        </Button>
                    </>
                )}
            </CardBody>

            <Modal
                show={pending !== null}
                onClose={() => setPending(null)}
                title="Confirm your password"
                maxWidth="sm"
            >
                <form onSubmit={unlock} className="space-y-4">
                    <p className="text-sm text-muted-foreground">
                        Changing the second factor asks for your password again — otherwise the
                        way past it is an unlocked machine and one click.
                    </p>

                    <Field label="Password" required error={gateError}>
                        {({ id }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="current-password"
                                value={password}
                                onChange={(event) => setPassword(event.target.value)}
                                error={gateError}
                                autoFocus
                            />
                        )}
                    </Field>

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={() => setPending(null)}>
                            Cancel
                        </Button>
                        <Button type="submit" loading={gateBusy}>
                            Confirm
                        </Button>
                    </div>
                </form>
            </Modal>
        </Card>
    );
}

/**
 * The codes that get somebody back in when the phone is gone.
 *
 * Shown as text to copy rather than offered as a download: a browser download
 * from this app would land in the Downloads folder of whatever machine HR
 * happened to be at, which is the one place these should not be left.
 */
function RecoveryCodes({ codes }) {
    return (
        <div className="rounded-lg border border-border bg-secondary/40 p-4">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Recovery codes
            </p>

            <ul className="grid gap-1 font-mono text-xs text-foreground sm:grid-cols-2">
                {codes.map((code) => (
                    <li key={code}>{code}</li>
                ))}
            </ul>

            <p className="mt-3 text-xs text-muted-foreground">
                Each one works once. Store them somewhere you can reach without your phone —
                they are the only way back into this account if you lose it.
            </p>
        </div>
    );
}

/**
 * The emailed second factor: one switch, and the password to move it.
 *
 * **Simpler than the authenticator card next to it, and the reason is that it
 * has one state rather than three.** There is nothing to scan, nothing to
 * confirm, and no recovery codes to keep: the address is already on the
 * account and already verified, so there is no half-set-up condition where
 * somebody holds a secret and no protection.
 *
 * **The password is asked in both directions**, and the *off* direction is the
 * one that needs it. Without that, the cheapest way past this factor is an
 * unlocked machine and one click — the attacker never needs the inbox, they
 * remove the requirement. Same argument `confirmPassword` settles for the
 * authenticator, reached with one request that carries its own proof rather
 * than a 423 handshake for a single checkbox.
 */
function EmailOtpCard({ account, mailIsLogged }) {
    const [open, setOpen] = useState(false);

    const form = useForm({ enabled: !account.otp_enabled, current_password: '' });

    const submit = (event) => {
        event.preventDefault();

        form.put('/settings/security/otp', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    const turningOn = !account.otp_enabled;

    return (
        <Card>
            <CardHeader
                title="Email Sign-In Code"
                description="A one-time code sent to your email each time you sign in. Nothing to install — it goes to the inbox you already have."
                action={
                    account.otp_enabled ? (
                        <Badge variant="success">On</Badge>
                    ) : (
                        <Badge variant="muted">Off</Badge>
                    )
                }
            />

            <CardBody className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    {account.otp_enabled
                        ? 'Signing in asks for a code sent to your email. It expires in five minutes and works once.'
                        : 'Your password alone is one secret, and it is the one most likely to be reused or phished. This asks for something your sign-in also has to be able to receive.'}
                </p>

                {/*
                 * Said before the switch is thrown, not discovered after.
                 *
                 * With the `log` mailer nothing is delivered — the code is
                 * written to storage/logs/laravel.log — so switching this on
                 * and signing out is a lockout. The card warns rather than
                 * refusing: reading the log is a legitimate way to demonstrate
                 * the feature, and refusing would make it undemonstrable on a
                 * fresh clone.
                 */}
                {mailIsLogged && turningOn && (
                    <div className="rounded-lg border border-warning/30 bg-warning/5 p-4">
                        <p className="flex items-start gap-2 text-sm font-medium text-warning">
                            <TriangleAlert
                                className="mt-0.5 h-4 w-4 shrink-0"
                                aria-hidden="true"
                            />
                            Mail is not configured to send yet.
                        </p>
                        <p className="mt-1.5 pl-6 text-sm text-muted-foreground">
                            <code>MAIL_MAILER</code> is <code>log</code>, so codes are written
                            to <code>storage/logs/laravel.log</code> instead of being delivered.
                            You can still finish signing in by reading the code out of that file
                            — and the code screen always offers a way to sign out, so this
                            cannot lock the account permanently.
                        </p>
                    </div>
                )}

                {open ? (
                    <form onSubmit={submit} className="space-y-4">
                        <Field
                            label="Confirm your password"
                            required
                            error={form.errors.current_password}
                        >
                            {({ id }) => (
                                <Input
                                    id={id}
                                    type="password"
                                    autoComplete="current-password"
                                    value={form.data.current_password}
                                    onChange={(event) =>
                                        form.setData('current_password', event.target.value)
                                    }
                                    error={form.errors.current_password}
                                    autoFocus
                                />
                            )}
                        </Field>

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                variant={turningOn ? 'default' : 'destructive'}
                                loading={form.processing}
                            >
                                {turningOn ? 'Turn on email codes' : 'Turn off email codes'}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    form.reset();
                                    setOpen(false);
                                }}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                ) : (
                    <Button
                        variant={turningOn ? 'default' : 'outline'}
                        onClick={() => setOpen(true)}
                    >
                        <Mail className="h-4 w-4" aria-hidden="true" />
                        {turningOn ? 'Turn on email codes' : 'Turn off email codes'}
                    </Button>
                )}
            </CardBody>
        </Card>
    );
}
