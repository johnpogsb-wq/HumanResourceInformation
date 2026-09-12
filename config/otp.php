<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login OTP
    |--------------------------------------------------------------------------
    |
    | The emailed second factor. Every number here is a trade-off between a
    | window an attacker can work inside and a window a person can actually
    | finish in, so they live in config rather than in the service — tightening
    | one is an edit here, not a code change.
    |
    */

    /*
     * Six digits, because that is what people expect an OTP to look like and
     * what they will copy from an email without mistyping. It is only
     * defensible alongside `max_attempts` below: a million combinations is a
     * lot for a person and nothing for a script.
     */
    'length' => (int) env('OTP_LENGTH', 6),

    /*
     * Long enough to find the email, short enough that a code read off a
     * shoulder or a shared screen is stale before it is useful. Five minutes
     * is the figure banks and telcos here have trained people to expect.
     */
    'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),

    /*
     * Five wrong answers burn the code and force a new one. This is what makes
     * six digits a factor rather than a formality — without it the expiry
     * window is simply how long a script has to try every combination.
     */
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    /*
     * The gap before another code may be sent. It paces two different abuses
     * with one number: somebody hammering "resend" to fill another person's
     * inbox, and somebody requesting codes in bulk to see which addresses
     * exist.
     */
    'resend_after_seconds' => (int) env('OTP_RESEND_AFTER_SECONDS', 60),

    /*
     * Whether a new account starts with the emailed factor on.
     *
     * False, and deliberately: a default that holds every login behind a code
     * is only safe once mail is known to deliver, and on a fresh clone
     * `MAIL_MAILER` is `log`. Turn it on per account from Settings > Security,
     * or set this once mail is configured.
     */
    'default_enabled' => (bool) env('OTP_DEFAULT_ENABLED', false),

];
