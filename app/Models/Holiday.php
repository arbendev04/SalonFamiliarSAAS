<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasPlatformOrCompanyDefault;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single holiday date available to a company. A null company_id is a
 * platform default (e.g. the national Colombian holiday calendar) shared by
 * every company; see HasPlatformOrCompanyDefault::scopeEffectiveForCompany()
 * for how defaults and per-company overrides are resolved together.
 *
 * Unlike LeaveType/NoveltyType, Holiday has no `code` column to group by
 * (it has date + name instead), so it intentionally does not use
 * HasPlatformOrCompanyDefault::effectiveCatalog() — how a company holiday
 * landing on the same date as a platform default should be displayed or
 * merged is left to the holidays CRUD commit, not decided here.
 */
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use BelongsToCompany, HasFactory, HasPlatformOrCompanyDefault, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'date',
        'name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }
}
