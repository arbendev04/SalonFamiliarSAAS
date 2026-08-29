<?php

namespace App\Models;

use App\Exceptions\AmbiguousSalaryHistoryException;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\SalaryHistoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A salary revision within a contract (raises), without closing and
 * reopening the contract itself. See .ai/04-DOMAIN-MODEL.md.
 */
class SalaryHistory extends Model
{
    /** @use HasFactory<SalaryHistoryFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    protected $table = 'salary_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'contract_id',
        'effective_from',
        'effective_to',
        'base_salary',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'base_salary' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<EmploymentContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class, 'contract_id');
    }

    /**
     * Effective-dated lookup: the salary revision in force for the given
     * contract on the given date. Returns null when none applies — this is
     * a valid, expected outcome (not every date needs a revision): the
     * caller must then fall back to the contract's own base_salary. This
     * method never resolves that fallback itself, it only reports whether a
     * specific revision is in force. Throws when more than one revision is
     * in force simultaneously — that is a data integrity bug, never
     * something to guess around (see .ai/04-DOMAIN-MODEL.md).
     *
     * @throws AmbiguousSalaryHistoryException
     */
    public static function activeAt(string $contractId, CarbonInterface $date): ?self
    {
        $candidates = static::query()
            ->where('contract_id', $contractId)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->get();

        if ($candidates->count() > 1) {
            throw new AmbiguousSalaryHistoryException($contractId, $date);
        }

        return $candidates->first();
    }
}
