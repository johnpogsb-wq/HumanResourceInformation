import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { LogoMark } from '@/Components/layout/PrimePowerLogo';
import { Button, Input, InputError, Label } from '@/Components/ui';

/**
 * The one page an unauthenticated visitor sees.
 *
 * Split layout: the form on the left, the company mark on the right — which
 * collapses to the form alone below `lg`, since the artwork is decoration and
 * a phone should not have to scroll past it to sign in.
 *
 * Deliberately absent, and all three for the same reason — this system does
 * not have the thing the control implies:
 *
 *  - **Social sign-in.** There is no Apple/Google/Meta provider configured,
 *    and logins are provisioned by HR against an employee record. A button
 *    that cannot work is worse than no button.
 *  - **A role picker.** Roles come from the account, assigned by HR. A
 *    dropdown at sign-in either does nothing or implies you pick your own
 *    permissions.
 *  - **A sign-up link.** Self-registration is disabled by design; HR creates
 *    logins from the employee form.
 */
export default function Login({ status, canResetPassword }) {
    const brand = usePage().props.brand ?? {};
    const name = brand.name ?? 'PrimePower';

    // `remember` is not sent at all, rather than sent as false: Fortify reads
    // it off the request, and a key that is never there cannot be flipped on
    // by anything the browser does.
    const { data, setData, post, processing, errors, reset } = useForm({
        username: '',
        password: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-background p-4 sm:p-6">
            <Head title="Log in" />

            <div className="grid w-full max-w-4xl overflow-hidden rounded-xl border border-border bg-card shadow-lg lg:grid-cols-2">
                {/* Form */}
                <div className="px-6 py-10 sm:px-10">
                    <div className="mb-8 flex items-center justify-center gap-2.5">
                        {/* Big enough for the artwork's own "PRIMEPOWER" to read, which it\n                            does not at sidebar size. */}
                        <LogoMark className="h-20 w-20" />
                        <span className="text-lg font-bold tracking-tight text-logo-primary">
                            {name.toUpperCase()}
                        </span>
                    </div>

                    <div className="mb-7 text-center">
                        <h1 className="text-2xl font-bold tracking-tight text-foreground">
                            Welcome back
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Login to your {name} account
                        </p>
                    </div>

                    {status && (
                        <p
                            role="status"
                            className="mb-5 rounded-md border border-success/20 bg-success/10 px-3 py-2 text-sm font-medium text-success"
                        >
                            {status}
                        </p>
                    )}

                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <Label htmlFor="username">Username</Label>
                            <Input
                                id="username"
                                type="text"
                                name="username"
                                value={data.username}
                                autoComplete="username"
                                autoCapitalize="none"
                                spellCheck={false}
                                autoFocus
                                error={errors.username}
                                onChange={(event) => setData('username', event.target.value)}
                            />
                            <InputError message={errors.username} />
                        </div>

                        <div>
                            <div className="flex items-baseline justify-between gap-3">
                                <Label htmlFor="password" className="mb-0">
                                    Password
                                </Label>
                                {canResetPassword && (
                                    <Link
                                        href={route('password.request')}
                                        className="text-xs font-medium text-primary hover:underline"
                                    >
                                        Forgot your password?
                                    </Link>
                                )}
                            </div>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                autoComplete="current-password"
                                className="mt-1.5"
                                error={errors.password}
                                onChange={(event) => setData('password', event.target.value)}
                            />
                            <InputError message={errors.password} />
                        </div>

                        {/* No "Remember me", and its absence is load-bearing.
                            The box issued a long-lived cookie that signs the
                            holder back in after the session cookie has gone —
                            which is exactly what the ten-minute idle timeout
                            exists to prevent. Left in, an unattended machine
                            would sign itself back in on the next click and the
                            timeout would be theatre. */}
                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            loading={processing}
                            disabled={processing}
                        >
                            Login
                        </Button>
                    </form>

                    <p className="mt-8 text-center text-xs text-muted-foreground">
                        Accounts are issued by HR. Contact your HR administrator for access.
                    </p>
                </div>

                {/* Brand panel. Hidden below lg — it is decoration, and a phone
                    should not scroll past it to reach the form. */}
                <div className="hidden place-items-center border-l border-border bg-card p-10 lg:grid">
                    <img
                        src="/images/logo.png"
                        alt=""
                        className="max-h-72 w-full object-contain"
                    />
                </div>
            </div>
        </div>
    );
}
