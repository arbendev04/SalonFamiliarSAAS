<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recalculable derived cache: the planned-vs-worked outcome for one
 * employee on one date, produced by the time calculation engine from
 * shifts, net attendance events, and the labor rule version in force.
 * Regenerated wholesale via updateOrCreate() on (employee_id, date) —
 * never patched incrementally (ADR-014) — so, unlike AttendanceEvent, this
 * table is not immutable and carries no soft-delete: deletion isn't a
 * concept that applies to a value that is always fully recomputed.
 *
 * @property Carbon $date
 * @property array<string, mixed> $planned_json
 * @property array<string, mixed> $worked_json
 * @property array<string, mixed> $justification_json
 * @property Carbon $calculated_at
 */
class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'date',
        'planned_json',
        'worked_json',
        'ordinary_minutes',
        'overtime_candidate_minutes',
        'missing_minutes',
        'justified_minutes',
        'justification_json',
        'rule_version_id',
        'calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'planned_json' => 'array',
            'worked_json' => 'array',
            'justification_json' => 'array',
            'calculated_at' => 'datetime',
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
     * @return BelongsTo<LaborRuleVersion, $this>
     */
    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(LaborRuleVersion::class, 'rule_version_id');
    }
}
