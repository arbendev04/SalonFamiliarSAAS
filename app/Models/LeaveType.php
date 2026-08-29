<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasPlatformOrCompanyDefault;
use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A type of leave (e.g. vacation, unpaid leave) available to a company. A
 * null company_id is a platform default catalog entry shared by every
 * company; see HasPlatformOrCompanyDefault for how defaults and per-company
 * overrides are resolved together.
 */
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
    use BelongsToCompany, HasFactory, HasPlatformOrCompanyDefault, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
    ];
}
