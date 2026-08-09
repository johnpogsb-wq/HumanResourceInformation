<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const EMPLOYMENT_STATUSES = [
        'regular',
        'probationary',
        'contractual',
        'project-based',
        'resigned',
        'terminated',
    ];

    public const STATUSES = ['active', 'inactive', 'on_leave'];

    protected $guarded = ['id'];

    protected $appends = ['full_name'];

    // --- Relationships -----------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    // --- Accessors ---------------------------------------------------------

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name ? mb_substr($this->middle_name, 0, 1).'.' : null,
            $this->last_name,
            $this->suffix,
        ])));
    }

    // --- Scopes ------------------------------------------------------------

    /** Matches name, employee number, or email. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', $term).'%';

        // `ilike` is Postgres-only; sqlite's LIKE is already case-insensitive.
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function (Builder $inner) use ($like, $operator) {
            $inner->where('first_name', $operator, $like)
                ->orWhere('last_name', $operator, $like)
                ->orWhere('middle_name', $operator, $like)
                ->orWhere('employee_number', $operator, $like)
                ->orWhere('email', $operator, $like);
        });
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->search($filters['search'] ?? null)
            ->when(
                $filters['department_id'] ?? null,
                fn (Builder $q, $value) => $q->where('department_id', $value),
            )
            ->when(
                $filters['employment_status'] ?? null,
                fn (Builder $q, $value) => $q->where('employment_status', $value),
            )
            ->when(
                $filters['status'] ?? null,
                fn (Builder $q, $value) => $q->where('status', $value),
            );
    }

    /** Next sequential employee number, e.g. PPM-2026-0007. */
    public static function nextEmployeeNumber(): string
    {
        $year = now()->year;
        $prefix = "PPM-{$year}-";

        $latest = static::withTrashed()
            ->where('employee_number', 'like', $prefix.'%')
            ->orderByDesc('employee_number')
            ->value('employee_number');

        $sequence = $latest ? ((int) substr($latest, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'date_hired' => 'date',
            'date_regularized' => 'date',
            'date_separated' => 'date',
            'license_expiry' => 'date',
            'basic_salary' => 'decimal:2',
        ];
    }
}
