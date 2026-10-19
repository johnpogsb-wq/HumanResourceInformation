<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One press of the time clock, as it happened.
 *
 * The day in `attendance_logs` is computed and can be corrected; these rows
 * are not, and nothing updates them. They are what a disputed day is checked
 * against: the time the server stamped, how closely the face matched, and the
 * picture the camera took.
 */
class AttendancePunch extends Model
{
    public const ACTION_TIME_IN = 'time_in';

    public const ACTION_BREAK_START = 'break_start';

    public const ACTION_BREAK_END = 'break_end';

    public const ACTION_TIME_OUT = 'time_out';

    public const ACTIONS = [
        self::ACTION_TIME_IN, self::ACTION_BREAK_START, self::ACTION_BREAK_END, self::ACTION_TIME_OUT,
    ];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'attendance_log_id');
    }

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'match_distance' => 'float',
        ];
    }
}
