import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';

/**
 * The second factor, between the password and the session.
 *
 * One screen for both answers — the six-digit code from the authenticator app,
 * and a recovery code — because they are the same question reached two ways:
 * prove you still hold the factor. Somebody whose phone is flat is having a
 * bad enough day without being sent to find a second page.
 *
 * The two are separate *fields* rather than one box that guesses, because
 * Fortify reads them as separate inputs and a recovery code is not six digits.
 * Only one is ever submitted: switching clears the other, so a stale value
 * cannot ride along and fail the challenge for a reason nobody can see.
 */
export default function TwoFactorChallenge() {
    const [usingRecovery, setUsingRecovery] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        recovery_code: '',
    });

    const submit = (event) => {
        event.preventDefault();

        post(route('two-factor.login'), {
            onFinish: () => reset('code', 'recovery_code'),
        });
    };

    const toggle = () => {
        // Clear whichever field is being left behind — see the note above.
        reset('code', 'recovery_code');
        setUsingRecovery((current) => !current);
    };

    return (
        <GuestLayout>
            <Head title="Two-Factor Confirmation" />

            <div className="mb-4 text-sm text-gray-600">
                {usingRecovery
                    ? 'Enter one of the recovery codes you saved when you set this up. Each one works once.'
                    : 'Enter the six-digit code from your authenticator app.'}
            </div>

            <form onSubmit={submit}>
                {usingRecovery ? (
                    <div>
                        <InputLabel htmlFor="recovery_code" value="Recovery code" />

                        <TextInput
                            id="recovery_code"
                            type="text"
                            name="recovery_code"
                            value={data.recovery_code}
                            className="mt-1 block w-full"
                            autoComplete="one-time-code"
                            isFocused
                            onChange={(event) => setData('recovery_code', event.target.value)}
                        />

                        <InputError message={errors.recovery_code} className="mt-2" />
                    </div>
                ) : (
                    <div>
                        <InputLabel htmlFor="code" value="Authentication code" />

                        <TextInput
                            id="code"
                            type="text"
                            name="code"
                            value={data.code}
                            className="mt-1 block w-full tracking-[0.3em]"
                            /*
                             * `one-time-code` is what lets a phone offer the
                             * code from its own notification, and `numeric`
                             * brings up the number pad rather than a keyboard
                             * somebody has to switch.
                             */
                            autoComplete="one-time-code"
                            inputMode="numeric"
                            maxLength={6}
                            isFocused
                            onChange={(event) => setData('code', event.target.value)}
                        />

                        <InputError message={errors.code} className="mt-2" />
                    </div>
                )}

                <div className="mt-4 flex items-center justify-end gap-4">
                    <button
                        type="button"
                        onClick={toggle}
                        className="text-sm text-gray-600 underline hover:text-gray-900"
                    >
                        {usingRecovery ? 'Use an authenticator code' : 'Use a recovery code'}
                    </button>

                    <PrimaryButton disabled={processing}>Log in</PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
