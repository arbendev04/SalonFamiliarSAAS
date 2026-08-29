<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Opt-in, additive counterpart to BelongsToCompany for catalog tables that
 * hold both platform-wide defaults (company_id = null) and per-company
 * overrides (company_id = <tenant>) in the same table.
 *
 * Why this trait exists instead of a plain query: BelongsToCompany's global
 * scope runs `where company_id = <current company>` on every query while a
 * tenant is active. That clause implicitly excludes company_id IS NULL rows
 * — SQL's `column = value` never matches NULL — so the platform-default rows
 * become completely unreachable through the model's default query builder,
 * not because of a missing `OR company_id IS NULL` somewhere, but because
 * the global scope runs before any explicit query can add one. Confirmed by
 * reading app/Models/Concerns/BelongsToCompany.php: the scope is registered
 * under the name 'company' via `addGlobalScope('company', ...)`. This is
 * also why LaborRule (Fase 7) never exercised its own "platform default"
 * half despite having a nullable company_id — same root cause.
 *
 * BelongsToCompany itself is intentionally NOT changed (out of scope: it is
 * shared by every DIRECTO/GLOBAL model in the app, its own blast radius).
 * This trait instead removes the 'company' global scope explicitly, on
 * purpose, only for the models that opt into it, and rebuilds the intended
 * "default OR override" condition by hand. Do not simplify this back to a
 * plain `Model::where(...)` call — that reintroduces the same exclusion bug
 * this trait was written to fix.
 */
trait HasPlatformOrCompanyDefault
{
    /**
     * All rows visible to the given company: platform defaults
     * (company_id IS NULL) plus that company's own overrides.
     */
    public function scopeEffectiveForCompany(Builder $query, ?string $companyId): Builder
    {
        return $query->withoutGlobalScope('company')
            ->where(function (Builder $q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            });
    }

    /**
     * The effective catalog for a company: one row per `code`, where a
     * company-specific override (company_id = $companyId) wins over a
     * platform default (company_id = null) sharing the same code. The merge
     * happens in PHP, not SQL, because there is no FK linking an override
     * row back to the default row it replaces.
     *
     * Only meaningful for models with a `code` column (e.g. LeaveType,
     * NoveltyType). Models without one (e.g. Holiday) should use
     * scopeEffectiveForCompany() directly instead of calling this.
     *
     * @return Collection<int, static>
     */
    public static function effectiveCatalog(?string $companyId): Collection
    {
        return static::query()->effectiveForCompany($companyId)->get()
            ->groupBy('code')
            ->map(fn (Collection $rows) => $rows->sortByDesc(fn ($row) => $row->company_id !== null)->first())
            ->values();
    }
}
