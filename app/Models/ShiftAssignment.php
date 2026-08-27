<?php

namespace App\Models;

use App\Exceptions\OverlappingShiftAssignmentException;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ShiftAssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link between a concrete Shift and the employee assigned to work it.
 * See .ai/08-SHIFTS.md.
 */
class ShiftAssignment extends Model
{
    /** @use HasFactory<ShiftAssignmentFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'shift_id',
        'employee_id',
        'status',
    ];

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Whether assigning $employeeId to a shift spanning
     * [$startDatetime, $endDatetime] would overlap another shift they are
     * already (non-cancelled) assigned to.
     */
    public static function overlapsForEmployee(
        string $employeeId,
        string $startDatetime,
        string $endDatetime,
        ?string $excludingShiftId = null,
    ): bool {
        return static::query()
            ->where('employee_id', $employeeId)
            ->where('status', '!=', 'cancelled')
            ->whereHas('shift', function ($query) use ($startDatetime, $endDatetime, $excludingShiftId) {
                $query->where('start_datetime', '<', $endDatetime)
                    ->where('end_datetime', '>', $startDatetime);

                if ($excludingShiftId) {
                    $query->where('id', '!=', $excludingShiftId);
                }
            })
            ->exists();
    }

    /**
     * @throws OverlappingShiftAssignmentException
     */
    public static function assertNoOverlap(
        string $employeeId,
        string $startDatetime,
        string $endDatetime,
        ?string $excludingShiftId = null,
    ): void {
        if (static::overlapsForEmployee($employeeId, $startDatetime, $endDatetime, $excludingShiftId)) {
            throw new OverlappingShiftAssignmentException($employeeId);
        }
    }
}
