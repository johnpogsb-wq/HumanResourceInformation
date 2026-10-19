<?php

/*
 * The face-verified time clock (Time & Attendance).
 *
 * The face is read on the employee's own device: the browser turns the camera
 * frame into a 128-number template with face-api, and only that template and
 * one small snapshot are sent here. No image goes to a third party.
 */
return [

    /*
     * How far apart two templates may be and still be the same person, as a
     * Euclidean distance. face-api's own examples use 0.6; 0.5 is stricter,
     * which costs the occasional retry in bad light and buys fewer strangers
     * accepted. Lower is stricter.
     */
    'match_threshold' => (float) env('TIMECLOCK_MATCH_THRESHOLD', 0.5),

    /*
     * On the kiosk the face has to say *who*, not only "yes": the nearest
     * enrolled face must beat the next nearest by at least this much, or the
     * punch is refused as too close to call.
     */
    'identify_margin' => (float) env('TIMECLOCK_IDENTIFY_MARGIN', 0.06),

    // How many templates HR takes at enrolment. More covers more angles.
    'enroll_samples' => 3,

    /*
     * Ask the employee to blink before the punch is taken, so a printed photo
     * held up to the camera does not clock somebody in. Checked in the
     * browser, so it raises the bar rather than closing the door — the
     * snapshot kept with every punch is what a disputed day is checked
     * against.
     */
    'require_blink' => (bool) env('TIMECLOCK_REQUIRE_BLINK', true),

    /*
     * A time-in older than this with no time-out is treated as abandoned: it
     * stays on the DTR as incomplete for HR to correct, and the next time-in
     * opens a new day rather than being refused as "already timed in".
     */
    'max_shift_hours' => 16,

    /*
     * Punch snapshots are biometric data, so they are not kept forever.
     * `php artisan timeclock:prune-snapshots` deletes the pictures older than
     * this; the punch rows and their times stay.
     */
    'snapshot_retention_days' => (int) env('TIMECLOCK_SNAPSHOT_RETENTION_DAYS', 90),

    'snapshot_disk' => 'local',

];
