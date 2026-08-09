<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use Auditable, HasFactory;

    /** Filed, awaiting the supervisor. */
    public const STATUS_PENDING = 'pending';

    /** Supervisor signed off; HR still has to confirm and deduct credits. */
    public const STATUS_SUPERVISOR_APPROVED = 'supervisor_approved';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUPERVISOR_APPROVED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses that still hold a claim on the employee's calendar. */
    public const OPEN_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SUPERVISOR_APPROVED,
        self::STATUS_APPROVED,
    ];

    protected $guarded = ['id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function hr(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                $filters['status'] ?? null,
                fn (Builder $q, $value) => $q->where('status', $value),
            )
            ->when(
                $filters['leave_type_id'] ?? null,
                fn (Builder $q, $value) => $q->where('leave_type_id', $value),
            )
            ->when(
                $filters['employee_id'] ?? null,
                fn (Builder $q, $value) => $q->where('employee_id', $value),
            )
            ->when(
                $filters['from'] ?? null,
                fn (Builder $q, $value) => $q->whereDate('end_date', '>=', $value),
            )
            ->when(
                $filters['to'] ?? null,
                fn (Builder $q, $value) => $q->whereDate('start_date', '<=', $value),
            );
    }

    /** Overlaps the given range — used for the calendar and conflict checks. */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from);
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days_requested' => 'decimal:2',
            'is_half_day' => 'boolean',
            'supervisor_acted_at' => 'datetime',
            'hr_acted_at' => 'datetime',
        ];
    }
}
