<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical location of a company. See .ai/00-SYSTEM-SPECIFICATION.md.
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'timezone',
    ];

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
