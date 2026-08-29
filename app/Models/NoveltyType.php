<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasPlatformOrCompanyDefault;
use Database\Factories\NoveltyTypeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A type of novelty (e.g. justified absence, permit) available to a
 * company. A null company_id is a platform default catalog entry shared by
 * every company; see HasPlatformOrCompanyDefault for how defaults and
 * per-company overrides are resolved together.
 */
class NoveltyType extends Model
{
    /** @use HasFactory<NoveltyTypeFactory> */
    use BelongsToCompany, HasFactory, HasPlatformOrCompanyDefault, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'affects_time_calc',
        'affects_payroll',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'affects_time_calc' => 'boolean',
            'affects_payroll' => 'boolean',
        ];
    }
}
