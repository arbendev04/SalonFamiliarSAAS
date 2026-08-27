<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WorkScheduleTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The reusable day-of-week schedule rule for a company (e.g. "Mon-Fri
 * 06:00-14:00"), independent of any specific date. See .ai/08-SHIFTS.md.
 */
class WorkScheduleTemplate extends Model
{
    /** @use HasFactory<WorkScheduleTemplateFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
    ];

    /**
     * @return HasMany<WorkScheduleDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(WorkScheduleDay::class, 'template_id');
    }
}
