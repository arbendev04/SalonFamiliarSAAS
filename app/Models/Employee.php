<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A worker within a company. Contract, salary, and payroll data live in
 * separate entities (see .ai/04-DOMAIN-MODEL.md) — this holds only basic
 * biographical/employment data.
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'branch_id',
        'full_name',
        'document_type',
        'national_id',
        'birth_date',
        'email',
        'phone',
        'address',
        'hire_date',
        'termination_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'hire_date' => 'date:Y-m-d',
            'termination_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
