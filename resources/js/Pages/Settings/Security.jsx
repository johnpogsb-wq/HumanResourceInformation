import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { History, KeyRound, TriangleAlert } from 'lucide-react';
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
import { formatDate } from '@/lib/utils';

const titleCase = (value) =>
    String(value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

export default function Security({ account, tokens, auditLog, canViewAudit }) {
    const [deleteOpen, setDeleteOpen] = useState(false);

    const profileForm = useForm({ name: account.name, email: account.email });
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const deleteForm = useForm({ password: '' });

    const submitProfile = (event) => {
        event.preventDefault();
        profileForm.put('/settings/security/profile', { preserveScroll: true });
    };

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
            description="Your account details, password, and the API tokens issued to you."
        >
            <Card>
                <CardHeader title="Profile" description="How you appear across the system." />
                <CardBody>
                    <form onSubmit={submitProfile} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Name" required error={profileForm.errors.name}>
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        value={profileForm.data.name}
                                        onChange={(event) =>
                                            profileForm.setData('name', event.target.value)
                                        }
                                        error={profileForm.errors.name}
                                    />
                                )}
                            </Field>

                            <Field
                                label="Email"
                                required
                                hint={
                                    account.email_verified
                                        ? undefined
                                        : 'This address has not been verified.'
                                }
                                error={profileForm.errors.email}
                            >
                                {({ id }) => (
                                    <Input
                                        id={id}
                                        type="email"
                                        value={profileForm.data.email}
                                        onChange={(event) =>
                                            profileForm.setData('email', event.target.value)
                                        }
                                        error={profileForm.errors.email}
                                    />
                                )}
                            </Field>
                        </div>

                        <div className="flex items-center justify-between gap-3">
                            <p className="text-xs text-muted-foreground">
                                Signed in as{' '}
                                <Badge variant="primary">{titleCase(account.role)}</Badge> since{' '}
                                {formatDate(account.created_at)}
                            </p>
                            <Button type="submit" loading={profileForm.processing}>
                                Save
                            </Button>
                        </div>
                    </form>
                </CardBody>
            </Card>

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
                        description="The 50 most recent changes across every module."
                    />

                    <Table>
                        <THead>
                            <TR>
                                <TH>Event</TH>
                                <TH>Record</TH>
                                <TH>Changed</TH>
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
                                            <Badge
                                                variant={
                                                    entry.event === 'deleted'
                                                        ? 'destructive'
                                                        : entry.event === 'created'
                                                          ? 'success'
                                                          : 'primary'
                                                }
                                            >
                                                {entry.event}
                                            </Badge>
                                        </TD>
                                        <TD className="text-sm text-foreground">
                                            {entry.subject} #{entry.subject_id}
                                        </TD>
                                        <TD className="max-w-xs">
                                            <p className="truncate text-xs text-muted-foreground">
                                                {entry.changed.length > 0
                                                    ? entry.changed.map(titleCase).join(', ')
                                                    : '—'}
                                            </p>
                                        </TD>
                                        <TD className="text-sm text-muted-foreground">
                                            {entry.user}
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
