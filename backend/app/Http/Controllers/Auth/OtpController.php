<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The code screen, and the two things that can happen on it.
 *
 * Thin on purpose: every rule about what a code is, how long it lives, and how
 * many answers it takes belongs to `OtpService`, which is why the whole of it
 * can be tested without a browser. This decides what the screen is told and
 * where a decision sends the reader.
 */
class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Reachable directly, so it has to cope with being opened by somebody
        // who does not need it — otherwise it is a screen with no way off.
        if (! $user->otp_enabled || $request->session()->has(OtpService::SESSION_KEY)) {
            return redirect()->intended(route('dashboard'));
        }

        return Inertia::render('Auth/OtpChallenge', [
            // Where it went, obscured. It is the reader's own inbox or phone,
            // so saying which is the point — but a screen reached before the
            // second factor is cleared is the wrong place to print either in
            // full.
            'sent_to' => $this->otp->destinationFor($user),
            'expires_in' => $this->otp->hasLiveCode($user)
                ? max(0, Carbon::now()->diffInSeconds($user->otp_expires_at, false))
                : 0,
            'resend_in' => $this->otp->secondsUntilResend($user),
            'attempts_left' => $this->otp->attemptsLeft($user),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Loose on shape and strict on the answer: people paste codes with
            // spaces in them, and the service strips non-digits before it
            // compares. A format rule here would refuse a correct code.
            'code' => ['required', 'string', 'max:16'],
        ]);

        $user = $request->user();

        if (! $this->otp->verify($user, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => $this->otp->hasLiveCode($user->fresh())
                    ? 'That code is not right. '.$this->otp->attemptsLeft($user->fresh()).' attempt(s) left.'
                    : 'That code has expired or been used up. Send a new one.',
            ]);
        }

        /*
         * A fresh session id on the way through, for the same reason Laravel
         * regenerates on login: the id that existed before the factor was
         * cleared may have been seen by whoever had the password.
         */
        $request->session()->regenerate();
        $request->session()->put(OtpService::SESSION_KEY, Carbon::now()->toIso8601String());

        return redirect()->intended(route('dashboard'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Refused rather than silently ignored: a button that appears to work
        // and sends nothing is how somebody waits for an email that was never
        // going to arrive.
        if (! $this->otp->canResend($user)) {
            throw ValidationException::withMessages([
                'code' => 'A code was just sent. Try again in '
                    .$this->otp->secondsUntilResend($user).' second(s).',
            ]);
        }

        $this->otp->send($user);

        return back()->with('success', 'A new code is on its way.');
    }
}
