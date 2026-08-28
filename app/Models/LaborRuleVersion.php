<?php

namespace App\Models;

use App\Exceptions\AmbiguousLaborRuleVersionException;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Database\Factories\LaborRuleVersionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A versioned, effective-dated set of parameters for a LaborRule. Never
 * edited once a calculation may have used it — a correction is always a
 * new version (HISTORIAL semantics, see .ai/05-DATABASE.md), which is why
 * this model has no soft-delete.
 */
class LaborRuleVersion extends Model
{
    /** @use HasFactory<LaborRuleVersionFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'labor_rule_id',
        'effective_from',
        'effective_to',
        'parameters',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<LaborRule, $this>
     */
    public function laborRule(): BelongsTo
    {
        return $this->belongsTo(LaborRule::class);
    }

    /**
     * Effective-dated lookup: the labor rule version in force for the
     * given labor rule on the given date, or null when none applies.
     *
     * @throws AmbiguousLaborRuleVersionException
     */
    public static function activeFor(string $laborRuleId, CarbonInterface $date): ?self
    {
        $candidates = static::query()
            ->where('labor_rule_id', $laborRuleId)
            ->where('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date->toDateString());
            })
            ->get();

        if ($candidates->count() > 1) {
            throw new AmbiguousLaborRuleVersionException($laborRuleId, $date);
        }

        return $candidates->first();
    }
}
