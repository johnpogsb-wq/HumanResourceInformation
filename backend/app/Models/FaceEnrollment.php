<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The face an employee punches the time clock with: a few 128-number
 * templates taken by HR with the employee in front of the camera.
 *
 * A template is biometric data — sensitive personal information under
 * RA 10173 — so it is encrypted at rest and kept out of the audit trail.
 */
class FaceEnrollment extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    /** @var array<int, string> */
    protected $auditExclude = ['descriptors'];

    protected $hidden = ['descriptors'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function enroller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    protected function casts(): array
    {
        return [
            'descriptors' => 'encrypted:array',
            'consented_at' => 'datetime',
        ];
    }
}
