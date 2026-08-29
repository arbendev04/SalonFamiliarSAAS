<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\NoveltyRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A concrete occurrence of a novelty type over a date range for an employee,
 * mirroring the status of whatever originated it (`source_type`/
 * `source_id`: leave_records, overtime_records, or attendance_adjustments).
 * source_type/source_id are plain validated attributes, not an Eloquent
 * morphTo() relation: they point at three tables that don't share a common
 * interface/base, and this codebase has no other polymorphic-by-convention
 * precedent (no Relation::morphMap() wiring anywhere else) to extend. See
 * .ai/04-DOMAIN-MODEL.md. No soft-delete: like absence_records, this is a
 * factual trace, not a reversible decision of its own.
 */
class NoveltyRecord extends Model
{
    /** @use HasFactory<NoveltyRecordFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'novelty_type_id',
        'date_from',
        'date_to',
        'source_type',
        'source_id',
        'status',
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
     * @return BelongsTo<NoveltyType, $this>
     */
    public function noveltyType(): BelongsTo
    {
        return $this->belongsTo(NoveltyType::class);
    }
}
