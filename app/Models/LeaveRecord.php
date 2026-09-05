<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\LeaveRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A request for leave (vacation, unpaid leave, etc.) by an employee, over a
 * date range, moving through pending/approved/rejected per
 * App\Services\Leave\LeaveRecordService. See .ai/04-DOMAIN-MODEL.md.
 *
 * @property Carbon $date_from
 * @property Carbon $date_to
 */
class LeaveRecord extends Model
{
    /** @use HasFactory<LeaveRecordFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'leave_type_id',
        'date_from',
        'date_to',
        'status',
        'approved_by',
        'document_ref',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
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
     * @return BelongsTo<LeaveType, $this>
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
