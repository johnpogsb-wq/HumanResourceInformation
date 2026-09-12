<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\LoginOtp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * The emailed one-time code: issuing it, and deciding whether an answer is it.
 *
 * Every rule about the code lives here and nothing else knows how one is made
 * or checked — the middleware only asks whether this session has passed, and
 * the controller only forwards an answer. That split is what lets the whole of
 * it be tested without a browser, the same shape `AttendanceCalculator` and
 * the other rule engines take.
 *
 * **An SMS channel was built here and taken back out, and that is worth
 * knowing before anybody builds it again.** It worked — a `SmsSender` with
 * Semaphore and Twilio drivers, the number read off the 201 file, E.164
 * conversion at the edge, and an email fallback for accounts with no usable
 * number. What killed it was not the code: **every SMS gateway reachable from
 * the Philippines is prepaid**, so the channel has a running cost and a day it
 * stops working, and two of the accounts on this system had no phone number at
 * all — including the administrator's. Email costs nothing, needs no account,
 * and its address *is* the login. If SMS comes back, it comes back beside this
 * rather than instead of it.
 *
 * **The code is never stored, only its hash.** A live six-digit code sitting
 * in plaintext would make that column a better target than the password hash
 * beside it: a password hash cannot be replayed and a plaintext OTP can. The
 * only place the code itself exists is the notification, in transit.
 */
class OtpService
{
    /** Where a verified session is remembered. */
    public const SESSION_KEY = 'otp.verified_at';

    /**
     * Issues a fresh code and emails it.
     *
     * The attempt counter resets here rather than on a failed answer: a new
     * code is a new question, and carrying the old count over would let five
     * wrong guesses spread across two codes lock somebody out of a code they
     * had only just been sent.
     */
    public function send(User $user): void
    {
        $code = $this->generate();

        $user->forceFill([
            'otp_code_hash' => Hash::make($code),
            'otp_expires_at' => Carbon::now()->addSeconds($this->ttl()),
            'otp_sent_at' => Carbon::now(),
            'otp_attempts' => 0,
        ])->save();

        $user->notify(new LoginOtp($code, $this->ttl()));
    }

    /**
     * Where the code went, obscured for a screen shown before sign-in.
     *
     * Lives here rather than on the controller — the one thing kept from the
     * SMS work, because the service is what knows where a code was sent and a
     * controller asking it twice would be two answers to one question.
     */
    public function destinationFor(User $user): string
    {
        return $this->maskEmail((string) $user->email);
    }

    /**
     * Whether another code may be sent yet.
     *
     * One number paces two different abuses: somebody hammering "resend" to
     * fill another person's inbox, and somebody requesting codes in bulk to
     * learn which addresses exist.
     */
    public function canResend(User $user): bool
    {
        return $this->secondsUntilResend($user) === 0;
    }

    public function secondsUntilResend(User $user): int
    {
        if ($user->otp_sent_at === null) {
            return 0;
        }

        $ready = $user->otp_sent_at->addSeconds(
            (int) config('otp.resend_after_seconds', 60),
        );

        return max(0, Carbon::now()->diffInSeconds($ready, false));
    }

    /**
     * Checks an answer, and consumes the code either way.
     *
     * **A wrong answer costs an attempt and a right one clears the code**, so
     * a code cannot be replayed from a browser history entry or a second tab.
     * Past `max_attempts` the code is burned rather than the account locked:
     * locking would hand anybody who knows an email address a way to keep its
     * owner out, which is a denial of service dressed as a security control.
     */
    public function verify(User $user, string $answer): bool
    {
        if (! $this->hasLiveCode($user)) {
            return false;
        }

        if ($user->otp_attempts >= $this->maxAttempts()) {
            $this->clear($user);

            return false;
        }

        // Counted before the comparison, so a request that dies mid-check
        // still costs the attempt rather than being free to retry.
        $user->forceFill(['otp_attempts' => $user->otp_attempts + 1])->save();

        if (! Hash::check($this->normalise($answer), (string) $user->otp_code_hash)) {
            // The last attempt takes the code with it, so the next screen
            // offers a fresh one rather than a field that cannot succeed.
            if ($user->otp_attempts >= $this->maxAttempts()) {
                $this->clear($user);
            }

            return false;
        }

        $this->clear($user);

        return true;
    }

    /** Whether an unexpired code is outstanding. */
    public function hasLiveCode(User $user): bool
    {
        return $user->otp_code_hash !== null
            && $user->otp_expires_at !== null
            && $user->otp_expires_at->isFuture();
    }

    /** How many answers are left before this code is burned. */
    public function attemptsLeft(User $user): int
    {
        return max(0, $this->maxAttempts() - (int) $user->otp_attempts);
    }

    /**
     * Forgets the outstanding code.
     *
     * Called on success, on exhaustion, and when the factor is switched off —
     * a code left behind after any of those is a credential nobody is
     * expecting to still work.
     */
    public function clear(User $user): void
    {
        $user->forceFill([
            'otp_code_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
        ])->save();
    }

    /** `ju***@gmail.com` — enough to recognise, not enough to harvest. */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $keep = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $keep.str_repeat('*', max(1, mb_strlen($local) - mb_strlen($keep)))
            .($domain === '' ? '' : '@'.$domain);
    }

    /**
     * A uniformly random code of the configured length.
     *
     * `random_int` rather than `rand`, because this is a credential: the
     * Mersenne Twister behind `rand()` is predictable from previous output,
     * which for a code guarding a payroll system is the whole attack.
     * Zero-padded, so a leading zero is not silently a five-digit code.
     */
    private function generate(): string
    {
        $length = max(4, (int) config('otp.length', 6));

        return str_pad(
            (string) random_int(0, (10 ** $length) - 1),
            $length,
            '0',
            STR_PAD_LEFT,
        );
    }

    /** Spaces and dashes are what people paste; they are not a wrong answer. */
    private function normalise(string $answer): string
    {
        return preg_replace('/\D/', '', $answer) ?? '';
    }

    private function ttl(): int
    {
        return max(30, (int) config('otp.ttl_seconds', 300));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('otp.max_attempts', 5));
    }
}
