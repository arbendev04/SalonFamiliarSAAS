<?php

namespace App\Models;

use App\Exceptions\AmbiguousContractException;
use App\Exceptions\AmbiguousScheduleException;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A worker within a company. Contract, salary, and payroll data live in
 * separate entities (see .ai/04-DOMAIN-MODEL.md) — this holds only basic
 * biographical/employment data.
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'full_name',
        'document_type',
        'national_id',
        'birth_date',
        'email',
        'phone',
        'address',
        'hire_date',
        'termination_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'hire_date' => 'date:Y-m-d',
            'termination_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<EmploymentContract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class);
    }

    /**
     * @return HasOne<PayrollInformation, $this>
     */
    public function payrollInformation(): HasOne
    {
        return $this->hasOne(PayrollInformation::class);
    }

    /**
     * @throws AmbiguousContractException
     */
    public function activeContractAt(CarbonInterface $date): ?EmploymentContract
    {
        return EmploymentContract::activeForEmployeeAt($this->id, $date);
    }

    /**
     * @return HasMany<EmployeeSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    /**
     * @return HasMany<ShiftAssignment, $this>
     */
    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /**
     * @return HasMany<AttendanceEvent, $this>
     */
    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    /**
     * @return HasMany<AttendanceAdjustment, $this>
     */
    public function attendanceAdjustments(): HasMany
    {
        return $this->hasMany(AttendanceAdjustment::class);
    }

    /**
     * @return HasMany<AttendanceRecord, $this>
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * @return HasMany<LeaveRecord, $this>
     */
    public function leaveRecords(): HasMany
    {
        return $this->hasMany(LeaveRecord::class);
    }

    /**
     * @return HasMany<OvertimeRecord, $this>
     */
    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class);
    }

    /**
     * @throws AmbiguousScheduleException
     */
    public function activeScheduleAt(CarbonInterface $date): ?EmployeeSchedule
    {
        return EmployeeSchedule::activeForEmployeeAt($this->id, $date);
    }
}
