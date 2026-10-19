<?php

namespace App\Services;

use App\Mail\AttendanceCalendarMail;
use App\Models\AttendanceLog;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends an employee their month of time records by email.
 *
 * It goes out through the mailer in `MAIL_*` — the company Gmail the sign-in
 * codes already use — so there is one sending account to keep working, not
 * two.
 *
 * Every send that actually leaves writes an `exported` audit row: a month of
 * somebody's punches in an inbox is personal data that has left the system,
 * which is exactly what `DataAccessLogger::exported()` exists to record. The
 * address is masked on the row, the same as the OTP events.
 */
class AttendanceMailer
{
    public const SENT = 'sent';

    public const NO_ADDRESS = 'no_address';

    public const UNDELIVERABLE = 'undeliverable';

    public const FAILED = 'failed';

    /**
     * Domains that cannot receive mail by definition (RFC 2606 / RFC 6761).
     * Every seeded employee has one — Faker's `safeEmail()` — so "send to all"
     * on a demo database would otherwise hand Gmail fifty-odd messages to
     * nowhere and put fifty-odd bounce notices in the sending inbox.
     */
    private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    private const RESERVED_SUFFIXES = ['.test', '.example', '.invalid', '.localhost'];

    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly DataAccessLogger $access,
    ) {}

    /**
     * Where this employee's copy goes: the email on their 201 file, else the
     * personal inbox an administrator connected to their login.
     */
    public function recipientFor(Employee $employee): ?string
    {
        $address = trim((string) ($employee->email ?: $employee->user?->otp_email));

        return $address === '' ? null : strtolower($address);
    }

    public function isDeliverable(string $address): bool
    {
        $domain = strtolower(substr(strrchr($address, '@') ?: '', 1));

        if ($domain === '' || in_array($domain, self::RESERVED_DOMAINS, true)) {
            return false;
        }

        foreach (self::RESERVED_SUFFIXES as $suffix) {
            if (str_ends_with('.'.$domain, $suffix)) {
                return false;
            }
        }

        return true;
    }

    /** One of the class constants — the caller reports it, it is never thrown. */
    public function send(Employee $employee, Carbon $month): string
    {
        $address = $this->recipientFor($employee);

        if ($address === null) {
            return self::NO_ADDRESS;
        }

        if (! $this->isDeliverable($address)) {
            return self::UNDELIVERABLE;
        }

        $data = $this->calendar->month($employee, $month);
        $url = route('hr.timekeeping.calendar', ['employee' => $employee->id, 'month' => $data['month']]);

        try {
            Mail::to($address, $employee->full_name)->send(new AttendanceCalendarMail($employee, $data, $url));
        } catch (\Throwable $exception) {
            // The address stays out of the log; the employee number is enough
            // to find the row, and the exception says what the mailer refused.
            Log::warning('Attendance email failed', [
                'employee' => $employee->employee_number,
                'month' => $data['month'],
                'error' => $exception->getMessage(),
            ]);

            return self::FAILED;
        }

        $this->access->exported('attendance:email', AttendanceLog::class, [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'month' => $data['month'],
            'recipient' => $this->mask($address),
        ]);

        return self::SENT;
    }

    /** `ju***@gmail.com` — the same shape the sign-in code events record. */
    public function mask(string $address): string
    {
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');
        $keep = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $keep.str_repeat('*', max(1, mb_strlen($local) - mb_strlen($keep))).($domain === '' ? '' : '@'.$domain);
    }
}
