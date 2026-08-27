<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A concrete, dated shift instance — generated from a WorkScheduleTemplate
 * or created manually (double shifts, exceptional coverage). See
 * .ai/08-SHIFTS.md.
 */
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'template_id',
        'date',
        'start_datetime',
        'end_datetime',
        'type',
        'crosses_midnight',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'crosses_midnight' => 'boolean',
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
     * @return BelongsTo<WorkScheduleTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleTemplate::class, 'template_id');
    }

    /**
     * @return HasMany<ShiftAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /**
     * @return HasMany<ShiftBreak, $this>
     */
    public function breaks(): HasMany
    {
        return $this->hasMany(ShiftBreak::class);
    }
}
