<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\LaborRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named labor rule (e.g. standard workweek tolerance/rounding) whose
 * actual parameters live in versioned, effective-dated LaborRuleVersion
 * rows. A null company_id represents a platform-wide default rule instead
 * of a company-specific override. See .ai/05-DATABASE.md.
 */
class LaborRule extends Model
{
    /** @use HasFactory<LaborRuleFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'rule_type',
        'name',
    ];

    /**
     * @return HasMany<LaborRuleVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(LaborRuleVersion::class);
    }
}
