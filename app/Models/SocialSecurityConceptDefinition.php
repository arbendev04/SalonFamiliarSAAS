<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasPlatformOrCompanyDefault;
use Database\Factories\SocialSecurityConceptDefinitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A social-security contribution concept (e.g. the health or pension
 * contribution bucket), routed to whichever SocialSecurityAffiliation
 * `entity_type` it consumes. A null company_id is a platform default, same
 * pattern as Holiday/PayrollConceptDefinition — see
 * HasPlatformOrCompanyDefault::scopeEffectiveForCompany(). No platform
 * default rows are seeded this phase (see .ai/11-SOCIAL-SECURITY.md): every
 * row is company-created via CRUD.
 */
class SocialSecurityConceptDefinition extends Model
{
    /** @use HasFactory<SocialSecurityConceptDefinitionFactory> */
    use BelongsToCompany, HasFactory, HasPlatformOrCompanyDefault, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'entity_type',
    ];

    /**
     * @return HasMany<SocialSecurityContribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(SocialSecurityContribution::class, 'concept_id');
    }
}
