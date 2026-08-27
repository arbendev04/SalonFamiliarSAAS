<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Request;

/**
 * The transversal audit-recording layer required by .ai/16-AUDIT.md. Every
 * module that performs a sensitive action calls this instead of inserting
 * into audit_logs directly, so the shape of an audit entry stays uniform.
 *
 * Callers are responsible for wrapping the business action and this call in
 * the same DB transaction: if this insert fails, the whole transaction must
 * abort (ADR-018) — this class does not itself open a transaction.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValue
     * @param  array<string, mixed>|null  $newValue
     */
    public function record(
        User $user,
        string $action,
        string $entityType,
        string $entityId,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $reason = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'reason' => $reason,
            'ip_address' => Request::ip(),
        ]);
    }
}
