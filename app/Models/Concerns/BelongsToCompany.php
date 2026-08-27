<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Services\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the active company for every query, and auto-fills
 * company_id when creating. This is the enforcement mechanism for
 * .ai/15-MULTI-TENANCY.md: tenant isolation happens here, not by
 * remembering to filter manually in every controller.
 *
 * Outside an HTTP request (e.g. console commands, seeders) with no
 * active company resolved, the scope does not filter — those are
 * trusted contexts, not user-facing requests.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $companyId = app(CurrentCompany::class)->id();

            if ($companyId !== null) {
                $builder->where($builder->getModel()->getTable().'.company_id', $companyId);
            }
        });

        static::creating(function ($model) {
            if (! $model->company_id) {
                $model->company_id = app(CurrentCompany::class)->id();
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
