<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown whenever code attempts to UPDATE or DELETE an audit_logs row,
 * whether through a model instance or the query builder. Per
 * .ai/16-AUDIT.md, audit_logs es inmutable: no se permite UPDATE ni DELETE
 * bajo ninguna circunstancia ni rol, ni siquiera SUPER_ADMIN.
 */
class AuditLogImmutableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'audit_logs es inmutable: no se permite UPDATE ni DELETE bajo ninguna circunstancia ni rol.'
        );
    }
}
