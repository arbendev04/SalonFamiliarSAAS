<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable trail of sensitive business actions (.ai/16-AUDIT.md). Rows are
 * only ever inserted through App\Services\Audit\AuditLogger — never updated
 * or deleted by any code path, not even for SUPER_ADMIN.
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use BelongsToCompany, HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_value',
        'new_value',
        'reason',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'array',
            'new_value' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
