<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records who *took* personal data out, into the same `audit_logs` table.
 *
 * The system already answers two of the three questions an auditor asks.
 * `Auditable` answers "who changed this record"; `RecordAuthenticationEvents`
 * answers "who was in the system". Neither answers **"who read it"** — and for
 * an HRIS that is the question with teeth. Every access gate in Modules 1, 2,
 * and 4 was already correct; what was missing was any trace afterwards.
 *
 * Two things are worth a row, and they are not the same size:
 *
 * - **`accessed`** — one person opened one 201-file document. That file is a
 *   photograph of somebody's PhilSys ID or NBI clearance.
 * - **`exported`** — a CSV left the system carrying many people at once. The
 *   BIR alphalist alone holds every employee's TIN, SSS number, and annual
 *   pay. Without a row here, a full extract of the workforce's government
 *   numbers leaves no trace at all.
 *
 * Under RA 10173 a personal information controller has to be able to account
 * for how personal data was processed. "We gated it correctly" is only half an
 * answer; this is the other half.
 */
class DataAccessLogger
{
    public const EVENT_ACCESSED = 'accessed';

    public const EVENT_EXPORTED = 'exported';

    /** Both read events, for filtering the Security screen's log by kind. */
    public const EVENTS = [self::EVENT_ACCESSED, self::EVENT_EXPORTED];

    /**
     * One person opened one record's file.
     *
     * @param  string  $how  which route served it — `download` or `preview`,
     *                       kept apart because one leaves a copy on a machine
     *                       and the other does not.
     * @param  array<string, mixed>  $context
     */
    public function accessed(Model $subject, string $how, array $context = []): void
    {
        $this->write(
            event: self::EVENT_ACCESSED,
            type: $subject::class,
            id: $subject->getKey(),
            details: ['how' => $how, ...$context],
        );
    }

    /**
     * A report left the system carrying more than one person.
     *
     * `auditable_id` is null and `auditable_type` is not, which is the same
     * split a failed sign-in uses: an export is about many rows, so there is
     * no single id to point at, but the *kind* of record taken is always
     * known and is the first thing anyone reading the log wants.
     *
     * @param  string  $report  what was taken, e.g. "compliance:alphalist"
     * @param  class-string  $subject  the model the rows came from
     * @param  array<string, mixed>  $context  the filters it was taken under —
     *                                         the period and scope are what
     *                                         make the row answer anything
     */
    public function exported(string $report, string $subject, array $context = []): void
    {
        $this->write(
            event: self::EVENT_EXPORTED,
            type: $subject,
            id: null,
            details: ['report' => $report, ...$context],
        );
    }

    /** @param array<string, mixed> $details */
    private function write(string $event, ?string $type, ?int $id, array $details): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_type' => $type,
            'auditable_id' => $id,
            'event' => $event,
            'old_values' => null,
            // The detail goes in `new_values` rather than a column of its own:
            // the table is shared with model changes and the Security screen
            // already knows how to render that side.
            'new_values' => $details,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
