<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AbsenceRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee/date row recording that the employee was absent that day,
 * and whether the absence is justified. Written from the leave_records
 * approval path (justified=true, leave_record_id set) by
 * App\Services\Leave\LeaveRecordService. See .ai/04-DOMAIN-MODEL.md. No
 * soft-delete: unlike leave_records, an absence_records row is a factual
 * trace of a date, not a decision that can be reversed.
 */
class AbsenceRecord extends Model
{
    /** @use HasFactory<AbsenceRecordFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'date',
        'leave_record_id',
        'justified',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'justified' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<LeaveRecord, $this>
     */
    public function leaveRecord(): BelongsTo
    {
        return $this->belongsTo(LeaveRecord::class);
    }
}
