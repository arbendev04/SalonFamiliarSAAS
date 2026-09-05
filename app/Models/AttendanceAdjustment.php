<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AttendanceAdjustmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The correction mechanism over an attendance_event per .ai/07-ATTENDANCE.md
 * (Flujo 2). Unlike AttendanceEvent, this model has no ORM-level
 * immutability guard: the "immutable once approved" rule is enforced at the
 * service layer (App\Services\Attendance\AttendanceAdjustmentService) — a
 * later correction always inserts a new row, it never edits an
 * already-approved/rejected one.
 *
 * @property array<string, mixed>|null $original_value
 * @property array<string, mixed> $corrected_value
 */
class AttendanceAdjustment extends Model
{
    /** @use HasFactory<AttendanceAdjustmentFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'original_event_id',
        'employee_id',
        'type',
        'original_value',
        'corrected_value',
        'reason',
        'requested_by',
        'approved_by',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_value' => 'array',
            'corrected_value' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AttendanceEvent, $this>
     */
    public function originalEvent(): BelongsTo
    {
        return $this->belongsTo(AttendanceEvent::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
