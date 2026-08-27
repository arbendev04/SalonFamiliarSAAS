<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PayrollInformationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sensitive banking/tax data for paying an employee, kept separate from
 * `employees` and encrypted at rest. See .ai/04-DOMAIN-MODEL.md and
 * .ai/20-SECURITY.md.
 */
class PayrollInformation extends Model
{
    /** @use HasFactory<PayrollInformationFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'payroll_information';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'bank_account_enc',
        'tax_regime',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bank_account_enc' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
