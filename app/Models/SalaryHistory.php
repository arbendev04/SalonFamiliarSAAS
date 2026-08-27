<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
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
}
