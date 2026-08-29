<?php

namespace App\Models;

use App\Exceptions\AmbiguousContractException;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\EmploymentContractFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The historical employment relationship between an employee and the
 * company. Never overwritten once created — a change in conditions always
 * closes the current contract (end_date) and opens a new one; a salary
 * raise within the same contract goes through SalaryHistory instead.
 * See .ai/04-DOMAIN-MODEL.md.
 */
class EmploymentContract extends Model
{
    /** @use HasFactory<EmploymentContractFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'position_id',
        'contract_type',
        'start_date',
        'end_date',
        'base_salary',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'base_salary' => 'decimal:2',
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
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * @return HasMany<SalaryHistory, $this>
     */
    public function salaryHistory(): HasMany
    {
        return $this->hasMany(SalaryHistory::class, 'contract_id');
    }

    /**
     * Effective-dated lookup: the contract in force for the given employee
     * on the given date. Returns null when none is in force (before hire,
     * after termination with no successor yet). Throws when more than one
     * contract is in force simultaneously — that is a data integrity bug,
     * never something to guess around (see .ai/04-DOMAIN-MODEL.md).
     *
     * @throws AmbiguousContractException
     */
    public static function activeForEmployeeAt(string $employeeId, CarbonInterface $date): ?self
    {
        $candidates = static::query()
            ->where('employee_id', $employeeId)
            ->where('start_date', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $date->toDateString());
            })
            ->get();

        if ($candidates->count() > 1) {
            throw new AmbiguousContractException($employeeId, $date);
        }

        return $candidates->first();
    }

    /**
     * @return HasMany<PayrollEntry, $this>
     */
    public function payrollEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class, 'contract_id');
    }

    /**
     * @return HasMany<PayrollEntryLine, $this>
     */
    public function payrollEntryLines(): HasMany
    {
        return $this->hasMany(PayrollEntryLine::class, 'contract_id');
    }
}
