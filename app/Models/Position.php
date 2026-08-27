<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job title/role catalog entry, per company. See .ai/00-SYSTEM-SPECIFICATION.md.
 * Salary lives on the employment contract, not here (see .ai/04-DOMAIN-MODEL.md).
 */
class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use BelongsToCompany, HasFactory, HasUuids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'title',
        'department',
    ];
}
