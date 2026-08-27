<?php

namespace App\Models;

use App\Exceptions\AmbiguousScheduleException;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\EmployeeScheduleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The assignment of a WorkScheduleTemplate to an employee for a date range
 * — an effective-dated record (.ai/04-DOMAIN-MODEL.md): assigning a new
 * template closes the previous assignment instead of overwriting it.
 */
class EmployeeSchedule extends Model
{
    /** @use HasFactory<EmployeeScheduleFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'template_id',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
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
     * @return BelongsTo<WorkScheduleTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkScheduleTemplate::class, 'template_id');
    }

    /**
     * Effective-dated lookup: the schedule assignment in force for the
     * given employee on the given date, or null when none applies.
     *
     * @throws AmbiguousScheduleException
     */
    public static function activeForEmployeeAt(string $employeeId, CarbonInterface $date): ?self
    {
        $candidates = static::query()
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->get();

        if ($candidates->count() > 1) {
            throw new AmbiguousScheduleException($employeeId, $date);
        }

        return $candidates->first();
    }
}
