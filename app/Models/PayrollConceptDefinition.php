<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasPlatformOrCompanyDefault;
use Database\Factories\PayrollConceptDefinitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A payroll concept catalog entry (e.g. BASE_SALARY, OVERTIME, LOAN,
 * GARNISHMENT). A null company_id is a platform default shared by every
 * company, following the same DIRECTO/GLOBAL pattern as Holiday/NoveltyType
 * — see HasPlatformOrCompanyDefault. See .ai/10-PAYROLL.md.
 */
class PayrollConceptDefinition extends Model
{
    /** @use HasFactory<PayrollConceptDefinitionFactory> */
    use BelongsToCompany, HasFactory, HasPlatformOrCompanyDefault, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'calculation_method',
    ];
}
