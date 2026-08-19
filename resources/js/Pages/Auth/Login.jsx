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

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
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
                        <LogoMark className="h-8 w-8" />
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
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                autoComplete="username"
                                autoFocus
                                error={errors.email}
                                onChange={(event) => setData('email', event.target.value)}
                            />
                            <InputError message={errors.email} />
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

                        <label className="flex w-fit cursor-pointer items-center gap-2">
                            <input
                                type="checkbox"
                                name="remember"
                                checked={data.remember}
                                onChange={(event) => setData('remember', event.target.checked)}
                                className="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-ring focus:ring-offset-0"
                            />
                            <span className="text-sm text-muted-foreground">Remember me</span>
                        </label>

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
