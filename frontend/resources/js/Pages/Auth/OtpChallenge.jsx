import { useEffect, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';

/**
 * The emailed code, between the password and the session.
 *
 * `GuestLayout` even though the reader is technically signed in: the session
 * exists but is held, and dressing this as an application screen — sidebar,
 * topbar, their name in the corner — would say they are already in. They are
 * not, and the whole point of the screen is that the difference matters.
 *
 * **Two counters, and they are not the same clock.** One is how long the code
 * still works; the other is how long until another may be sent. Showing only
 * the first leaves somebody staring at a dead field with a "Send a new code"
 * button that silently refuses; showing only the second hides the fact that
 * waiting is itself a way to fail.
 */
export default function OtpChallenge({ sent_to, expires_in, resend_in, attempts_left }) {
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const [expiresIn, setExpiresIn] = useState(expires_in ?? 0);
    const [resendIn, setResendIn] = useState(resend_in ?? 0);

    /*
     * One interval for both counters.
     *
     * Counted down rather than compared against a timestamp, which is the
     * opposite of `IdleTimeout` — and deliberately: this screen is in front of
     * somebody right now, for five minutes, not left open overnight. Drift of
     * a second does not matter here, and the server refuses a stale code
     * regardless of what the display says.
     */
    useEffect(() => {
        const tick = setInterval(() => {
            setExpiresIn((value) => Math.max(0, value - 1));
            setResendIn((value) => Math.max(0, value - 1));
        }, 1000);

        return () => clearInterval(tick);
    }, []);

    const submit = (event) => {
        event.preventDefault();
        post(route('otp.verify'));
    };

    const resend = () => {
        router.post(
            route('otp.resend'),
            {},
            {
                preserveScroll: true,
                // The two counters are server state, so they are read back
                // rather than guessed at from the click.
                onSuccess: () => router.reload({ only: ['expires_in', 'resend_in'] }),
            },
        );
    };

    return (
        <GuestLayout>
            <Head title="Sign-in code" />

            <div className="mb-4 text-sm text-muted-foreground">
                <p className="font-medium text-foreground">Check your email</p>
                <p className="mt-1">
                    A one-time code was sent to <span className="font-medium">{sent_to}</span>.
                    It works once.
                </p>
            </div>

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="code" value="Sign-in code" />

                    <TextInput
                        id="code"
                        name="code"
                        value={data.code}
                        className="mt-1 block w-full tracking-[0.4em]"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        autoFocus
                        maxLength={8}
                        onChange={(event) => setData('code', event.target.value)}
                    />

                    <InputError message={errors.code} className="mt-2" />

                    <p className="mt-2 text-xs text-muted-foreground">
                        {expiresIn > 0
                            ? `Expires in ${Math.floor(expiresIn / 60)}:${String(expiresIn % 60).padStart(2, '0')} · ${attempts_left} attempt(s) left`
                            : 'This code has expired — send a new one.'}
                    </p>
                </div>

                <div className="mt-4 flex items-center justify-between gap-3">
                    {/* Disabled *and* labelled with the wait. A button that
                        looks available and refuses is how somebody decides the
                        screen is broken. */}
                    <button
                        type="button"
                        onClick={resend}
                        disabled={resendIn > 0 || processing}
                        className="text-sm text-primary underline-offset-4 transition-colors hover:underline disabled:cursor-not-allowed disabled:text-muted-foreground disabled:no-underline"
                    >
                        {resendIn > 0 ? `Send a new code (${resendIn}s)` : 'Send a new code'}
                    </button>

                    <PrimaryButton disabled={processing}>Continue</PrimaryButton>
                </div>
            </form>

            {/*
             * The way out, and it has to be here.
             *
             * Somebody whose inbox is unreachable — wrong address on the
             * account, mail not configured on the server — would otherwise be
             * held on this screen with no action available at all. Signing out
             * is the one thing that reduces exposure rather than adding to it,
             * which is the same reason `logout` is on RequirePasswordChange's
             * allow-list as well as this middleware's.
             */}
            <div className="mt-6">
                <button
                    type="button"
                    onClick={() => router.post(route('logout'))}
                    className="text-xs text-muted-foreground underline-offset-4 transition-colors hover:text-foreground hover:underline"
                >
                    Cannot get the code? Sign out
                </button>
            </div>
        </GuestLayout>
    );
}
