<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SocialSecurityAffiliationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The historical record of an employee's affiliation to a
 * SocialSecurityEntity for a given `entity_type` (e.g. health, pension).
 * Never overwritten once created — closing an affiliation means setting
 * end_date, never deleting the row (HISTORIAL semantics, same as
 * EmploymentContract), which is why this model has no soft-delete. The
 * effective-dated lookup analogous to
 * EmploymentContract::activeForEmployeeAt() is a later commit, not this one.
 */
class SocialSecurityAffiliation extends Model
{
    /** @use HasFactory<SocialSecurityAffiliationFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'employee_id',
        'entity_id',
        'entity_type',
        'affiliation_number',
        'start_date',
        'end_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
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
     * SocialSecurityEntity is a DIRECTO/GLOBAL model (nullable company_id,
     * platform-default rows possible via HasPlatformOrCompanyDefault).
     * Eager-loading this relation without `->withoutGlobalScope('company')`
     * silently drops any platform-default entity behind the active
     * tenant's BelongsToCompany scope. This exact bug has already bitten
     * three times in Fase 8-9 (see composed-knitting-dusk.md's "Modelos"
     * section) — do not eager-load this relation bare.
     *
     * @return BelongsTo<SocialSecurityEntity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(SocialSecurityEntity::class);
    }
}
