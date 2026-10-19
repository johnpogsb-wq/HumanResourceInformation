<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One employee's month of time records, in their own inbox.
 *
 * **Deliberately not queued**, for the reason `LoginOtp` gives: there is no
 * queue worker on the deployment, so a queued mail would sit in the `jobs`
 * table while the screen said it had gone.
 *
 * The body carries the whole month rather than only a link, because the point
 * is that the employee can read it in Gmail on a phone without signing in.
 * The link to the calendar is there for checking it against the screen and
 * filing a correction — not as the only way to see anything.
 */
class AttendanceCalendarMail extends Mailable
{
    /** @param  array<string, mixed>  $month  AttendanceCalendar::month() */
    public function __construct(
        public readonly Employee $employee,
        public readonly array $month,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your attendance for {$this->month['label']} — PrimePower",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.attendance-calendar',
            with: [
                'company' => config('mail.from.name') ?: 'PrimePower Manpower HRIS',
                'weeks' => $this->weeks(),
                'exceptions' => collect($this->month['days'])
                    ->filter(fn (array $day) => in_array($day['status'], ['late', 'undertime', 'absent', 'incomplete'], true))
                    ->values()
                    ->all(),
            ],
        );
    }

    /**
     * The month as rows of seven, padded at both ends — an email cannot lay out
     * a CSS grid, and a table is the one layout every mail client agrees on.
     *
     * @return array<int, array<int, array<string, mixed>|null>>
     */
    private function weeks(): array
    {
        $cells = [...array_fill(0, $this->month['leading_blanks'], null), ...$this->month['days']];

        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        return array_chunk($cells, 7);
    }
}
