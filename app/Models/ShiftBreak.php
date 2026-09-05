<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ShiftBreakFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A planned rest window inside a Shift — distinct from the real
 * BREAK_START/BREAK_END attendance events (.ai/07-ATTENDANCE.md). A split
 * shift is modeled as a single Shift with one of these covering the
 * unworked middle window (.ai/08-SHIFTS.md).
 *
 * @property Carbon $planned_start
 * @property Carbon $planned_end
 */
class ShiftBreak extends Model
{
    /** @use HasFactory<ShiftBreakFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'shift_id',
        'planned_start',
        'planned_end',
        'paid',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_start' => 'datetime',
            'planned_end' => 'datetime',
            'paid' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
