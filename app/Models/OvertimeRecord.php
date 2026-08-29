<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\OvertimeRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An overtime candidate detected for a single shift, moving through the
 * 4-state lifecycle detected/requested/authorized-or-rejected/paid via
 * App\Services\Overtime\OvertimeRecordService. `detected` rows are created
 * by TimeCalculationEngine (Fase 8, section D) — this model has no factory
 * default beyond that state. See .ai/04-DOMAIN-MODEL.md.
 */
class OvertimeRecord extends Model
{
    /** @use HasFactory<OvertimeRecordFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'shift_id',
        'detected_minutes',
        'requested_minutes',
        'authorized_minutes',
        'status',
    ];

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
