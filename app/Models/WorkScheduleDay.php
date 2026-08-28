<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WorkScheduleDayFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single day-of-week rule within a WorkScheduleTemplate. day_of_week
 * follows Carbon's convention (0 = Sunday .. 6 = Saturday) so shift
 * generation can match it directly against Carbon::dayOfWeek.
 */
class WorkScheduleDay extends Model
{
    /** @use HasFactory<WorkScheduleDayFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'template_id',
        'day_of_week',
        'start_time',
        'end_time',
        'crosses_midnight',
        'break_start_time',
        'break_end_time',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'crosses_midnight' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkScheduleTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleTemplate::class, 'template_id');
    }
}
