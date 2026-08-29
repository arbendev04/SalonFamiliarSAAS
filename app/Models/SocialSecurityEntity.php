<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasPlatformOrCompanyDefault;
use Database\Factories\SocialSecurityEntityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A social-security entity a company affiliates its employees with (e.g. a
 * health or pension provider). A null company_id is a platform default,
 * same pattern as Holiday/PayrollConceptDefinition — see
 * HasPlatformOrCompanyDefault::scopeEffectiveForCompany(). No platform
 * default rows are seeded this phase (see .ai/11-SOCIAL-SECURITY.md): every
 * row is company-created via CRUD.
 */
class SocialSecurityEntity extends Model
{
    /** @use HasFactory<SocialSecurityEntityFactory> */
    use BelongsToCompany, HasFactory, HasPlatformOrCompanyDefault, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'type',
        'name',
        'code',
    ];

    /**
     * @return HasMany<SocialSecurityAffiliation, $this>
     */
    public function affiliations(): HasMany
    {
        return $this->hasMany(SocialSecurityAffiliation::class, 'entity_id');
    }

    /**
     * @return HasMany<SocialSecurityContribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(SocialSecurityContribution::class, 'entity_id');
    }
}
